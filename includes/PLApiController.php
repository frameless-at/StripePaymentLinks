<?php namespace ProcessWire;

/**
 * Class PLApiController
 *
 * Handles JSON API requests under /stripepaymentlinks/api and Stripe webhook events.
 */
final class PLApiController {

  /** @var StripePaymentLinks Reference to main module. */
  private StripePaymentLinks $mod;

  /**
   * Constructor
   *
   * @param StripePaymentLinks $mod Main module instance.
   */
  public function __construct(StripePaymentLinks $mod) {
	$this->mod = $mod;
  }

  /**
   * Find a ProcessWire user by email address.
   *
   * @param string $email Email address to look up.
   * @return \ProcessWire\User|null User instance or null if not found.
   */
  private function findUserByEmail(string $email): ?\ProcessWire\User {
	$u = wire('users')->get('email=' . wire('sanitizer')->email(trim($email)));
	return ($u && $u->id) ? $u : null;
  }

  /**
   * Find a ProcessWire user by their Stripe customer ID stored in spl_purchases metadata.
   *
   * @param string $customerId Stripe customer ID from a webhook event.
   * @return \ProcessWire\User|null Matching user or null if none found.
   */
  private function findUserByStripeCustomerId(string $customerId): ?\ProcessWire\User {
	if ($customerId === '') return null;
	$users = wire('users');

	foreach ($users as $u) {
	  if (!$u->hasField('spl_purchases') || !$u->spl_purchases->count()) continue;
	  foreach ($u->spl_purchases as $purchase) {
		$session = (array) $purchase->meta('stripe_session');
		$cust = $session['customer'] ?? null;

		if (is_string($cust) && $cust !== '') {
		  $storedId = $cust;
		} elseif (is_array($cust) && !empty($cust['id'])) {
		  $storedId = (string) $cust['id'];
		} elseif (is_object($cust) && !empty($cust->id)) {
		  $storedId = (string) $cust->id;
		} else {
		  $storedId = null;
		}

		if ($storedId === $customerId) {
		  return $u;
		}
	  }
	}

	return null;
  }

  /**
   * Handle incoming Stripe webhook for subscriptions and invoices.
   *
   * @param \ProcessWire\HookEvent $e Hook event with request data.
   */
  public function handleStripeWebhook(\ProcessWire\HookEvent $e): void {
	$e->replace = true;

	$payload   = file_get_contents('php://input') ?: '';
	$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
	$secret    = (string)($this->mod->webhookSecret ?? '');

	if ($secret === '') {
	  http_response_code(500);
	  $e->return = 'Missing webhook secret';
	  return;
	}

	try {
	  if (!class_exists('\Stripe\Webhook')) {
		require_once($this->mod->stripeSdkPath ?? (__DIR__ . '/../vendor/stripe-php/init.php'));
	  }
	  $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $secret);
	} catch (\Throwable $ex) {
	  wire('log')->save(StripePaymentLinks::LOG_PL, '[WEBHOOK] signature error: ' . $ex->getMessage());
	  http_response_code(400);
	  $e->return = 'Invalid signature';
	  return;
	}

	$type = (string) ($event->type ?? '(unknown)');
	$obj  = $event->data->object ?? null;

	try {
	  switch ($type) {

		case 'customer.subscription.deleted': {
		  $customerId = (string)($obj->customer ?? '');
		  if (!$customerId) break;

		  $u = $this->findUserByStripeCustomerId($customerId);
		  if (!$u || !$u->id) {
			wire('log')->save(StripePaymentLinks::LOG_PL, "[WEBHOOK] canceled subscription: no user found for $customerId");
			break;
		  }

		  foreach ($u->spl_purchases as $p) {
			$map = (array)$p->meta('period_end_map');
			if (!$map) continue;

			$now     = time();
			$changed = false;

			foreach ($map as $pid => $ts) {
			  if (!is_numeric($pid)) continue;
			  if ((int)$map[$pid] !== $now) {
				$map[$pid] = $now;
				$changed = true;
			  }
			  $cKey = $pid . '_canceled';
			  if (!isset($map[$cKey])) {
				$map[$cKey] = 1;
				$changed = true;
			  }
			}
			foreach (array_keys($map) as $k) {
			  if (is_string($k) && substr($k, -7) === '_paused') {
				unset($map[$k]);
				$changed = true;
			  }
			}

			if ($changed) {
			  $p->of(false);
			  $p->meta('period_end_map', $map);
			  $p->save(['quiet' => true]);
			  $this->mod->rebuildPurchaseLines($p);
			}
		  }
		  wire('log')->save(StripePaymentLinks::LOG_PL, "[WEBHOOK] subscription canceled for user {$u->id}");
		  break;
		}

		case 'customer.subscription.updated': {
		  $customerId = (string) ($obj->customer ?? '');
		  if (!$customerId) {
			wire('log')->save(StripePaymentLinks::LOG_PL, '[WEBHOOK] updated: missing customer id');
			break;
		  }

		  $u = $this->findUserByStripeCustomerId($customerId);
		  if (!$u || !$u->id) {
			wire('log')->save(StripePaymentLinks::LOG_PL, "[WEBHOOK] updated: no user for $customerId");
			break;
		  }

		  $pausedNow      = isset($obj->pause_collection) && $obj->pause_collection !== null;
		  $subscriptionId = (string)($obj->id ?? '');
		  $periodEnd      = isset($obj->current_period_end) && is_numeric($obj->current_period_end) ? (int)$obj->current_period_end : null;

		  // Generate scope keys from subscription items
		  $subscriptionProducts = [];
		  if (isset($obj->items->data) && is_array($obj->items->data)) {
			foreach ($obj->items->data as $lineItem) {
			  $stripePid = $lineItem->price->product ?? '';
			  $pages = $this->mod->wire('pages');
			  $p = $pages->get("stripe_product_id=" . $this->mod->wire('sanitizer')->selectorValue($stripePid));
			  $key = $p && $p->id ? (string)$p->id : ('0#' . $stripePid);
			  if ($key !== '') $subscriptionProducts[] = $key;
			}
			$subscriptionProducts = array_values(array_unique($subscriptionProducts));
		  }

		  if ($pausedNow) {
			// Set paused flag for matching scope keys
			foreach ($u->spl_purchases as $p) {
			  $map = (array)$p->meta('period_end_map');
			  if (!$map) {
				$this->mod->rebuildPurchaseLines($p);
				continue;
			  }
			  $changed = false;
			  foreach ($subscriptionProducts as $scopeKey) {
				$flagKey = $scopeKey . '_paused';
				if (!array_key_exists($flagKey, $map)) {
				  $map[$flagKey] = 1;
				  $changed = true;
				}
			  }
			  if ($changed) {
				$p->of(false);
				$p->meta('period_end_map', $map);
				$p->save(['quiet' => true]);
			  }
			  $this->mod->rebuildPurchaseLines($p);
			}
			wire('log')->save(StripePaymentLinks::LOG_PL, "[WEBHOOK] subscription paused for user {$u->id}");
			break;
		  } else {
			// Resume: remove paused flags for matching scope keys
			foreach ($u->spl_purchases as $p) {
			  $map = (array)$p->meta('period_end_map');
			  $changed = false;
			  foreach (array_keys($map) as $k) {
				if (is_string($k) && substr($k, -7) === '_paused' && in_array(substr($k, 0, -7), $subscriptionProducts, true)) {
				  unset($map[$k]);
				  $changed = true;
				}
			  }
			  if ($changed) {
				$p->of(false);
				$p->meta('period_end_map', $map);
				$p->save(['quiet' => true]);
			  }
			  $this->mod->rebuildPurchaseLines($p);
			}
			wire('log')->save(StripePaymentLinks::LOG_PL, "[WEBHOOK] subscription resumed for user {$u->id}");

			// Update period_end only if strictly greater than existing
			if ($periodEnd) {
			  $didUpdate = false;
			  foreach ($u->spl_purchases as $p) {
				$map = (array)$p->meta('period_end_map');
				$changed = false;
				foreach ($subscriptionProducts as $scopeKey) {
				  if (array_key_exists($scopeKey, $map)) {
					$old = is_numeric($map[$scopeKey]) ? (int)$map[$scopeKey] : 0;
					if ($periodEnd > $old) {
					  $map[$scopeKey] = $periodEnd;
					  $changed = true;
					}
				  }
				}
				if ($changed) {
				  $didUpdate = true;
				  $p->of(false);
				  $p->meta('period_end_map', $map);
				  $p->save(['quiet' => true]);
				  $this->mod->rebuildPurchaseLines($p);
				}
			  }
			  if ($didUpdate) {
				wire('log')->save(
				  StripePaymentLinks::LOG_PL,
				  "[WEBHOOK] updated (resume & period_end) user {$u->id} → period_end={$periodEnd}"
				);
			  }
			}
		  }

		  break;
		}

		case 'invoice.payment_succeeded': {
		  $customerId = (string)($obj->customer ?? '');
		  if (!$customerId) {
			wire('log')->save(StripePaymentLinks::LOG_PL, '[WEBHOOK] invoice.succeeded: missing customer id');
			break;
		  }

		  $u = $this->findUserByStripeCustomerId($customerId);
		  if (!$u || !$u->id) {
			wire('log')->save(StripePaymentLinks::LOG_PL, "[WEBHOOK] invoice.succeeded: no user for $customerId");
			break;
		  }

		  $periodEnd = null;
		  if (isset($obj->lines->data) && is_array($obj->lines->data) && count($obj->lines->data)) {
			$li0 = $obj->lines->data[0];
			if (isset($li0->period->end) && is_numeric($li0->period->end)) {
			  $periodEnd = (int)$li0->period->end;
			}
		  }

		  if ($periodEnd) {
			foreach ($u->spl_purchases as $p) {
			  $map = (array)$p->meta('period_end_map');
			  if (!$map) continue;

			  $changed = false;
			  foreach ($map as $pid => $ts) {
				if (!is_numeric($pid)) continue;
				$old = is_numeric($ts) ? (int)$ts : 0;
				if ($periodEnd > $old) {
				  $map[$pid] = $periodEnd;
				  $changed = true;
				}
			  }
			  if ($changed) {
				$p->of(false);
				$p->meta('period_end_map', $map);
				$p->save(['quiet' => true]);
				$this->mod->rebuildPurchaseLines($p);
			  }
			}
			wire('log')->save(StripePaymentLinks::LOG_PL, "[WEBHOOK] invoice.succeeded user {$u->id} → extended to {$periodEnd}");
		  }
		  break;
		}

		case 'invoice.payment_failed': {
		  $customerId = (string)($obj->customer ?? '');
		  if (!$customerId) {
			wire('log')->save(StripePaymentLinks::LOG_PL, '[WEBHOOK] invoice.failed: missing customer id');
			break;
		  }

		  $u = $this->findUserByStripeCustomerId($customerId);
		  if (!$u || !$u->id) {
			wire('log')->save(StripePaymentLinks::LOG_PL, "[WEBHOOK] invoice.failed: no user for $customerId");
			break;
		  }

		  wire('log')->save(StripePaymentLinks::LOG_PL, "[WEBHOOK] invoice.failed for user {$u->id} (no immediate lock)");
		  break;
		}

		default:
		  wire('log')->save(StripePaymentLinks::LOG_PL, "[WEBHOOK] ignored event $type");
		  break;
	  }
	} catch (\Throwable $ex) {
	  wire('log')->save(StripePaymentLinks::LOG_PL, '[WEBHOOK] handler error ' . $type . ': ' . $ex->getMessage());
	  http_response_code(500);
	  $e->return = 'Handler error';
	  return;
	}

	http_response_code(200);
	$e->return = 'OK';
  }

  /**
   * Handle API operations: login, set_password, reset_request, reset_password.
   *
   * @param object $event Hook event object.
   */
  public function handle($event): void
  {
	$input     = wire('input');
	$session   = wire('session');
	$users     = wire('users');
	$pages     = wire('pages');
	$config    = wire('config');
	$sanitizer = wire('sanitizer');

	$json = function(array $arr, int $status = 200): string {
	  http_response_code($status);
	  header('Content-Type: application/json; charset=utf-8');
	  return json_encode($arr, JSON_UNESCAPED_UNICODE);
	};

	if (!$input->requestMethod('POST')) {
	  $event->return = $json(['ok' => false, 'error' => $this->mod->t('api.method_not_allowed')], 405);
	  return;
	}

	if (!$session->CSRF->hasValidToken()) {
	  $event->return = $json(['ok' => false, 'error' => $this->mod->t('api.csrf_invalid')], 400);
	  return;
	}

	$op = (string) ($input->post->op ?: $input->post->action ?: '');

	// --------------------- LOGIN ---------------------
	if ($op === 'login') {
	  $email = trim((string)$input->post->email);
	  $pass  = (string)$input->post->password;
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
	  if ($session->get('pl_intended_url')) {
		$session->remove('pl_intended_url');
	  }
	  $event->return = $json([
		'ok' => true,
		'msg' => $this->mod->t('api.login.success'),
		'redirect' => $redirectUrl
	  ]);
	  return;
	}

	// ----------------- SET PASSWORD -----------------
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
	  $p1 = (string)$input->post->password;
	  $p2 = (string)$input->post->password_confirm;
	  if (strlen($p1) < 8) {
		$event->return = $json(['ok' => false, 'error' => $this->mod->t('api.password.too_short')]);
		return;
	  }
	  if ($p1 !== $p2) {
		$event->return = $json(['ok' => false, 'error' => $this->mod->t('api.password.mismatch')]);
		return;
	  }
	  try {
		$user->of(false);
		$user->pass = $p1;
		$user->must_set_password = 0;
		if ($user->hasField('access_token'))   $user->access_token   = '';
		if ($user->hasField('access_expires')) $user->access_expires = 0;
		$users->save($user);
		$event->return = $json(['ok' => true, 'msg' => $this->mod->t('api.resetpwd.success')]);
	  } catch (\Throwable $e) {
		$this->mod->wire('log')->save('users', 'set_password error: ' . $e->getMessage());
		$event->return = $json(['ok' => false, 'error' => $this->mod->t('api.server_error')], 500);
	  }
	  return;
	}

	// ----------------- RESET REQUEST -----------------
	if ($op === 'reset_request') {
	  $email     = trim((string)$input->post->email);
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
		  if (!$sent) {
			$this->mod->wire('log')->save('mail', '[WARN] reset_request: sendPasswordResetMail returned false {"user":'.$u->id.'}');
		  }
		}
	  }
	  $event->return = $json(['ok' => true, 'msg' => $okMsg]);
	  return;
	}

	// ---------------- RESET PASSWORD -----------------
	if ($op === 'reset_password') {
	  $token = $input->post->text('token');
	  $p1    = (string)$input->post->password;
	  $p2    = (string)$input->post->password_confirm;
	  if (!$token) {
		$event->return = $json(['ok' => false, 'error' => $this->mod->t('api.resetpwd.token_missing')]);
		return;
	  }
	  if (strlen($p1) < 8) {
		$event->return = $json(['ok' => false, 'error' => $this->mod->t('api.password.too_short')]);
		return;
	  }
	  if ($p1 !== $p2) {
		$event->return = $json(['ok' => false, 'error' => $this->mod->t('api.password.mismatch')]);
		return;
	  }
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
	  } catch (\Throwable $e) {
		$this->mod->wire('log')->save('users', 'reset_password error: ' . $e->getMessage());
		$event->return = $json(['ok' => false, 'error' => $this->mod->t('api.server_error')], 500);
	  }
	  return;
	}

	// Fallback for unknown actions
	$event->return = $json(['ok' => false, 'error' => $this->mod->t('api.unknown_action')]);
  }

}
