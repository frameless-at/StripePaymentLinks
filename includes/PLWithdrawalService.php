<?php namespace ProcessWire;

use ProcessWire\Page;
use ProcessWire\Wire;

/**
 * PLWithdrawalService
 *
 * Implements the FAGG / EU 2023/2673 electronic withdrawal flow:
 *  - Step 1 (form):    user fills form on /withdrawal/
 *  - Step 2 (confirm): user reviews + confirms on /withdrawal/confirm/
 *  - Step 3 (submit):  if a matching user exists, an entry is appended to
 *                      the user's spl_withdrawals repeater; receipt + admin
 *                      mails are sent in either case.
 *
 * Dispatched by PLApiController for the two POST ops:
 *  - withdrawal_init   (form → confirm)
 *  - withdrawal_submit (confirm → repeater entry + mails)
 *
 * Frontend rendering happens through StripePaymentLinks::render($page),
 * which detects withdrawal pages and routes here.
 */
final class PLWithdrawalService extends Wire
{
	/** @var StripePaymentLinks */
	private StripePaymentLinks $mod;

	/** Session key for pending data between step 1 and step 2 */
	private const SESS_KEY    = 'spl_withdrawal_pending';
	private const SESS_TTL    = 1800;        // 30 minutes
	private const RATE_LIMIT  = 3;           // max submissions per window
	private const RATE_WINDOW = 3600;        // 1 hour
	private const HONEYPOT    = 'website';   // hidden field name

	public function __construct(StripePaymentLinks $mod)
	{
		$this->mod = $mod;
	}

	/* ------------------------------------------------------------------
	 * Public entry points (called from module + PLApiController)
	 * ----------------------------------------------------------------*/

	/**
	 * Render the appropriate withdrawal step for the current page.
	 * Dispatched by StripePaymentLinks::render() for withdrawal pages.
	 *
	 * @param Page $page Current page being rendered.
	 * @return string HTML markup.
	 */
	public function renderForPage(Page $page): string
	{
		$session = $this->mod->wire('session');
		try {
			// Step 3: success — set in session by handleSubmit()
			if ($session->get('spl_withdrawal_success')) {
				$session->remove('spl_withdrawal_success');
				return $this->renderSuccess();
			}

			if ($page->name === 'confirm') {
				return $this->renderConfirm();
			}
			return $this->renderForm();
		} catch (\Throwable $e) {
			$this->mod->wire('log')->save(StripePaymentLinks::LOG_PL,
				'[WITHDRAWAL] renderForPage error on ' . $page->path . ': ' . $e->getMessage()
				. ' @ ' . $e->getFile() . ':' . $e->getLine()
			);
			$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
			return '<div class="spl-withdrawal alert alert-danger">'
				 . $h($this->mod->t('withdrawal.error.generic')) . '</div>';
		}
	}

	/**
	 * Handle POST op=withdrawal_init.
	 * Validates form, stores data in session, returns redirect to confirm page.
	 *
	 * @param \ProcessWire\HookEvent $event
	 * @return void Sets $event->return to JSON response.
	 */
	public function handleInit($event): void
	{
		$mod     = $this->mod;
		$input   = $mod->wire('input');
		$session = $mod->wire('session');
		$pages   = $mod->wire('pages');
		$san     = $mod->wire('sanitizer');

		$json = function(array $arr, int $status = 200) {
			http_response_code($status);
			header('Content-Type: application/json; charset=utf-8');
			return json_encode($arr, JSON_UNESCAPED_UNICODE);
		};

		// Honeypot — silent reject
		if (trim((string) $input->post(self::HONEYPOT)) !== '') {
			$mod->wire('log')->save(StripePaymentLinks::LOG_SEC, '[WITHDRAWAL] honeypot triggered (init)');
			$event->return = $json(['ok' => true, 'redirect' => $this->withdrawalUrl()]);
			return;
		}

		$name      = trim((string) $san->text($input->post('spl_withdrawal_name')));
		$email     = trim((string) $san->email($input->post('spl_withdrawal_email')));
		$orderId   = trim((string) $san->text($input->post('spl_withdrawal_order_id')));
		$orderDate = trim((string) $san->text($input->post('spl_withdrawal_order_date')));
		$product   = trim((string) $san->text($input->post('spl_withdrawal_product')));
		$reason    = trim((string) $san->textarea($input->post('spl_withdrawal_reason')));

		if ($name === '' || $email === '' || $product === '') {
			$event->return = $json(['ok' => false, 'error' => $mod->t('withdrawal.error.required')]);
			return;
		}
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			$event->return = $json(['ok' => false, 'error' => $mod->t('withdrawal.error.email_invalid')]);
			return;
		}
		if ($orderId === '' && $orderDate === '') {
			$event->return = $json(['ok' => false, 'error' => $mod->t('withdrawal.error.either_or')]);
			return;
		}

		$session->set(self::SESS_KEY, [
			'name'       => $name,
			'email'      => $email,
			'order_id'   => $orderId,
			'order_date' => $orderDate,
			'product'    => $product,
			'reason'     => $reason,
			'created_at' => time(),
		]);

		$confirm = $pages->get('/withdrawal/confirm/');
		$redirect = ($confirm && $confirm->id) ? $confirm->httpUrl : $this->withdrawalUrl();

		$event->return = $json([
			'ok'       => true,
			'msg'      => $mod->t('withdrawal.api.init.ok'),
			'redirect' => $redirect,
		]);
	}

	/**
	 * Handle POST op=withdrawal_submit.
	 * Validates confirmation, creates repeater entry (if user exists), sends mails.
	 *
	 * @param \ProcessWire\HookEvent $event
	 * @return void
	 */
	public function handleSubmit($event): void
	{
		$mod     = $this->mod;
		$input   = $mod->wire('input');
		$session = $mod->wire('session');

		$json = function(array $arr, int $status = 200) {
			http_response_code($status);
			header('Content-Type: application/json; charset=utf-8');
			return json_encode($arr, JSON_UNESCAPED_UNICODE);
		};

		// Honeypot — silent reject
		if (trim((string) $input->post(self::HONEYPOT)) !== '') {
			$mod->wire('log')->save(StripePaymentLinks::LOG_SEC, '[WITHDRAWAL] honeypot triggered (submit)');
			$event->return = $json(['ok' => true, 'redirect' => $this->withdrawalUrl()]);
			return;
		}

		$pending = $session->get(self::SESS_KEY);
		if (!is_array($pending) || empty($pending['email'])) {
			$event->return = $json(['ok' => false, 'error' => $mod->t('withdrawal.error.session_expired')]);
			return;
		}
		if (time() - (int) ($pending['created_at'] ?? 0) > self::SESS_TTL) {
			$session->remove(self::SESS_KEY);
			$event->return = $json(['ok' => false, 'error' => $mod->t('withdrawal.error.session_expired')]);
			return;
		}

		// Confirmation checkbox
		if (!$input->post('spl_withdrawal_confirm')) {
			$event->return = $json(['ok' => false, 'error' => $mod->t('withdrawal.error.confirm_required')]);
			return;
		}

		// Rate limiting (per IP hash, last hour)
		$ipHash = $this->hashIp($session->getIP());
		if ($this->isRateLimited($ipHash)) {
			$event->return = $json(['ok' => false, 'error' => $mod->t('withdrawal.error.rate_limited')], 429);
			return;
		}

		// Try to attach to an existing user
		$entryItem  = null;
		$user       = $this->findUserByEmail($pending['email']);
		$userStatus = $user ? ('linked to user #' . $user->id) : 'no matching account';

		if ($user && $user->id && $user->hasField('spl_withdrawals')) {
			try {
				$entryItem = $this->appendRepeaterEntry($user, $pending, $ipHash);
			} catch (\Throwable $e) {
				$mod->wire('log')->save(StripePaymentLinks::LOG_PL,
					'[WITHDRAWAL] repeater append error: ' . $e->getMessage()
				);
			}
		}

		// Mails (best-effort, never block the flow)
		$sentReceipt = $mod->mail()->sendWithdrawalReceiptMail($mod, $pending);
		if ($sentReceipt && $entryItem && $entryItem->id) {
			$entryItem->of(false);
			$entryItem->spl_withdrawal_confirmation_sent    = 1;
			$entryItem->spl_withdrawal_confirmation_sent_at = time();
			try { $entryItem->save(); } catch (\Throwable $e) {}
		}
		$mod->mail()->sendWithdrawalAdminMail($mod, $pending, $userStatus);

		$this->bumpRateLimit($ipHash);
		$session->remove(self::SESS_KEY);
		$session->set('spl_withdrawal_success', 1);

		$mod->wire('log')->save(StripePaymentLinks::LOG_PL,
			'[WITHDRAWAL] new entry email=' . $pending['email'] . ' (' . $userStatus . ')'
		);

		$event->return = $json([
			'ok'       => true,
			'msg'      => $mod->t('withdrawal.api.submit.ok'),
			'redirect' => $this->withdrawalUrl(),
		]);
	}

	/* ------------------------------------------------------------------
	 * Step renderers
	 * ----------------------------------------------------------------*/

	/**
	 * Render step 1: the withdrawal form.
	 */
	private function renderForm(): string
	{
		$mod = $this->mod;
		$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

		$action  = $mod->apiUrl();
		$csrf    = $mod->wire('session')->CSRF->renderInput();
		$privacy = $this->renderPrivacyNotice();

		$out  = '<div class="spl-withdrawal spl-withdrawal-form">';
		$out .= '<h1 class="mb-3">' . $h($mod->t('withdrawal.form.title')) . '</h1>';
		$out .= '<p class="lead mb-4">' . $h($mod->t('withdrawal.form.intro')) . '</p>';

		$out .= '<form method="post" action="' . $h($action) . '" data-ajax="pw-json"'
			  . ' data-error-id="splWithdrawalError" data-success-id="splWithdrawalSuccess">';
		$out .= $csrf;
		$out .= '<input type="hidden" name="op" value="withdrawal_init">';

		// Honeypot
		$out .= '<div style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;" aria-hidden="true">'
			  . '<label>Website <input type="text" name="' . self::HONEYPOT . '" tabindex="-1" autocomplete="off"></label>'
			  . '</div>';

		$out .= $this->field('text',  'spl_withdrawal_name',     $mod->t('withdrawal.field.name'),  '', ['required' => true]);
		$out .= $this->field('email', 'spl_withdrawal_email',    $mod->t('withdrawal.field.email'), '', ['required' => true, 'help' => $mod->t('withdrawal.field.email_help')]);

		$out .= '<div class="row g-3">';
		$out .= '<div class="col-md-6">' . $this->field('text', 'spl_withdrawal_order_id',   $mod->t('withdrawal.field.order_id'),   '', []) . '</div>';
		$out .= '<div class="col-md-6">' . $this->field('date', 'spl_withdrawal_order_date', $mod->t('withdrawal.field.order_date'), '', []) . '</div>';
		$out .= '</div>';
		$out .= '<div class="form-text mb-3">' . $h($mod->t('withdrawal.field.either_or_help')) . '</div>';

		$out .= $this->field('text',     'spl_withdrawal_product', $mod->t('withdrawal.field.product'), '', ['required' => true]);
		$out .= $this->field('textarea', 'spl_withdrawal_reason',  $mod->t('withdrawal.field.reason'),  '', ['help' => $mod->t('withdrawal.field.reason_help')]);

		$out .= $privacy;

		$out .= '<div class="text-danger small my-2" id="splWithdrawalError" style="display:none;"></div>';
		$out .= '<div class="text-success small my-2" id="splWithdrawalSuccess" style="display:none;"></div>';

		$out .= '<button type="submit" class="btn btn-primary btn-lg">' . $h($mod->t('withdrawal.form.submit')) . '</button>';
		$out .= '</form>';
		$out .= '</div>';

		$out .= $this->ajaxScript();

		return $out;
	}

	/**
	 * Render step 2: confirmation review.
	 */
	private function renderConfirm(): string
	{
		$mod = $this->mod;
		$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
		$session = $mod->wire('session');
		$pending = $session->get(self::SESS_KEY);

		if (!is_array($pending) || empty($pending['email'])
			|| time() - (int) ($pending['created_at'] ?? 0) > self::SESS_TTL) {
			$session->remove(self::SESS_KEY);
			return '<div class="spl-withdrawal alert alert-warning">'
				 . $h($mod->t('withdrawal.error.session_expired')) . ' '
				 . '<a href="' . $h($this->withdrawalUrl()) . '">' . $h($mod->t('withdrawal.confirm.back')) . '</a>'
				 . '</div>';
		}

		$action = $mod->apiUrl();
		$csrf   = $mod->wire('session')->CSRF->renderInput();

		$rows = [
			'withdrawal.confirm.label_name'    => $pending['name']       ?? '',
			'withdrawal.confirm.label_email'   => $pending['email']      ?? '',
			'withdrawal.confirm.label_product' => $pending['product']    ?? '',
			'withdrawal.confirm.label_order'   => $pending['order_id']   ?? '',
			'withdrawal.confirm.label_date'    => $pending['order_date'] ?? '',
			'withdrawal.confirm.label_reason'  => $pending['reason']     ?? '',
		];

		$out  = '<div class="spl-withdrawal spl-withdrawal-confirm">';
		$out .= '<h1 class="mb-3">' . $h($mod->t('withdrawal.confirm.title')) . '</h1>';
		$out .= '<p class="lead mb-4">' . $h($mod->t('withdrawal.confirm.intro')) . '</p>';

		$out .= '<dl class="row mb-4">';
		foreach ($rows as $key => $value) {
			if (trim((string) $value) === '') continue;
			$out .= '<dt class="col-sm-3">' . $h($mod->t($key)) . '</dt>';
			$out .= '<dd class="col-sm-9">' . nl2br($h($value)) . '</dd>';
		}
		$out .= '</dl>';

		$out .= '<form method="post" action="' . $h($action) . '" data-ajax="pw-json"'
			  . ' data-error-id="splWithdrawalError" data-success-id="splWithdrawalSuccess">';
		$out .= $csrf;
		$out .= '<input type="hidden" name="op" value="withdrawal_submit">';
		$out .= '<div style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;" aria-hidden="true">'
			  . '<input type="text" name="' . self::HONEYPOT . '" tabindex="-1" autocomplete="off"></div>';

		$out .= '<div class="form-check mb-3">'
			  . '<input class="form-check-input" type="checkbox" name="spl_withdrawal_confirm" id="spl_withdrawal_confirm" value="1" required>'
			  . '<label class="form-check-label" for="spl_withdrawal_confirm">' . $h($mod->t('withdrawal.confirm.checkbox')) . '</label>'
			  . '</div>';

		$out .= '<div class="text-danger small my-2" id="splWithdrawalError" style="display:none;"></div>';
		$out .= '<div class="text-success small my-2" id="splWithdrawalSuccess" style="display:none;"></div>';

		$out .= '<a class="btn btn-secondary me-2" href="' . $h($this->withdrawalUrl()) . '">' . $h($mod->t('withdrawal.confirm.back')) . '</a>';
		$out .= '<button type="submit" class="btn btn-primary btn-lg">' . $h($mod->t('withdrawal.confirm.submit')) . '</button>';
		$out .= '</form>';
		$out .= '</div>';

		$out .= $this->ajaxScript();

		return $out;
	}

	/**
	 * Render step 3: success page.
	 */
	private function renderSuccess(): string
	{
		$mod = $this->mod;
		$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
		return '<div class="spl-withdrawal spl-withdrawal-success">'
			 . '<h1 class="mb-3">' . $h($mod->t('withdrawal.success.title')) . '</h1>'
			 . '<p class="lead">' . $h($mod->t('withdrawal.success.message')) . '</p>'
			 . '</div>';
	}

	/* ------------------------------------------------------------------
	 * Repeater entry creation
	 * ----------------------------------------------------------------*/

	/**
	 * Append a new entry to the user's spl_withdrawals repeater.
	 *
	 * @param \ProcessWire\User $user
	 * @param array $pending
	 * @param string $ipHash
	 * @return \ProcessWire\Page The new repeater item.
	 */
	private function appendRepeaterEntry(\ProcessWire\User $user, array $pending, string $ipHash): \ProcessWire\Page
	{
		$user->of(false);
		$item = $user->spl_withdrawals->getNew();
		$user->spl_withdrawals->add($item);
		$user->save('spl_withdrawals');

		$item->of(false);
		$item->spl_withdrawal_name        = $pending['name']       ?? '';
		$item->spl_withdrawal_email       = $pending['email']      ?? '';
		$item->spl_withdrawal_order_id    = $pending['order_id']   ?? '';
		if (!empty($pending['order_date'])) {
			$ts = strtotime((string) $pending['order_date']);
			if ($ts !== false) $item->spl_withdrawal_order_date = $ts;
		}
		$item->spl_withdrawal_product     = $pending['product']    ?? '';
		$item->spl_withdrawal_reason      = $pending['reason']     ?? '';
		$item->spl_withdrawal_received_at = time();
		$item->spl_withdrawal_ip_hash     = $ipHash;
		$item->spl_withdrawal_status      = 'received';

		$linkedId = $this->matchPurchase($user, $pending);
		if ($linkedId > 0) {
			$item->spl_withdrawal_linked_purchase_id = $linkedId;
		}
		$item->save();

		return $item;
	}

	/**
	 * Try to match the withdrawal against an existing spl_purchases entry
	 * by order_id (Stripe Session ID) or order_date.
	 */
	private function matchPurchase(\ProcessWire\User $user, array $pending): int
	{
		if (!$user->hasField('spl_purchases')) return 0;

		$orderId   = trim((string) ($pending['order_id'] ?? ''));
		$orderDate = trim((string) ($pending['order_date'] ?? ''));
		$orderTs   = $orderDate !== '' ? (int) strtotime($orderDate) : 0;

		foreach ($user->spl_purchases as $item) {
			if (!$item || !$item->id) continue;

			if ($orderId !== '') {
				$session = (array) $item->meta('stripe_session');
				$sid = (string) ($session['id'] ?? '');
				if ($sid !== '' && strcasecmp($sid, $orderId) === 0) {
					return (int) $item->id;
				}
			}
			if ($orderTs > 0) {
				$pTs = (int) ($item->purchase_date ?? 0);
				if ($pTs > 0 && abs($pTs - $orderTs) <= 86400) {
					return (int) $item->id;
				}
			}
		}
		return 0;
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ----------------------------------------------------------------*/

	private function findUserByEmail(string $email): ?\ProcessWire\User
	{
		$email = trim($email);
		if ($email === '') return null;
		$san = $this->mod->wire('sanitizer');
		$u = $this->mod->wire('users')->get('email=' . $san->email($email));
		return ($u && $u->id) ? $u : null;
	}

	private function withdrawalUrl(): string
	{
		$pages = $this->mod->wire('pages');
		$p = $pages->get('/withdrawal/');
		if ($p && $p->id) return $p->httpUrl;
		return $pages->get('/')->httpUrl;
	}

	/**
	 * HMAC-SHA-256 of the IP using $config->userAuthSalt as pepper.
	 */
	public function hashIp(string $ip): string
	{
		$pepper = (string) ($this->mod->wire('config')->userAuthSalt ?? '');
		return hash_hmac('sha256', $ip, $pepper !== '' ? $pepper : 'spl-withdrawal');
	}

	private function isRateLimited(string $ipHash): bool
	{
		$cache = $this->mod->wire('cache');
		$count = (int) $cache->get('spl_withdrawal_rl_' . $ipHash);
		return $count >= self::RATE_LIMIT;
	}

	private function bumpRateLimit(string $ipHash): void
	{
		$cache = $this->mod->wire('cache');
		$key   = 'spl_withdrawal_rl_' . $ipHash;
		$count = (int) $cache->get($key) + 1;
		$cache->saveFor('StripePaymentLinks', $key, $count, self::RATE_WINDOW);
	}

	private function renderPrivacyNotice(): string
	{
		$mod = $this->mod;
		$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
		$privacy = $mod->wire('pages')->get('/datenschutz/');
		$link = ($privacy && $privacy->id)
			? '<a href="' . $h($privacy->url) . '">' . $h($mod->t('withdrawal.privacy.link_label')) . '</a>'
			: $h($mod->t('withdrawal.privacy.link_label'));
		return '<p class="form-text my-3"><small>' . $h($mod->t('withdrawal.privacy.notice')) . ' ' . $link . '</small></p>';
	}

	private function field(string $type, string $name, string $label, string $value = '', array $opts = []): string
	{
		$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
		$id = $h($name);
		$req = !empty($opts['required']) ? ' required' : '';
		$reqMark = $req ? ' <span class="text-danger" aria-hidden="true">*</span>' : '';
		$help = isset($opts['help']) && $opts['help'] !== ''
			? '<div class="form-text">' . $h($opts['help']) . '</div>' : '';

		if ($type === 'textarea') {
			return '<div class="mb-3">'
				 . '<label class="form-label" for="' . $id . '">' . $h($label) . $reqMark . '</label>'
				 . '<textarea class="form-control" id="' . $id . '" name="' . $h($name) . '" rows="4"' . $req . '>' . $h($value) . '</textarea>'
				 . $help . '</div>';
		}
		$inputType = $type === 'date' ? 'date' : ($type === 'email' ? 'email' : 'text');
		return '<div class="mb-3">'
			 . '<label class="form-label" for="' . $id . '">' . $h($label) . $reqMark . '</label>'
			 . '<input type="' . $inputType . '" class="form-control" id="' . $id . '" name="' . $h($name) . '" value="' . $h($value) . '"' . $req . '>'
			 . $help . '</div>';
	}

	/**
	 * Inline AJAX submit handler for forms inside .spl-withdrawal.
	 */
	private function ajaxScript(): string
	{
		$errGeneric = json_encode($this->mod->t('withdrawal.error.generic'), JSON_UNESCAPED_UNICODE);
		$errServer  = json_encode($this->mod->t('ui.ajax.error_server'),     JSON_UNESCAPED_UNICODE);
		return <<<HTML
<script>
(function(){
  document.addEventListener('submit', async function(ev){
	var form = ev.target.closest('.spl-withdrawal form[data-ajax="pw-json"]');
	if (!form) return;
	ev.preventDefault();
	var errorBox   = document.getElementById(form.getAttribute('data-error-id'));
	var successBox = document.getElementById(form.getAttribute('data-success-id'));
	var show = function(el, msg){ if(el){ el.textContent = msg||''; el.style.display = msg ? 'block':'none'; } };
	show(errorBox,''); show(successBox,'');
	var fd = new FormData(form);
	try {
	  var res  = await fetch(form.action, { method:'POST', body:fd, credentials:'same-origin' });
	  var json = await res.json();
	  if (!json.ok) { show(errorBox, json.error || {$errGeneric}); return; }
	  if (json.msg) show(successBox, json.msg);
	  if (json.redirect) { window.location.href = json.redirect; return; }
	} catch(e) {
	  show(errorBox, {$errServer});
	}
  });
})();
</script>
HTML;
	}
}
