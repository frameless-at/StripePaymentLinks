<?php namespace ProcessWire;

use ProcessWire\Wire;

/**
 * PLWithdrawalService
 *
 * FAGG / EU 2023/2673 electronic withdrawal flow as Bootstrap modals:
 *  - withdrawalFormModal       — step 1: form fields
 *  - withdrawalConfirmModal    — step 2: review + explicit confirm checkbox
 *  - withdrawalSuccessModal    — step 3: receipt confirmation
 *
 * Trigger from anywhere (e.g. footer link):
 *   <a href="#" data-bs-toggle="modal" data-bs-target="#withdrawalFormModal">
 *     Vertrag widerrufen
 *   </a>
 *
 * Submission goes to the existing /stripepaymentlinks/api endpoint with
 * op=withdrawal_init (form → confirm) and op=withdrawal_submit
 * (confirm → repeater entry + mails). The AJAX response carries an
 * `open_modal` field so the frontend transitions modals.
 */
final class PLWithdrawalService extends Wire
{
	/** @var StripePaymentLinks */
	private StripePaymentLinks $mod;

	private const SESS_KEY    = 'spl_withdrawal_pending';
	private const SESS_TTL    = 1800;       // 30 minutes
	private const RATE_LIMIT  = 3;          // max submissions per window
	private const RATE_WINDOW = 3600;       // 1 hour
	private const HONEYPOT    = 'website';  // hidden field name

	public function __construct(StripePaymentLinks $mod)
	{
		$this->mod = $mod;
	}

	/* ------------------------------------------------------------------
	 * Modal renderers (all return HTML; called from StripePaymentLinks::render)
	 * ----------------------------------------------------------------*/

	public function modalForm(): string
	{
		$mod = $this->mod;
		$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
		$action = $mod->apiUrl();
		$csrf   = $mod->wire('session')->CSRF->renderInput();

		$privacy = $this->renderPrivacyNotice();

		$body  = '<p class="mb-3">' . $h($mod->t('withdrawal.form.intro')) . '</p>';
		$body .= '<form method="post" action="' . $h($action) . '" data-ajax="pw-json"'
			   . ' data-error-id="withdrawalFormError" data-success-id="withdrawalFormSuccess">';
		$body .= $csrf;
		$body .= '<input type="hidden" name="op" value="withdrawal_init">';
		// Honeypot
		$body .= '<div style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;" aria-hidden="true">'
			   . '<label>Website <input type="text" name="' . self::HONEYPOT . '" tabindex="-1" autocomplete="off"></label>'
			   . '</div>';

		$body .= $this->field('text',  'spl_withdrawal_name',  $mod->t('withdrawal.field.name'),  '', ['required' => true, 'id' => 'splWdName']);
		$body .= $this->field('email', 'spl_withdrawal_email', $mod->t('withdrawal.field.email'), '', ['required' => true, 'help' => $mod->t('withdrawal.field.email_help'), 'id' => 'splWdEmail']);

		$body .= '<div class="row g-3">'
			   . '<div class="col-md-6">' . $this->field('text', 'spl_withdrawal_order_id',   $mod->t('withdrawal.field.order_id'),   '', ['id' => 'splWdOrderId']) . '</div>'
			   . '<div class="col-md-6">' . $this->field('date', 'spl_withdrawal_order_date', $mod->t('withdrawal.field.order_date'), '', ['id' => 'splWdOrderDate']) . '</div>'
			   . '</div>'
			   . '<div class="form-text mb-3">' . $h($mod->t('withdrawal.field.either_or_help')) . '</div>';

		$body .= $this->field('text',     'spl_withdrawal_product', $mod->t('withdrawal.field.product'), '', ['required' => true, 'id' => 'splWdProduct']);
		$body .= $this->field('textarea', 'spl_withdrawal_reason',  $mod->t('withdrawal.field.reason'),  '', ['help' => $mod->t('withdrawal.field.reason_help'), 'id' => 'splWdReason']);

		$body .= $privacy;

		$body .= '<div class="text-danger small my-2" id="withdrawalFormError" style="display:none;"></div>';
		$body .= '<div class="text-success small my-2" id="withdrawalFormSuccess" style="display:none;"></div>';

		$body .= '<div class="d-flex justify-content-end gap-2 mt-3">';
		$body .= '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">' . $h($mod->t('modal.notice.cancel')) . '</button>';
		$body .= '<button type="submit" class="btn btn-primary">' . $h($mod->t('withdrawal.form.submit')) . '</button>';
		$body .= '</div>';
		$body .= '</form>';

		return $this->modalShell('withdrawalFormModal', $h($mod->t('withdrawal.form.title')), $body);
	}

	public function modalConfirm(): string
	{
		$mod = $this->mod;
		$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
		$action = $mod->apiUrl();
		$csrf   = $mod->wire('session')->CSRF->renderInput();

		$body  = '<p class="mb-3">' . $h($mod->t('withdrawal.confirm.intro')) . '</p>';
		// Will be filled by the AJAX response from withdrawal_init
		$body .= '<dl class="row mb-3" id="withdrawalConfirmData"></dl>';

		$body .= '<form method="post" action="' . $h($action) . '" data-ajax="pw-json"'
			   . ' data-error-id="withdrawalConfirmError" data-success-id="withdrawalConfirmSuccess">';
		$body .= $csrf;
		$body .= '<input type="hidden" name="op" value="withdrawal_submit">';
		$body .= '<div style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;" aria-hidden="true">'
			   . '<input type="text" name="' . self::HONEYPOT . '" tabindex="-1" autocomplete="off"></div>';

		$body .= '<div class="form-check mb-3">'
			   . '<input class="form-check-input" type="checkbox" name="spl_withdrawal_confirm" id="splWdConfirmCheck" value="1" required>'
			   . '<label class="form-check-label" for="splWdConfirmCheck">' . $h($mod->t('withdrawal.confirm.checkbox')) . '</label>'
			   . '</div>';

		$body .= '<div class="text-danger small my-2" id="withdrawalConfirmError" style="display:none;"></div>';
		$body .= '<div class="text-success small my-2" id="withdrawalConfirmSuccess" style="display:none;"></div>';

		$body .= '<div class="d-flex justify-content-end gap-2 mt-3">';
		$body .= '<button type="button" class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#withdrawalFormModal">' . $h($mod->t('withdrawal.confirm.back')) . '</button>';
		$body .= '<button type="submit" class="btn btn-primary">' . $h($mod->t('withdrawal.confirm.submit')) . '</button>';
		$body .= '</div>';
		$body .= '</form>';

		return $this->modalShell('withdrawalConfirmModal', $h($mod->t('withdrawal.confirm.title')), $body);
	}

	public function modalSuccess(): string
	{
		$mod = $this->mod;
		$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

		$body  = '<p class="mb-3">' . $h($mod->t('withdrawal.success.message')) . '</p>';
		$body .= '<div class="d-flex justify-content-end mt-3">';
		$body .= '<button type="button" class="btn btn-primary" data-bs-dismiss="modal">' . $h($mod->t('withdrawal.success.close')) . '</button>';
		$body .= '</div>';

		return $this->modalShell('withdrawalSuccessModal', $h($mod->t('withdrawal.success.title')), $body);
	}

	/**
	 * Render a Bootstrap modal shell (header + body) consistent with
	 * the existing PLModalService output.
	 */
	private function modalShell(string $id, string $title, string $body): string
	{
		return '<div class="modal fade" id="' . $id . '" tabindex="-1" aria-hidden="true" role="dialog" aria-labelledby="' . $id . '-title">'
			 . '<div class="modal-dialog modal-dialog-centered modal-lg">'
			 . '<div class="modal-content">'
			 . '<div class="modal-header bg-primary">'
			 . '<h5 id="' . $id . '-title" class="modal-title text-white">' . $title . '</h5>'
			 . '<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>'
			 . '</div>'
			 . '<div class="modal-body p-3">' . $body . '</div>'
			 . '</div></div></div>';
	}

	/* ------------------------------------------------------------------
	 * API handlers (called from PLApiController dispatch)
	 * ----------------------------------------------------------------*/

	/**
	 * op=withdrawal_init: validate form, store pending in session,
	 * return JSON containing the rendered review HTML for the confirm modal.
	 *
	 * @param \ProcessWire\HookEvent $event
	 */
	public function handleInit($event): void
	{
		$mod     = $this->mod;
		$input   = $mod->wire('input');
		$session = $mod->wire('session');
		$san     = $mod->wire('sanitizer');

		$json = function(array $arr, int $status = 200) {
			http_response_code($status);
			header('Content-Type: application/json; charset=utf-8');
			return json_encode($arr, JSON_UNESCAPED_UNICODE);
		};

		// Honeypot — silent reject
		if (trim((string) $input->post(self::HONEYPOT)) !== '') {
			$mod->wire('log')->save(StripePaymentLinks::LOG_SEC, '[WITHDRAWAL] honeypot triggered (init)');
			$event->return = $json(['ok' => true]);
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

		$pending = [
			'name'       => $name,
			'email'      => $email,
			'order_id'   => $orderId,
			'order_date' => $orderDate,
			'product'    => $product,
			'reason'     => $reason,
			'created_at' => time(),
		];
		$session->set(self::SESS_KEY, $pending);

		$event->return = $json([
			'ok'         => true,
			'msg'        => $mod->t('withdrawal.api.init.ok'),
			'open_modal' => 'withdrawalConfirmModal',
			'html_for'   => '#withdrawalConfirmData',
			'html'       => $this->renderConfirmDataDl($pending),
		]);
	}

	/**
	 * op=withdrawal_submit: validate confirm checkbox, append repeater entry
	 * (if user matches), send mails, return success.
	 *
	 * @param \ProcessWire\HookEvent $event
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
			$event->return = $json(['ok' => true]);
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

		if (!$input->post('spl_withdrawal_confirm')) {
			$event->return = $json(['ok' => false, 'error' => $mod->t('withdrawal.error.confirm_required')]);
			return;
		}

		$ipHash = $this->hashIp($session->getIP());
		if ($this->isRateLimited($ipHash)) {
			$event->return = $json(['ok' => false, 'error' => $mod->t('withdrawal.error.rate_limited')], 429);
			return;
		}

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

		$mod->wire('log')->save(StripePaymentLinks::LOG_PL,
			'[WITHDRAWAL] new entry email=' . $pending['email'] . ' (' . $userStatus . ')'
		);

		$event->return = $json([
			'ok'         => true,
			'msg'        => $mod->t('withdrawal.api.submit.ok'),
			'open_modal' => 'withdrawalSuccessModal',
		]);
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ----------------------------------------------------------------*/

	/**
	 * Render the <dl> rows for the confirm modal data panel.
	 */
	private function renderConfirmDataDl(array $pending): string
	{
		$mod = $this->mod;
		$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
		$rows = [
			'withdrawal.confirm.label_name'    => $pending['name']       ?? '',
			'withdrawal.confirm.label_email'   => $pending['email']      ?? '',
			'withdrawal.confirm.label_product' => $pending['product']    ?? '',
			'withdrawal.confirm.label_order'   => $pending['order_id']   ?? '',
			'withdrawal.confirm.label_date'    => $pending['order_date'] ?? '',
			'withdrawal.confirm.label_reason'  => $pending['reason']     ?? '',
		];
		$out = '';
		foreach ($rows as $key => $value) {
			if (trim((string) $value) === '') continue;
			$out .= '<dt class="col-sm-4">' . $h($mod->t($key)) . '</dt>';
			$out .= '<dd class="col-sm-8">' . nl2br($h($value)) . '</dd>';
		}
		return $out;
	}

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

	private function findUserByEmail(string $email): ?\ProcessWire\User
	{
		$email = trim($email);
		if ($email === '') return null;
		$san = $this->mod->wire('sanitizer');
		$u = $this->mod->wire('users')->get('email=' . $san->email($email));
		return ($u && $u->id) ? $u : null;
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
		$id = isset($opts['id']) ? $opts['id'] : $name;
		$req = !empty($opts['required']) ? ' required' : '';
		$reqMark = $req ? ' <span class="text-danger" aria-hidden="true">*</span>' : '';
		$help = isset($opts['help']) && $opts['help'] !== ''
			? '<div class="form-text">' . $h($opts['help']) . '</div>' : '';

		if ($type === 'textarea') {
			return '<div class="mb-3">'
				 . '<label class="form-label" for="' . $h($id) . '">' . $h($label) . $reqMark . '</label>'
				 . '<textarea class="form-control" id="' . $h($id) . '" name="' . $h($name) . '" rows="4"' . $req . '>' . $h($value) . '</textarea>'
				 . $help . '</div>';
		}
		$inputType = $type === 'date' ? 'date' : ($type === 'email' ? 'email' : 'text');
		return '<div class="mb-3">'
			 . '<label class="form-label" for="' . $h($id) . '">' . $h($label) . $reqMark . '</label>'
			 . '<input type="' . $inputType . '" class="form-control" id="' . $h($id) . '" name="' . $h($name) . '" value="' . $h($value) . '"' . $req . '>'
			 . $help . '</div>';
	}
}
