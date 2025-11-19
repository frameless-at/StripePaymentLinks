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
		   if (!$customerId) { wire('log')->save(StripePaymentLinks::LOG_PL, '[WEBHOOK] deleted: missing customer id'); break; }
   
		   $u = $this->findUserByStripeCustomerId($customerId);
		   if (!$u || !$u->id) { wire('log')->save(StripePaymentLinks::LOG_PL, "[WEBHOOK] deleted: no user for $customerId"); break; }
   
		   $scoped = $this->scopeKeysFromSubscription($obj);
		   $keys   = $scoped['keys'];
		   $subId  = ($scoped['subscription_id'] ?? null) ?: null;
			   $ended  = (isset($obj->ended_at) && is_numeric($obj->ended_at)) ? (int)$obj->ended_at : time();
   
		   if ($keys) {
			 $this->markCanceledForKeys($u, $keys, $ended, $subId);
			 wire('log')->save(StripePaymentLinks::LOG_PL, "[WEBHOOK] subscription deleted (scoped) user {$u->id} sub={$subId} keys=" . implode(',', $keys));
		   } else {
			 wire('log')->save(StripePaymentLinks::LOG_PL, "[WEBHOOK] subscription deleted: no keys resolved (user {$u->id}, sub={$subId})");
		   }
		   break;
		 }
   
		 case 'customer.subscription.updated': {
		   $customerId = (string)($obj->customer ?? '');
		   if (!$customerId) { wire('log')->save(StripePaymentLinks::LOG_PL, '[WEBHOOK] updated: missing customer id'); break; }
   
		   $u = $this->findUserByStripeCustomerId($customerId);
		   if (!$u || !$u->id) { wire('log')->save(StripePaymentLinks::LOG_PL, "[WEBHOOK] updated: no user for $customerId"); break; }
   
		   $scoped    = $this->scopeKeysFromSubscription($obj);
		   $keys      = $scoped['keys'];
		   $subId  = ($scoped['subscription_id'] ?? null) ?: null;
		   $pausedNow = isset($obj->pause_collection) && $obj->pause_collection !== null;
		   $periodEnd = (isset($obj->current_period_end) && is_numeric($obj->current_period_end)) ? (int)$obj->current_period_end : null;
   
		   if ($keys) {
			 $this->setPausedForKeys($u, $keys, $pausedNow, $subId);
			 if ($periodEnd) $this->updatePeriodEndForKeys($u, $keys, $periodEnd, true, $subId);
			 wire('log')->save(StripePaymentLinks::LOG_PL, "[WEBHOOK] subscription updated (scoped) user {$u->id} sub={$subId} keys=" . implode(',', $keys) . ($pausedNow ? ' [paused]' : ''));
		   } else {
			 wire('log')->save(StripePaymentLinks::LOG_PL, "[WEBHOOK] subscription updated: no keys resolved (user {$u->id}, sub={$subId})");
		   }
		   break;
		 }
   
		 case 'customer.subscription.created': {
		   $customerId = (string)($obj->customer ?? '');
		   if (!$customerId) { wire('log')->save(StripePaymentLinks::LOG_PL, '[WEBHOOK] created: missing customer id'); break; }
   
		   $u = $this->findUserByStripeCustomerId($customerId);
		   if (!$u || !$u->id) { wire('log')->save(StripePaymentLinks::LOG_PL, "[WEBHOOK] created: no user for $customerId"); break; }
   
		   $scoped    = $this->scopeKeysFromSubscription($obj);
		   $keys      = $scoped['keys'];
		   $subId  = ($scoped['subscription_id'] ?? null) ?: null;
		   $periodEnd = (isset($obj->current_period_end) && is_numeric($obj->current_period_end)) ? (int)$obj->current_period_end : null;
   
		   if ($keys) {
			 if ($periodEnd) $this->updatePeriodEndForKeys($u, $keys, $periodEnd, true, $subId);
			 $this->setPausedForKeys($u, $keys, false, $subId);
			 wire('log')->save(StripePaymentLinks::LOG_PL, "[WEBHOOK] subscription created (scoped) user {$u->id} sub={$subId} keys=" . implode(',', $keys));
		   } else {
			 wire('log')->save(StripePaymentLinks::LOG_PL, "[WEBHOOK] subscription created: no keys resolved (user {$u->id}, sub={$subId})");
		   }
		   break;
		 }
   
   		 case 'invoice.payment_succeeded': {
		   $customerId = (string)($obj->customer ?? '');
		   if (!$customerId) { wire('log')->save(StripePaymentLinks::LOG_PL, '[WEBHOOK] invoice.succeeded: missing customer id'); break; }
		 
		   $u = $this->findUserByStripeCustomerId($customerId);
		   if (!$u || !$u->id) { wire('log')->save(StripePaymentLinks::LOG_PL, "[WEBHOOK] invoice.succeeded: no user for $customerId"); break; }
		 
		   $parsed = $this->scopeKeysFromInvoice($obj);
		   $groups = (array)($parsed['groups'] ?? []);
		   $end    = $parsed['period_end'] ?? null;
		 
		   if ($end && $groups) {
			 foreach ($groups as $subId => $keys) {
			   if (!$keys) continue;
			   // scope by subscription if present; otherwise unscoped (null)
			   $subScope = ($subId !== null && $subId !== '') ? $subId : null;
			   $this->updatePeriodEndForKeys($u, $keys, (int)$end, true, $subScope);
			   $this->setPausedForKeys($u, $keys, false, $subScope);
			   wire('log')->save(
				 StripePaymentLinks::LOG_PL,
				 "[WEBHOOK] invoice.succeeded (scoped) user {$u->id} sub=" . ($subScope ?? '∅') .
				 " keys=" . implode(',', $keys) . " → period_end={$end}"
			   );
			 }
		   } else {
			 wire('log')->save(StripePaymentLinks::LOG_PL, "[WEBHOOK] invoice.succeeded: nothing to update");
		   }
		   break;
		 }
		    
		 case 'invoice.payment_failed': {
		   $customerId = (string)($obj->customer ?? '');
		   wire('log')->save(StripePaymentLinks::LOG_PL, "[WEBHOOK] invoice.failed for customer {$customerId}");
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
/** Build a stable scope key for a subscription line item product:
   *  Prefer ProcessWire product page ID (if it exists), else fallback "0#<stripe_product_id>".
   */
private function scopeKeyFromStripeProduct(string $stripeProductId): string {
	 $pages = $this->mod->wire('pages');         
	 $san   = $this->mod->wire('sanitizer');    
	 $p     = $pages->get("stripe_product_id=" . $san->selectorValue($stripeProductId));
	 return ($p && $p->id) ? (string)$p->id : ('0#' . $stripeProductId);
   }
  

/** Extract product scope keys AND the subscription_id */
   private function scopeKeysFromSubscription($sub): array {
	   $keys = [];
   
	   if (isset($sub->items->data) && is_array($sub->items->data)) {
		   foreach ($sub->items->data as $line) {
			   $prod = (string)($line->price->product ?? '');
			   if ($prod !== '') {
				   $keys[] = $this->scopeKeyFromStripeProduct($prod);
			   }
		   }
	   }
   
	   return [
		   'keys' => array_values(array_unique(array_filter($keys))),
		   'subscription_id' => (string)($sub->id ?? '')
	   ];
   }
   
   /** Extract scope groups from a Stripe Invoice:
   *  - returns buckets by subscription_id (can be null) with their product scope keys
   *  - also returns the max(period_end) over all lines
   */
  private function scopeKeysFromInvoice($invoice): array {
	$groups    = [];   // [ (string|null)$subId => array<string> $keys ]
	$periodEnd = null;

	if (isset($invoice->lines->data) && is_array($invoice->lines->data)) {
	  wire('log')->save(StripePaymentLinks::LOG_PL, 'DEBUG scopeKeysFromInvoice: Processing ' . count($invoice->lines->data) . ' invoice lines');
	  foreach ($invoice->lines->data as $line) {
		// product key for the line
		$prod = '';
		if (isset($line->price) && isset($line->price->product)) $prod = (string)$line->price->product;
		if ($prod === '') continue;

		$key  = $this->scopeKeyFromStripeProduct($prod);

		// subscription id on the line (may be missing)
		$subId = null;
		if (isset($line->subscription) && is_string($line->subscription) && $line->subscription !== '') {
		  $subId = $line->subscription;
		}

		if (!isset($groups[$subId])) $groups[$subId] = [];
		$groups[$subId][] = $key;

		// capture period_end (take max) - ONLY for recurring prices
		$priceType = (string)($line->price->type ?? '');
		$linePeriodEnd = isset($line->period->end) ? (int)$line->period->end : null;
		wire('log')->save(
		  StripePaymentLinks::LOG_PL,
		  "DEBUG scopeKeysFromInvoice: Line - product: $prod, key: $key, price.type: $priceType, period.end: " . ($linePeriodEnd ?: 'NULL')
		);

		if ($priceType === 'recurring' && isset($line->period->end) && is_numeric($line->period->end)) {
		  $end = (int)$line->period->end;
		  if (!$periodEnd || $end > $periodEnd) $periodEnd = $end;
		  wire('log')->save(StripePaymentLinks::LOG_PL, "DEBUG scopeKeysFromInvoice: SET period_end to $end for recurring product $prod");
		} elseif ($priceType !== 'recurring' && $linePeriodEnd) {
		  wire('log')->save(StripePaymentLinks::LOG_PL, "DEBUG scopeKeysFromInvoice: SKIPPED period_end for one-time product $prod (type: $priceType)");
		}
	  }
	}

	// de-dup keys per bucket
	foreach ($groups as $sid => $keys) {
	  $groups[$sid] = array_values(array_unique(array_filter($keys)));
	}

	return ['groups' => $groups, 'period_end' => $periodEnd];
  }
    
/** Update only the purchase whose meta['subscription_id'] matches */
/** Update only the purchase whose meta['subscription_id'] matches (robust) */
private function updatePurchasesMap(\ProcessWire\User $u, callable $mutator, ?string $subscriptionId = null): void {
  if (!$u->hasField('spl_purchases') || !$u->spl_purchases->count()) return;

  // treat empty string as null (no scoping)
  $subscriptionId = ($subscriptionId !== null && $subscriptionId !== '') ? $subscriptionId : null;

  $matched = 0;

  foreach ($u->spl_purchases as $p) {
	if ($subscriptionId !== null) {
	  $meta = (array) $p->meta('stripe_session');

	  // tolerate different shapes in stored session
	  $stored = $meta['subscription_id'] ?? null;
	  if ($stored === null) {
		$sub = $meta['subscription'] ?? null;
		if (is_string($sub) && $sub !== '') {
		  $stored = $sub;
		} elseif (is_array($sub) && !empty($sub['id'])) {
		  $stored = (string) $sub['id'];
		} elseif (is_object($sub) && !empty($sub->id)) {
		  $stored = (string) $sub->id;
		}
	  }

	  if ($stored !== $subscriptionId) continue;
	}

	$map     = (array) $p->meta('period_end_map');
	$changed = ($mutator($map) === true);

	if ($changed) {
	  $p->of(false);
	  $p->meta('period_end_map', $map);
	  $p->save(['quiet' => true]);

	  if (method_exists($this->mod, 'rebuildPurchaseLines')) {
		$this->mod->rebuildPurchaseLines($p);
	  }
	  $matched++;
	}
  }

  if ($subscriptionId !== null && $matched === 0) {
	wire('log')->save(StripePaymentLinks::LOG_PL, "[WEBHOOK] no purchase matched subscription_id={$subscriptionId} for user {$u->id}");
  }
}

/** Mark specific scope keys as CANCELED at given timestamp; remove any *_paused flag for those keys. */
private function markCanceledForKeys(\ProcessWire\User $u, array $keys, int $endedTs, ?string $subscriptionId = null): void {
  $this->updatePurchasesMap($u, function(array &$map) use ($keys, $endedTs): bool {
	$changed = false;
	foreach ($keys as $key) {
	  $old = isset($map[$key]) && is_numeric($map[$key]) ? (int)$map[$key] : 0;
	  if ($endedTs > $old) { $map[$key] = $endedTs; $changed = true; }

	  $cKey = $key . '_canceled';
	  if (!isset($map[$cKey])) { $map[$cKey] = 1; $changed = true; }

	  $pKey = $key . '_paused';
	  if (isset($map[$pKey])) { unset($map[$pKey]); $changed = true; }
	}
	return $changed;
  }, $subscriptionId);
}
  
  /** Set or clear *_paused flags for the given scope keys (optionally scoped to a subscription). */
  private function setPausedForKeys(\ProcessWire\User $u, array $keys, bool $paused, ?string $subscriptionId = null): void {
	$this->updatePurchasesMap($u, function(array &$map) use ($keys, $paused): bool {
	  $changed = false;
	  foreach ($keys as $key) {
		$flagKey = $key . '_paused';
		if ($paused) {
		  if (!isset($map[$flagKey])) { $map[$flagKey] = 1; $changed = true; }
		} else {
		  if (isset($map[$flagKey])) { unset($map[$flagKey]); $changed = true; }
		}
	  }
	  return $changed;
	}, $subscriptionId);
  }
    
/** Update period_end for given scope keys (optionally only if greater; optionally scoped to a subscription). */
  private function updatePeriodEndForKeys(\ProcessWire\User $u, array $keys, int $periodEnd, bool $onlyIfGreater = true, ?string $subscriptionId = null): void {
	$this->updatePurchasesMap($u, function(array &$map) use ($keys, $periodEnd, $onlyIfGreater): bool {
	  $changed = false;
	  foreach ($keys as $key) {
		$old = isset($map[$key]) && is_numeric($map[$key]) ? (int)$map[$key] : 0;
		if (!$onlyIfGreater || $periodEnd > $old) {
		  $map[$key] = $periodEnd;
		  $changed = true;
		}
	  }
	  return $changed;
	}, $subscriptionId);
  }
}
