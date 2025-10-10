<?php namespace ProcessWire;

/**
 * PLModalService
 *
 * Renders Bootstrap modals and small UI helpers for StripePaymentLinks.
 * - Pulls ALL texts via $mod->t('<key>') from StripePaymentLinks::defaultTexts()
 * - No hard-coded UI strings in this file
 * - Uses includes/ui/ModalRenderer.php + includes/views/modal.php
 */
final class PLModalService
{
	/** @var StripePaymentLinks */
	private StripePaymentLinks $mod;

	/** @var ModalRenderer */
	private ModalRenderer $ui;

	/**
	 * Constructor to initialize the modal service.
	 *
	 * @param StripePaymentLinks $mod Reference to the main StripePaymentLinks module.
	 */
	public function __construct(StripePaymentLinks $mod)
	{
		$this->mod = $mod;

		// UI renderer (view-based)
		require_once __DIR__ . '/ui/ModalRenderer.php';
		$this->ui = new ModalRenderer(
			__DIR__ . '/views/modal.php',
			function () { return $this->mod->wire('session')->CSRF->renderInput(); }
		);
	}

	/**
	 * Helper method: renders modal via ModalRenderer.
	 *
	 * @param array $m Modal configuration array.
	 * @return string Rendered HTML of the modal.
	 */
	private function renderModal(array $m): string
	{
		return $this->ui->render($m);
	}

	/* ---------------------------------------------------------------------
	 * One-off notice queueing (stored in session; opened on next render())
	 * -------------------------------------------------------------------*/

	/**
	 * Queue "login required" notice that opens login modal on sales page.
	 */
	public function queueLoginRequiredModal(): void
	{
		$s = $this->mod->wire('session');

		$titleEsc = htmlspecialchars($this->mod->t('modal.loginrequired.title'), ENT_QUOTES, 'UTF-8');
		$bodyHtml = $this->fillPlaceholders($this->mod->t('modal.loginrequired.body'), null);

		$btnClose = htmlspecialchars($this->mod->t('modal.notice.close'), ENT_QUOTES, 'UTF-8');
		$btnOpen  = htmlspecialchars($this->mod->t('modal.login.open'), ENT_QUOTES, 'UTF-8');

		$s->set('modal_notice', [
			'id'     => 'loginRequired',
			'title'  => $titleEsc,
			'body'   => $bodyHtml, // trusted HTML from fillPlaceholders()
			'footer' =>
				'<button class="btn btn-secondary" data-bs-dismiss="modal" type="button">'.$btnClose.'</button>'.
				'<button class="btn btn-primary" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#loginModal" type="button">'.$btnOpen.'</button>',
		]);
	}

	/**
	 * Queue "already purchased" notice modal.
	 */
	public function queueAlreadyPurchasedModal(): void
	{
		$s = $this->mod->wire('session');

		$titleEsc = htmlspecialchars($this->mod->t('modal.already_purchased.title'), ENT_QUOTES, 'UTF-8');
		$bodyHtml = $this->fillPlaceholders($this->mod->t('modal.already_purchased.body'), null);

		$btnClose = htmlspecialchars($this->mod->t('modal.notice.close'), ENT_QUOTES, 'UTF-8');

		$s->set('modal_notice', [
			'id'     => 'alreadyPurchasedModal',
			'title'  => $titleEsc,
			'body'   => $bodyHtml,
			'footer' => '<button class="btn btn-primary" data-bs-dismiss="modal" type="button">'.$btnClose.'</button>',
		]);
	}

	/**
	 * Render notice if reset token is expired and auto-open reset request modal.
	 *
	 * @param array $opts Optional configurations for placeholders.
	 */
	public function queueResetTokenExpiredModal(array $opts = []): void
	{
		$s = $this->mod->wire('session');

		$titleEsc = htmlspecialchars($this->mod->t('modal.resetexpired.title'), ENT_QUOTES, 'UTF-8');
		$bodyHtml = $this->fillPlaceholders($this->mod->t('modal.resetexpired.body'), null);

		$btnClose   = htmlspecialchars($this->mod->t('modal.notice.close'), ENT_QUOTES, 'UTF-8');
		$btnRequest = htmlspecialchars($this->mod->t('modal.resetexpired.request'), ENT_QUOTES, 'UTF-8');

		$s->set('modal_notice', [
			'id'     => 'resetExpiredNotice',
			'title'  => $titleEsc,
			'body'   => $bodyHtml, // trusted HTML
			'footer' =>
				'<button class="btn btn-secondary" data-bs-dismiss="modal" type="button">'.$btnClose.'</button>'.
				'<button class="btn btn-primary" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#resetRequestModal" type="button">'.$btnRequest.'</button>',
		]);
	}

	/**
	 * Queue "access link expired" notice that leads to login modal.
	 */
	public function queueExpiredAccessModal(): void
	{
		$s = $this->mod->wire('session');

		$titleEsc = htmlspecialchars($this->mod->t('modal.expiredaccess.title'), ENT_QUOTES, 'UTF-8');
		$bodyHtml = $this->fillPlaceholders($this->mod->t('modal.expiredaccess.body'), null);

		$btnClose = htmlspecialchars($this->mod->t('modal.notice.close'), ENT_QUOTES, 'UTF-8');
		$btnOpen  = htmlspecialchars($this->mod->t('modal.login.open'), ENT_QUOTES, 'UTF-8');

		$s->set('modal_notice', [
			'id'     => 'expiredAccessModal',
			'title'  => $titleEsc,
			'body'   => $bodyHtml,
			'footer' =>
				'<button class="btn btn-secondary" data-bs-dismiss="modal" type="button">'.$btnClose.'</button>'.
				'<button class="btn btn-primary" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#loginModal" type="button">'.$btnOpen.'</button>',
		]);
	}

	/**
	 * Render queued notice modal (if any) and auto-open it.
	 *
	 * @return string Rendered modal HTML + auto-open script.
	 */
	public function renderModalNotice(): string
	{
		$session = $this->mod->wire('session');
		$notice  = $session->get('modal_notice');
		if (!$notice || !is_array($notice)) return '';

		$session->remove('modal_notice');

		$id     = $notice['id']     ?? ('notice-' . uniqid());
		$title  = $notice['title']  ?? htmlspecialchars($this->mod->t('modal.notice.title'), ENT_QUOTES, 'UTF-8');
		$body   = $notice['body']   ?? '';
		$footer = $notice['footer'] ?? '<button class="btn btn-primary" data-bs-dismiss="modal" type="button">' . htmlspecialchars($this->mod->t('modal.notice.ok'), ENT_QUOTES, 'UTF-8') . '</button>';
		$nextId = $notice['openAfterClose'] ?? '';

		$html = $this->renderModal([
			'id'     => $id,
			'title'  => $title,
			'body'   => $body,
			'footer' => $footer,
		]);

		// Auto-open script and optional “open next modal after close”
		$html .= '<script>
		  (function(){
			var id = "'. addslashes($id) .'";
			var nextId = "'. addslashes($nextId) .'";
			function openWhenReady(){
			  if (!window.bootstrap) { return setTimeout(openWhenReady, 50); }
			  var el = document.getElementById(id);
			  if (!el) return;
			  var m = bootstrap.Modal.getOrCreateInstance(el);
			  el.addEventListener("hidden.bs.modal", function(){
				if(nextId){
				  var next = document.getElementById(nextId);
				  if(next) bootstrap.Modal.getOrCreateInstance(next).show();
				}
			  });
			  m.show();
			}
			if (document.readyState === "loading"){
			  document.addEventListener("DOMContentLoaded", openWhenReady);
			} else {
			  openWhenReady();
			}
		  })();
		</script>';

		return $html;
	}

	/* ---------------------------------------------------------------------
	 * Access block after checkout (buttons + optional auto redirect)
	 * -------------------------------------------------------------------*/

	/**
	 * Render the access buttons block (excluding modals).
	 *
	 * @param array $links Array of ['title'=>string, 'url'=>string, 'id'=>int].
	 * @return string HTML markup with deduplicated buttons.
	 */
	public function renderAccessButtonsBlock(array $links): string
	{
		if (!$links) return '';

		// Deduplicate by product ID
		$uniq = [];
		foreach ($links as $l) {
			$id = (int)($l['id'] ?? 0);
			if ($id && isset($uniq[$id])) continue;
			$uniq[$id] = $l;
		}
		$links = array_values($uniq);

		$esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
		$prodFallback = $this->mod->t('mail.common.product_fallback');

		if (count($links) === 1) {
			$l = $links[0];
			$title = $esc($l['title'] ?? $prodFallback);
			$url = $esc($l['url'] ?? '#');
			$hint = $esc($this->mod->t('ui.access.single_hint'));
			return '<div class="pl-access-block text-center my-5"><p>' . $hint . '</p>'
				. '<p><a class="btn btn-primary btn-lg" href="' . $url . '">' . $title . '</a></p></div>';
		}

		$hint = $esc($this->mod->t('ui.access.multi_hint'));
		$btns = [];
		foreach ($links as $l) {
			$title = $esc($l['title'] ?? $prodFallback);
			$url = $esc($l['url'] ?? '#');
			$btns[] = '<a class="btn btn-primary btn-lg mb-2" target="_blank" href="' . $url . '">' . $title . '</a>';
		}

		return '<div class="pl-access-block text-center my-5"><p>' . $hint . '</p><p>'
			. implode(' ', $btns)
			. '</p></div>';
	}

	/* ---------------------------------------------------------------------
	 * Modals (login / reset request / reset set / set password)
	 * -------------------------------------------------------------------*/

	/**
	 * Render login modal form.
	 *
	 * @param array $opts Options including 'action' and 'redirect_url'.
	 * @return string Rendered modal HTML.
	 */
	public function modalLogin(array $opts): string
	{
		$pages   = $this->mod->wire('pages');
		$session = $this->mod->wire('session');

		$intended = (string) ($session->get('pl_intended_url') ?: ($opts['redirect_url'] ?? $pages->get('/')->httpUrl));
		$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

		$title  = $this->mod->t('modal.login.title');
		$intro  = $this->mod->t('modal.login.body');
		$btn    = $this->mod->t('modal.login.submit');
		$cancel = $this->mod->t('modal.notice.cancel');
		$forgot = $this->mod->t('modal.login.forgot_link');

		$modal = [
			'id'    => 'loginModal',
			'title' => $h($title),
			'form'  => [
				'action'     => (string)($opts['action'] ?? $this->mod->apiUrl()),
				'op'         => 'login',
				'hidden'     => ['redirect_url' => $intended],
				'fields'     => [
					['type'=>'email','name'=>'email','label'=>$this->mod->t('modal.common.label_email'),'attrs'=>['required'=>true,'autocomplete'=>'username']],
					['type'=>'password','name'=>'password','label'=>$this->mod->t('modal.common.label_password'),'attrs'=>['required'=>true,'autocomplete'=>'current-password']],
				],
				'afterFieldsHtml' => '<a href="#" data-bs-toggle="modal" data-bs-target="#resetRequestModal" data-bs-dismiss="modal">'.$h($forgot).'</a>',
				'submitText' => $btn,
				'cancelText' => $cancel,
			],
		];

		if (trim($intro) !== '') {
			$modal['form']['bodyIntro'] = $this->fillPlaceholders($intro, null);
		}

		return $this->renderModal($modal);
	}

	/**
	 * Render password reset request modal form.
	 *
	 * @param array $opts Options including 'action' and 'return_url'.
	 * @return string Rendered modal HTML.
	 */
	public function modalResetRequest(array $opts): string
	{
		$title  = $this->mod->t('modal.resetreq.title');
		$body   = $this->mod->t('modal.resetreq.body');
		$btn    = $this->mod->t('modal.resetreq.submit');
		$cancel = $this->mod->t('modal.notice.cancel');

		return $this->renderModal([
			'id'    => 'resetRequestModal',
			'title' => htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
			'form'  => [
				'action'     => (string) $opts['action'],
				'op'         => 'reset_request',
				'fields'     => [
					['type'=>'email','name'=>'email','label'=>$this->mod->t('modal.common.label_email'),'attrs'=>['required'=>true,'autocomplete'=>'username']],
					['type'=>'hidden','name'=>'return_url','value'=> (string)($opts['return_url'] ?? '')],
				],
				'cancelText' => $cancel,
				'submitText' => $btn,
				'bodyIntro'  => $this->fillPlaceholders($body, null),
			],
		]);
	}

	/**
	 * Render password reset form modal, opened with reset token.
	 *
	 * @param array $opts Options including 'action', 'username', 'token'.
	 * @return string Rendered modal HTML.
	 */
	public function modalResetSet(array $opts): string
	{
		$title  = $this->mod->t('modal.resetset.title');
		$body   = $this->mod->t('modal.resetset.body');
		$btn    = $this->mod->t('modal.resetset.submit');
		$cancel = $this->mod->t('modal.notice.cancel');

		return $this->renderModal([
			'id'    => 'resetSetModal',
			'title' => htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
			'form'  => [
				'action'     => (string)($opts['action'] ?? $this->mod->apiUrl()),
				'op'         => 'reset_password',
				'fields'     => [
					['type'=>'hidden','name'=>'token','value'=> '', 'attrs'=>['id'=>'resetTokenField']],
					['type'=>'hidden','name'=>'username','value'=> (string)($opts['username'] ?? ''), 'attrs'=>['autocomplete'=>'username']],
					['type'=>'password','name'=>'password','label'=>$this->mod->t('modal.common.label_password'),'attrs'=>['required'=>true,'autocomplete'=>'new-password']],
					['type'=>'password','name'=>'password_confirm','label'=>$this->mod->t('modal.common.label_password_confirm'),'attrs'=>['required'=>true,'autocomplete'=>'new-password']],
				],
				'cancelText' => $cancel,
				'submitText' => $btn,
				'bodyIntro'  => $this->fillPlaceholders($body, null),
			],
		]);
	}

	/**
	 * Render "set new password" modal immediately after purchase.
	 *
	 * Requires a logged-in user.
	 *
	 * @param array $opts Options including 'user', 'action'.
	 * @return string Rendered modal HTML.
	 */
	public function modalSetPassword(array $opts): string
	{
		/** @var \ProcessWire\User|null $u */
		$u = $opts['user'] ?? null;

		$h      = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
		$title  = $this->mod->t('modal.setpwd.title');
		$intro  = $this->mod->t('modal.setpwd.intro');
		$btn    = $this->mod->t('modal.setpwd.btn_submit');
		$cancel = $this->mod->t('modal.notice.cancel');

		$modal = [
			'id'    => 'setPassModal',
			'title' => $h($title),
			'form'  => [
				'action'     => (string)($opts['action'] ?? $this->mod->apiUrl()),
				'op'         => 'set_password',
				'submitText' => $btn,
				'cancelText' => $cancel,
				'fields'     => [
					['type'=>'hidden','name'=>'username','value'=> ($u ? (string)$u->email : ''), 'attrs'=>['autocomplete'=>'username']],
					['type'=>'password','name'=>'password','label'=>$this->mod->t('modal.common.label_password'),'attrs'=>['required'=>true,'autocomplete'=>'new-password']],
					['type'=>'password','name'=>'password_confirm','label'=>$this->mod->t('modal.common.label_password_confirm'),'attrs'=>['required'=>true,'autocomplete'=>'new-password']],
				],
				'footerClass'=> 'modal-footer bg-light-subtle',
			],
		];

		if (trim($intro) !== '') {
			$modal['form']['bodyIntro'] = $this->fillPlaceholders($intro, $u);
		}

		return $this->renderModal($modal);
	}

	/* ---------------------------------------------------------------------
	 * JS helper
	 * -------------------------------------------------------------------*/

	/**
	 * Global fetch-based AJAX handler for modal forms.
	 *
	 * Handles submitting modal forms via AJAX, displaying success or error messages,
	 * redirecting, and closing modals on success.
	 *
	 * @return string JavaScript code for global AJAX handling.
	 */
	public function globalAjaxHandlerJs(): string
	{
		$errGeneric = json_encode($this->mod->t('ui.ajax.error_generic'), JSON_UNESCAPED_UNICODE);
		$errServer  = json_encode($this->mod->t('ui.ajax.error_server'),  JSON_UNESCAPED_UNICODE);

		return <<<HTML
<script>
document.addEventListener('submit', async (ev) => {
  const form = ev.target.closest('form[data-ajax="pw-json"]');
  if (!form) return;
  ev.preventDefault();

  const ERR_GENERIC = {$errGeneric};
  const ERR_SERVER  = {$errServer};

  const errorId   = form.getAttribute('data-error-id')   || (form.querySelector('[id][id^="formError"]')?.id)   || 'formError';
  const successId = form.getAttribute('data-success-id') || (form.querySelector('[id][id^="formSuccess"]')?.id) || 'formSuccess';
  const errorBox   = document.getElementById(errorId);
  const successBox = document.getElementById(successId);

  const show = (el, msg) => { if(el){ el.textContent = msg || ''; el.style.display = msg ? 'block' : 'none'; } };
  show(errorBox,''); show(successBox,'');

  const fd = new FormData(form);
  try {
	const res  = await fetch(form.action, { method: form.method || 'POST', body: fd, credentials:'same-origin' });
	const json = await res.json();
	if (!json.ok) { show(errorBox, json.error || json.message || ERR_GENERIC); return; }
	if (json.msg || json.message) show(successBox, json.msg || json.message);
	if (json.redirect) { window.location.href = json.redirect; return; }

	const op = fd.get('op') || fd.get('action') || '';
	const modalEl = form.closest('.modal');
	const modal   = modalEl ? bootstrap.Modal.getOrCreateInstance(modalEl) : null;
	if (op === 'reset_request') {
	  setTimeout(()=> modal?.hide(), 800);
	} else if (op === 'reset_password' || op === 'set_password') {
	  setTimeout(()=> window.location.href = window.location.pathname, 600);
	}
  } catch(e) {
	show(errorBox, ERR_SERVER);
  }
});
</script>
HTML;
	}

	/* ---------------------------------------------------------------------
	 * Small helpers
	 * -------------------------------------------------------------------*/

	/**
	 * Replace placeholders {firstname} and {email} in text,
	 * escaping the outer string and wrapping replacements in bold tags.
	 *
	 * @param string $text Input text with placeholders.
	 * @param \ProcessWire\User|null $u Optional user for replacement variables.
	 * @return string HTML escaped output with placeholders replaced.
	 */
	private function fillPlaceholders(string $text, ?\ProcessWire\User $u = null): string
	{
		// Mark placeholder tokens, escape the text, then replace placeholders wrapped in <b>
		$withTokens = strtr($text, ['{firstname}' => '%%FIRSTNAME%%', '{email}' => '%%EMAIL%%']);
		$escaped    = htmlspecialchars($withTokens, ENT_QUOTES, 'UTF-8');

		$firstname = $u ? $this->displayName($u) : '';
		$email     = $u ? (string)$u->email     : '';
		$fnEsc     = htmlspecialchars($firstname, ENT_QUOTES, 'UTF-8');
		$emEsc     = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');

		$out = strtr($escaped, [
			'%%FIRSTNAME%%' => ($fnEsc !== '' ? '<b>'.$fnEsc.'</b>' : ''),
			'%%EMAIL%%'     => ($emEsc !== '' ? '<b>'.$emEsc.'</b>' : ''),
		]);

		return '<p>'.$out.'</p>';
	}

	/**
	 * Generate a display name for a user, preferring title field or email prefix.
	 *
	 * @param \ProcessWire\User $user The user to generate display name for.
	 * @return string The display name.
	 */
	private function displayName(\ProcessWire\User $user): string
	{
		if ($user->hasField('title') && $user->title) return trim((string)$user->title);
		if (!empty($user->email)) {
			$at = strpos($user->email, '@');
			return $at !== false ? substr($user->email, 0, $at) : $user->email;
		}
		return '';
	}
}
