<?php namespace ProcessWire;

/**
 * PLApiController
 * Handles POST /stripepaymentlinks/api JSON operations.
 */
final class PLApiController {

	/** @var StripePaymentLinks */
	private StripePaymentLinks $mod;

	public function __construct(StripePaymentLinks $mod) {
		$this->mod = $mod;
	}

	/**
	 * Entry point for the URL hook (POST only).
	 * Supported ops: login, set_password, reset_request, reset_password
	 */
	public function handle($event): void
	{
		$input   = wire('input');
		$session = wire('session');
		$users   = wire('users');
		$pages   = wire('pages');
		$config  = wire('config');
		$sanitizer = wire('sanitizer');

		$json = function(array $arr, int $status = 200): string {
			http_response_code($status);
			header('Content-Type: application/json; charset=utf-8');
			return json_encode($arr, JSON_UNESCAPED_UNICODE);
		};

		if ($input->requestMethod('POST') === false) {
			$event->return = $json(['ok' => false, 'error' => $this->mod->t('api.method_not_allowed')], 405);
			return;
		}

		if (!$session->CSRF->hasValidToken()) {
			$event->return = $json(['ok' => false, 'error' => $this->mod->t('api.csrf_invalid')], 400);
			return;
		}

		$op = (string) ($input->post->op ?: $input->post->action ?: '');

		/* ========================= LOGIN ========================= */
		if ($op === 'login') {
			$email = trim((string) $input->post->email);
			$pass  = (string) $input->post->password;

			if (!$email || !$pass) {
				$event->return = $json(['ok' => false, 'error' => $this->mod->t('api.login.missing_fields')]);
				return;
			}

			$u = $users->get("email=" . $sanitizer->email($email));
			if (!$u || !$u->id || !$session->login($u->name, $pass)) {
				$event->return = $json(['ok' => false, 'error' => $this->mod->t('api.login.invalid')]);
				return;
			}

			$redirectUrl = (string) ($session->get('pl_intended_url') ?: $input->post->redirect_url ?: $pages->get('/')->httpUrl);
			if ($session->get('pl_intended_url')) $session->remove('pl_intended_url');

			$event->return = $json(['ok' => true, 'msg' => $this->mod->t('api.login.success'), 'redirect' => $redirectUrl]);
			return;
		}

		/* =================== SET PASSWORD (post-purchase) =================== */
		if ($op === 'set_password') {
			$user = wire('user');
			if (!$user->isLoggedin()) {
				$event->return = $json(['ok' => false, 'error' => $this->mod->t('api.setpwd.not_logged_in')], 401);
				return;
			}
			if (!$user->hasField('must_set_password') || !$user->must_set_password) {
				$event->return = $json(['ok' => false, 'error' => $this->mod->t('api.setpwd.already_set')]);
				return;
			}

			$p1 = (string) $input->post->password;
			$p2 = (string) $input->post->password_confirm;

			if (strlen($p1) < 8) { $event->return = $json(['ok' => false, 'error' => $this->mod->t('api.password.too_short')]); return; }
			if ($p1 !== $p2)     { $event->return = $json(['ok' => false, 'error' => $this->mod->t('api.password.mismatch')]); return; }

			try {
				$user->of(false);
				$user->pass = $p1;
				$user->must_set_password = 0;
				if ($user->hasField('access_token'))   $user->access_token   = '';
				if ($user->hasField('access_expires')) $user->access_expires = 0;
				$users->save($user);
				$event->return = $json(['ok' => true, 'msg' => $this->mod->t('api.resetpwd.success')]);
				return;
			} catch (\Throwable $e) {
				$this->mod->wire('log')->save('users', 'set_password error: ' . $e->getMessage());
				$event->return = $json(['ok' => false, 'error' => $this->mod->t('api.server_error')], 500);
				return;
			}
		}

		/* ========================= RESET REQUEST ========================= */
		if ($op === 'reset_request') {
			$email     = trim((string) $input->post->email);
			$okMsg     = $this->mod->t('api.resetreq.ok_generic');
			$returnUrl = $sanitizer->url($input->post->return_url) ?: $pages->get('/')->httpUrl;

			try {
				$ru = parse_url($returnUrl);
				if (!empty($ru['host']) && isset($config->httpHost) && $ru['host'] !== $config->httpHost) {
					$returnUrl = $pages->get('/')->httpUrl;
				}
			} catch (\Throwable $e) {
				$returnUrl = $pages->get('/')->httpUrl;
			}

			if ($email) {
				$u = $users->get("email=" . $sanitizer->email($email));
				if ($u && $u->id) {
					if (!$u->hasField('reset_token') || !$u->hasField('reset_expires')) {
						$event->return = $json(['ok' => false, 'error' => $this->mod->t('api.resetreq.not_config')]);
						return;
					}

					$token = bin2hex(random_bytes(32));
					$u->of(false);
					$u->reset_token   = $token;
					$u->reset_expires = time() + 2 * 3600;
					$users->save($u);

					$glue     = (strpos($returnUrl, '?') === false) ? '?' : '&';
					$resetUrl = $returnUrl . $glue . 'reset=' . urlencode($token);

					$sent = $this->mod->mail()->sendPasswordResetMail($this->mod, $u, $resetUrl);
					if (!$sent) 
						$this->mod->wire('log')->save('mail', '[WARN] reset_request: sendPasswordResetMail returned false {"user":'.$u->id.'}');				
					}
			}

			$event->return = $json(['ok' => true, 'msg' => $okMsg]);
			return;
		}

		/* ========================= RESET PASSWORD ========================= */
		if ($op === 'reset_password') {
			$token = $input->post->text('token');
			$p1    = (string) $input->post->password;
			$p2    = (string) $input->post->password_confirm;

			if (!$token)           { $event->return = $json(['ok' => false, 'error' => $this->mod->t('api.resetpwd.token_missing')]); return; }
			if (strlen($p1) < 8)   { $event->return = $json(['ok' => false, 'error' => $this->mod->t('api.password.too_short')]); return; }
			if ($p1 !== $p2)       { $event->return = $json(['ok' => false, 'error' => $this->mod->t('api.password.mismatch')]); return; }

			$now = time();
			$u = $users->get("reset_token=$token, reset_expires>=$now");
			if (!$u || !$u->id) {
				$event->return = $json(['ok' => false, 'error' => $this->mod->t('api.resetpwd.token_invalid')]);
				return;
			}

			try {
				$u->of(false);
				$u->pass = $p1;
				if ($u->hasField('reset_token'))       $u->reset_token = '';
				if ($u->hasField('reset_expires'))     $u->reset_expires = 0;
				if ($u->hasField('must_set_password')) $u->must_set_password = 0;
				if ($u->hasField('access_token'))      $u->access_token = '';
				if ($u->hasField('access_expires'))    $u->access_expires = 0;
				$users->save($u);
				$event->return = $json(['ok' => true, 'msg' => $this->mod->t('api.resetpwd.success')]);
				return;
			} catch (\Throwable $e) {
				$this->mod->wire('log')->save('users', 'reset_password error: ' . $e->getMessage());
				$event->return = $json(['ok' => false, 'error' => $this->mod->t('api.server_error')], 500);
				return;
			}
		}

		// Fallback
		$event->return = $json(['ok' => false, 'error' => $this->mod->t('api.unknown_action')]);
	}
}