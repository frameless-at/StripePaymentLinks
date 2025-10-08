<?php namespace ProcessWire;

/**
 * Class PLSyncHelper
 *
 * Helper used by StripePaymentLinks to synchronize Stripe Checkout Sessions
 * into ProcessWire user purchase records (repeater `spl_purchases`).
 *
 * Responsibilities:
 * - Read module config, resolve API keys and date filters.
 * - Page through Stripe Checkout Sessions and filter to paid sessions.
 * - For each session, fetch line items, map Stripe products to PW page IDs,
 *   and decide whether to CREATE or UPDATE a purchase item.
 * - Persist data in the same structure the module writes:
 *     - Field: purchase_date (timestamp)
 *     - Field: purchase_lines (human readable lines)
 *     - Meta:  product_ids (array<int>)
 *     - Meta:  stripe_session (expanded Stripe session array)
 *
 * Notes:
 * - Dry-run and update/create behavior is controlled by runtime flags set in
 *   runSyncFromConfig().
 */
final class PLSyncHelper extends Wire {

  /** @var StripePaymentLinks Reference to the main module */
  private $mod;
  
  /**
  * Constructor.
  *
  * @param \ProcessWire\StripePaymentLinks $mod Reference to the main module.
  */
  public function __construct(\ProcessWire\StripePaymentLinks $mod) {
	$this->mod = $mod;
  }
  
  /** Runtime options (set by runSyncFromConfig) */
  private bool $optDry = true;
  private bool $optUpdateExisting = false;
  private bool $optCreateMissing  = false;
  

	/* ========= Helpers: config / keys / cache ========= */
	
	/** @var array<string,\Stripe\StripeClient> */
	private array $stripeClients = [];
	private function clientFor(string $apiKey): \Stripe\StripeClient {
	  if (!isset($this->stripeClients[$apiKey])) {
		$this->stripeClients[$apiKey] = new \Stripe\StripeClient(['api_key' => $apiKey]);
	  }
	  return $this->stripeClients[$apiKey];
	}
	
	private array $userCache = [];
	private function findUserByEmailCached(string $email): ?\ProcessWire\User {
	  $key = strtolower($email);
	  if (array_key_exists($key, $this->userCache)) return $this->userCache[$key];
	  $u = $this->findUserByEmail($email);
	  if ($u) $this->userCache[$key] = $u; // nur positive Treffer cachen
	  return $u;
	}
	
	private array $linkedMapCache = []; // emailLower => [sessionId => purchaseId]
	
	private function linkedMapForUser(\ProcessWire\User $u): array {
	  $key = strtolower((string)$u->email);
	  if (isset($this->linkedMapCache[$key])) return $this->linkedMapCache[$key];
	  $map = [];
	  if ($u->hasField('spl_purchases')) {
		foreach ($u->spl_purchases as $item) {
		  $sid = $this->extractSessionIdFromMeta($item->meta('stripe_session'));
		  if ($sid) $map[$sid] = (int)$item->id;
		}
	  }
	  return $this->linkedMapCache[$key] = $map;
	}
		
	/** High-res timer start. */
	private function t0(): float { return microtime(true); }
	
	/** Milliseconds since $t0. */
	private function ms(float $t0): int { return (int) round((microtime(true) - $t0) * 1000); }
	
	/** Format milliseconds as human-readable duration. */
	private function fmtDuration(int $ms): string {
	  return ($ms < 1000) ? ($ms . 'ms') : (number_format($ms / 1000, 2, '.', '') . 's');
	}

	/**
	 * Safe getter for nested object/array paths.
	 *
	 * @param mixed $src   Source object/array.
	 * @param array $path  Key/property path, e.g. ['price','product','name'].
	 * @return mixed|null  Found value or null if missing.
	 */
	private function g($src, array $path) {
	$cur = $src;
	foreach ($path as $k) {
	  if (is_object($cur)) {
		if (!isset($cur->$k)) return null;
		$cur = $cur->$k;
	  } elseif (is_array($cur)) {
		if (!array_key_exists($k, $cur)) return null;
		$cur = $cur[$k];
	  } else {
		return null;
	  }
	}
	return $cur;
  }
  
  /**
 * Split raw keys input (string or array) into a clean list.
 *
 * @param string|array $raw
 * @return array<string>
 */
  private function splitKeys($raw): array {
	if (is_string($raw)) $raw = preg_split('~\R+~', trim($raw)) ?: [];
	return array_values(array_filter(array_map(fn($k)=>trim((string)$k), (array)$raw)));
  }
  
  /**
 * Select subset of keys by indices; if none given, return all.
 *
 * @param array<string> $all
 * @param array<int>    $idx
 * @return array<string>
 */
  private function selectKeys(array $all, array $idx): array {
	$idx = array_map('intval', $idx);
	return $idx ? array_values(array_intersect_key($all, array_flip($idx))) : $all;
  }

/**
   * Normalize a date range so that both bounds include the full day.
   *
   * - If only $fromTs is set → ensure it's at 00:00:00 of that day.
   * - If only $toTs is set   → ensure it's at 23:59:59 of that day.
   * - If both are set        → normalize both sides to cover the full days.
   *
   * This prevents the "empty result" bug when filtering Stripe sessions
   * by exact day (Stripe's `created` filter expects a full timestamp range).
   *
   * @param int $fromTs  Start timestamp (may be 0 if not set).
   * @param int $toTs    End timestamp   (may be 0 if not set).
   * @return array{0:int,1:int} Normalized [$fromTs, $toTs].
   */
    private function normalizeDateRange(int $fromTs, int $toTs): array {
	  // if only a date (00:00:00) was provided for "to", make it end-of-day
	  if ($fromTs && $toTs && $fromTs === $toTs) {
		  $toTs = $toTs + 86399; // same day → expand to 23:59:59
	  } elseif ($toTs && ($toTs % 86400) === 0) {
		  // heuristic: midnight stamp → treat as end-of-day
		  $toTs = $toTs + 86399;
	  }
	  // ensure order
	  if ($fromTs && $toTs && $toTs < $fromTs) {
		  [$fromTs, $toTs] = [$toTs, $fromTs];
	  }
	  return [$fromTs, $toTs];
  }
  
/* ========= Helpers: Stripe ========= */

/**
 * Ensure Stripe SDK is present and add version info to report.
 *
 * @param \ProcessWire\Session $ses
 * @param array<string>        $report
 * @return bool True if SDK found, false otherwise (and report is stored).
 */
  private function ensureStripe(\ProcessWire\Session $ses, array &$report): bool {
	$sdk = $this->mod->wire('config')->paths->siteModules . 'StripePaymentLinks/vendor/stripe-php/init.php';
	if (!is_file($sdk)) { $report[] = 'Stripe SDK: NOT FOUND'; $ses->set('pl_sync_report', implode("\n",$report)); return false; }
	require_once $sdk;
	return true;
  }
  
  /**
 * Fetch all sessions for a key with pagination and optional date filter.
 *
 * @param string $apiKey
 * @param int    $fromTs
 * @param int    $toTs
 * @param int    $limitPerPage
 * @return array<int,object> Stripe Checkout Session objects.
 */  
private function fetchSessionsForKey(string $apiKey, int $fromTs, int $toTs, int $limitPerPage = 100): array {
   $client = $this->clientFor($apiKey);
   $params = ['limit' => $limitPerPage];
   if ($fromTs || $toTs) {
	 $created = [];
	 if ($fromTs) $created['gte'] = $fromTs;
	 if ($toTs)   $created['lte'] = $toTs;
	 $params['created'] = $created;
   }
   $out = [];
   $startingAfter = null;
   do {
	 $pageParams = $params;
	 if ($startingAfter) $pageParams['starting_after'] = $startingAfter;
	 $page = $client->checkout->sessions->all($pageParams);
	 $data = (is_object($page) && isset($page->data) && is_array($page->data)) ? $page->data : [];
	 foreach ($data as $s) $out[] = $s;
	 $startingAfter = count($data) ? end($data)->id : null;
   } while (!empty($page->has_more));
   return $out;
 }

/**
  * Append a single session row to the report and (if paid) run the
  * CREATE/UPDATE flow. When writing, also backfill missing period_end_map.
  *
  * @param object            $s            Stripe Checkout Session (list payload).
  * @param array<string>     $report       Report buffer (by ref).
  * @param string            $apiKey       Stripe secret used for this batch.
  * @param array<string,int> $preLinkedMap Optional map sessionId=>purchaseId for the target user.
  * @return void
  */
 private function reportSessionRow($s, array &$report, string $apiKey, array $preLinkedMap = []): void {
	 $sid  = (string)($s->id ?? '');
	 $paid = ((string)($s->payment_status ?? '')) === 'paid';
	 $when = isset($s->created) ? date('Y-m-d H:i', (int)$s->created) : '';
	 $line = $when . ' ' . substr($sid, 0, 12) . '...';
 
	 if (!$paid) return;
 
	 $email = $this->g($s, ['customer_details','email']) ?? $this->g($s, ['customer_email']);
	 if (!$email) { $report[] = $line . ' [SKIP no email]'; return; }
 
	 // Resolve user + prior linking
	 $u = $this->findUserByEmailCached($email);
	 $linkedId = null;
	 if ($u) {
		 $linkedId = $preLinkedMap ? ($preLinkedMap[$sid] ?? null) : ($this->linkedMapForUser($u)[$sid] ?? null);
	 }
 
	 $status = ($u && $linkedId) ? 'LINKED' : 'MISSING';
	 $line  .= ' [' . $status . '] ' . $email;
 
	 if ($linkedId && !$this->optUpdateExisting) {
		 $report[] = $line . ' ⇒ action: LINKED (no update)';
		 return;
	 }
 
	 // Expanded session (for meta + subscription details)
	 $expanded = $this->retrieveExpandedSession($sid, $apiKey);
	 if (!$expanded) { $report[] = $line . ' [expand failed]'; return; }
 
	 // Resolve subscription period end (if any)
	 $client    = $this->clientFor($apiKey);
	 $periodEnd = $this->resolvePeriodEndFromSessionOrApi($expanded, $client); // int|null
 
	 // Line items (prefer expanded)
	 $items = $this->g($expanded, ['line_items']);
	 if (!$items) {
		 try { $items = $this->fetchLineItemsWithClient($apiKey, $sid); }
		 catch (\Throwable $e) { $report[] = $line . ' [items failed]'; return; }
	 }
 
	 try {
		 // Append period end to recurring lines
		 [$lines, $productIds] = $this->buildLinesMetaFromStripeItems($items, (string)($expanded->currency ?? 'EUR'), $periodEnd);
	 } catch (\Throwable $e) {
		 $report[] = $line . ' [items build failed]';
		 return;
	 }
 
	 $sessionForPersist = $expanded;
 
	 // UPDATE
	 if ($linkedId) {
		 $line .= ' ⇒ action: UPDATE purchase #' . (int)$linkedId;
		 if (!$this->optDry) {
			 try {
				 $this->persistUpdatePurchase($u, (int)$linkedId, $sessionForPersist, $lines, $productIds);
				 // Backfill period_end_map for legacy items (no-op if already present)
				 $this->backfillPeriodEndsForUser($u, $client);
			 } catch (\Throwable $e) { /* logged in helpers */ }
		 }
		 $report[] = $line;
		 return;
	 }
 
	 // CREATE (create user if missing)
	 if (!$u) {
		 if (!$this->optCreateMissing) { $report[] = $line . ' ⇒ action: SKIP (user missing)'; return; }
		 if ($this->optDry) {
			 $report[] = $line . ' ⇒ action: CREATE (purchase)';
			 foreach ($lines as $L) $report[] = '         ' . $L;
			 $report[] = '';
			 return;
		 }
		 $fullName = (string)($this->g($s, ['customer_details','name']) ?? '');
		 $u = $this->createUserLikeModule($email, $fullName);
		 if (!$u || !$u->id) { $report[] = $line . ' ⇒ action: SKIP (user create failed)'; return; }
		 $this->userCache[strtolower($email)]      = $u;
		 $this->linkedMapCache[strtolower($email)] = $this->linkedMapForUser($u);
	 }
 
	 // CREATE purchase
	 $line .= ' ⇒ action: CREATE (purchase)';
	 if (!$this->optDry) {
		 try {
			 $this->persistNewPurchase($u, $sessionForPersist, $lines, $productIds);
			 // Backfill period_end_map for legacy items (no-op if already present)
			 $this->backfillPeriodEndsForUser($u, $client);
		 } catch (\Throwable $e) { /* logged in helpers */ }
	 }
	 $report[] = $line;
	 foreach ($lines as $L) $report[] = '         ' . $L;
	 $report[] = '';
 }
  
/* Fetch line items for a given Stripe Checkout Session.
 *
 * @param string $sessionId
 * @return object Stripe list object (data[] of line items).
 */  
  private function fetchLineItemsWithClient(string $apiKey, string $sessionId) {
	$client = $this->clientFor($apiKey);
	return $client->checkout->sessions->allLineItems($sessionId, [
	  'limit'  => 100,
	  'expand' => ['data.price.product'],
	]);
  }
/**
 * Format a single line item into the purchase_lines string format:
 * "{product_id} • {qty} • {name} • {amount currency}".
 *
 * @param object $li
 * @param string $fallbackCurrency
 * @return string
 */
  private function formatLineItem($li, string $fallbackCurrency): string {
	$stripePid = $this->liStripeProductId($li);
	$mappedId  = $stripePid !== '' ? ($this->mapStripeProductToPageId($stripePid) ?? 0) : 0;
  
	$qty  = max(1, (int)($this->g($li, ['quantity']) ?? 1));
	$name = $this->g($li, ['description'])
		 ?? $this->g($li, ['price','product','name'])
		 ?? $this->g($li, ['price','nickname'])
		 ?? 'Item';
  
	$cents = (int)($this->g($li, ['amount_total'])
			 ?? $this->g($li, ['amount'])
			 ?? 0);
  
	$cur = strtoupper((string)($this->g($li, ['currency']) ?? $fallbackCurrency ?: 'EUR'));
  
	return $mappedId . ' • ' . $qty . ' • ' . $name . ' • ' . number_format($cents/100, 2, '.', '') . ' ' . $cur;
  }

/**
   * Build purchase_lines and product_ids from Stripe line items.
   * Appends " • YYYY-MM-DD" to recurring items if $periodEndTs is given.
   *
   * @param object|array $items           Stripe list object or array-like.
   * @param string       $sessionCurrency Fallback currency.
   * @param int|null     $periodEndTs     Subscription period end (unix ts) or null.
   * @return array{0:array<int,string>,1:array<int,int>}
   */
  private function buildLinesMetaFromStripeItems($items, string $sessionCurrency = 'EUR', ?int $periodEndTs = null): array {
	  $lines = [];
	  $productIds = [];
  
	  $data = (is_object($items) && isset($items->data) && is_array($items->data)) ? $items->data : [];
	  foreach ($data as $li) {
		  $stripePid = $this->liStripeProductId($li);
		  $mappedId  = $stripePid !== '' ? ($this->mapStripeProductToPageId($stripePid) ?? 0) : 0;
  
		  $qty   = max(1, (int)($this->g($li, ['quantity']) ?? 1));
		  $name  = $this->g($li, ['description'])
				?? $this->g($li, ['price','product','name'])
				?? $this->g($li, ['price','nickname'])
				?? 'Item';
  
		  $cents = (int)($this->g($li, ['amount_total'])
				  ?? $this->g($li, ['amount'])
				  ?? 0);
  
		  $cur   = strtoupper((string)($this->g($li, ['currency']) ?? $sessionCurrency ?: 'EUR'));
  
		  $line = $mappedId . ' • ' . $qty . ' • ' . $name . ' • ' . number_format($cents/100, 2, '.', '') . ' ' . $cur;
  
		  // Append period end for recurring prices (if available)
		  $isRecurring = (bool) $this->g($li, ['price','recurring']);
		  if ($isRecurring && $periodEndTs) {
			  $line .= ' • ' . gmdate('Y-m-d', $periodEndTs);
		  }
  
		  $lines[]      = $line;
		  $productIds[] = (int) $mappedId;
	  }
  
	  return [$lines, $productIds];
  }
  
/* ========= Helpers: PW users / purchases ========= */

/**
 * Find a user by email.
 *
 * @param string $email
 * @return \ProcessWire\User|null
 */
  private function findUserByEmail(string $email): ?\ProcessWire\User {
	$users = $this->wire('users'); $san = $this->wire('sanitizer');
	$u = $users->get('email=' . $san->email($email));
	return ($u && $u->id) ? $u : null;
  }

/**
 * Extract session id from meta('stripe_session').
 *
 * @param mixed $meta
 * @return string|null
 */
  private function extractSessionIdFromMeta($meta): ?string {
	try {
	  if (is_array($meta) && !empty($meta['id'])) return (string)$meta['id'];
	  if (is_object($meta)) {
		if (method_exists($meta, 'toArray')) {
		  $arr = $meta->toArray();
		  if (!empty($arr['id'])) return (string)$arr['id'];
		}
		if (isset($meta->id) && $meta->id) return (string)$meta->id;
	  }
	} catch (\Throwable $e) {}
	return null;
  }

/**
 * Retrieve an expanded Stripe session for persistence in meta.
 *
 * @param string $sessionId
 * @return object|null
 */
private function retrieveExpandedSession(string $sessionId, string $apiKey) {
   try {
	 $client = $this->clientFor($apiKey);
	 return $client->checkout->sessions->retrieve($sessionId, [
	   'expand' => ['line_items.data.price.product', 'customer'],
	 ]);
   } catch (\Throwable $e) {
	 try {
	   $client = $this->clientFor($apiKey);
	   return $client->checkout->sessions->retrieve($sessionId);
	 } catch (\Throwable $e2) {}
	 return null;
   }
 }
  
/**
* Extract Stripe product id from a line item in a robust way.
*
* @param object $li
* @return string
*/ 
  private function liStripeProductId($li): string {
	try {
	  $pp = $this->g($li, ['price','product']);
	  if (is_object($pp) && !empty($pp->id)) return (string)$pp->id;
	  if (is_string($pp) && $pp !== '') return $pp;
	} catch (\Throwable $e) {}
	return '';
  }
  
/**
 * Map a Stripe product id to a ProcessWire product page id.
 *
 * @param string $stripeProductId
 * @return int|null Page id or null if not found.
 */
  private function mapStripeProductToPageId(string $stripeProductId): ?int {
	if ($stripeProductId === '') return null;
	$pages = $this->wire('pages');
	$san   = $this->wire('sanitizer');
  
	// optional: auf konfigurierte Produkt-Templates einschränken
	$tplNames = array_values(array_filter((array)($this->mod->productTemplateNames ?? [])));
	$tplNames = array_map([$san, 'name'], $tplNames);
	$tplSel   = $tplNames ? ('template=' . implode('|', $tplNames) . ', ') : '';
  
	$sid = $san->selectorValue($stripeProductId);
  
	if ($tplSel) {
	  if (($p = $pages->get($tplSel . "stripe_product_id=$sid")) && $p->id) return (int)$p->id;
	}
	if (($p = $pages->get("stripe_product_id=$sid")) && $p->id) return (int)$p->id;
  
	return null;
  }


/**
 * Persist a new purchase repeater item (CREATE path).
 * Stores purchase_date, purchase_lines and meta identical to the module.
 *
 * @param \ProcessWire\User $u
 * @param mixed             $sessionObj Expanded or raw session object/array.
 * @param array<int,string> $lines
 * @param array<int,int>    $productIds
 * @return void
 */
  private function persistNewPurchase(
	\ProcessWire\User $u,
	$sessionObj,
	array $lines,
	array $productIds
  ): void {
	/** @var \ProcessWire\Users $users */
	$users = $this->wire('users');
  
	if (!$u->hasField('spl_purchases')) return;
  
	// created aus Session (Fallback: now)
	$createdTs = 0;
	try {
	  if (is_object($sessionObj) && isset($sessionObj->created))      $createdTs = (int)$sessionObj->created;
	  elseif (is_array($sessionObj) && isset($sessionObj['created'])) $createdTs = (int)$sessionObj['created'];
	} catch (\Throwable $e) {}
	if ($createdTs <= 0) $createdTs = time();
  
	$u->of(false);
	$item = $u->spl_purchases->getNew();
	$item->set('purchase_date', $createdTs);
	$item->set('purchase_lines', implode("\n", $lines));
	$u->spl_purchases->add($item);
	$users->save($u, ['quiet' => true]);
  
	try {
	  $item->meta('product_ids', array_values(array_map('intval', $productIds)));
  
	  // WICHTIG: exakt wie im Modul – expandierte Session unter 'stripe_session'
	  $sessionArr = $sessionObj;
	  if (is_object($sessionObj) && method_exists($sessionObj, 'toArray')) $sessionArr = $sessionObj->toArray();
	  if (is_object($sessionArr)) $sessionArr = (array)$sessionArr;
  
	  $item->meta('stripe_session', is_array($sessionArr) ? $sessionArr : (array)$sessionObj);
	  // KEIN 'stripe_line_items' mehr speichern – das braucht dein Template nicht.
	  $item->save();
	} catch (\Throwable $e) {
	  $this->wire('log')->save(\ProcessWire\StripePaymentLinks::LOG_PL, '[SYNC] persist meta warning: '.$e->getMessage());
	}
  
	$u->of(true);
  }

/**
   * Resolve the subscription period end timestamp for a given Stripe Checkout session.
   *
   * This helper tries multiple strategies:
   *  1. If the session already contains an expanded subscription object, use its
   *     `current_period_end` or the one from its first subscription item.
   *  2. If the session only contains a subscription ID, fetch the subscription
   *     via the Stripe API and extract the same data.
   *
   * Used by the Sync Helper to backfill or initialize the `period_end_map` for
   * recurring purchases imported from Stripe.
   *
   * @param \Stripe\Checkout\Session|array|object $session The Stripe Checkout session (may contain expanded subscription).
   * @param \Stripe\StripeClient $client Initialized Stripe client with valid secret key.
   * @return int|null UNIX timestamp of the current period end, or null if not found.
   */
  private function resolvePeriodEndFromSessionOrApi($session, \Stripe\StripeClient $client): ?int {
	  // a) Expanded subscription object present on the session?
	  try {
		  if (is_object($session) && is_object($session->subscription ?? null)) {
			  $sub = $session->subscription;
			  if (!empty($sub->current_period_end)) return (int) $sub->current_period_end;
			  if (!empty($sub->items->data) && is_array($sub->items->data)) {
				  foreach ($sub->items->data as $si) {
					  if (!empty($si->current_period_end)) return (int) $si->current_period_end;
				  }
			  }
		  }
	  } catch (\Throwable $e) {
		  // ignore, fall through to ID path
	  }
  
	  // b) Only a subscription ID? Fetch it.
	  try {
		  $subId = null;
		  if (is_object($session) && is_string($session->subscription ?? null) && $session->subscription !== '') {
			  $subId = (string) $session->subscription;
		  } elseif (is_array($session) && !empty($session['subscription']) && is_string($session['subscription'])) {
			  $subId = (string) $session['subscription'];
		  }
		  if ($subId) {
			  $sub = $client->subscriptions->retrieve($subId, []);
			  if (!empty($sub->current_period_end)) return (int) $sub->current_period_end;
			  if (!empty($sub->items->data) && is_array($sub->items->data)) {
				  foreach ($sub->items->data as $si) {
					  if (!empty($si->current_period_end)) return (int) $si->current_period_end;
				  }
			  }
		  }
	  } catch (\Throwable $e) {
		  wire('log')->save(StripePaymentLinks::LOG_PL, '[SYNC] resolvePeriodEnd fetch failed: '.$e->getMessage());
	  }
  
	  return null;
  }
  
/**
   * Backfill missing or incomplete subscription metadata on purchases for a user.
   *
   * What this does:
   * - If a purchase has no `period_end_map`, it will be created using the
   *   subscription's `current_period_end` for all mapped product IDs.
   * - It mirrors subscription state into meta flags:
   *     - Sets or removes "{productId}_paused" depending on `pause_collection`.
   *     - If `status === canceled` → forces an effective end (prefers `ended_at`,
   *       then `cancel_at`, then `current_period_end`, finally `time()`).
   *     - If `cancel_at_period_end === true` → ensures period end >= `current_period_end`.
   * - Never shortens an existing end date; only raises or initializes it.
   *
   * Notes:
   * - This is a best-effort backfill for data imported without webhooks. Live webhook
   *   updates remain the source of truth for future transitions.
   *
   * @param \ProcessWire\User $u The ProcessWire user whose purchases should be updated.
   * @param \Stripe\StripeClient $client Initialized Stripe client with valid secret key.
   * @return void
   */
  private function backfillPeriodEndsForUser(\ProcessWire\User $u, \Stripe\StripeClient $client): void {
	  if (!$u->hasField('spl_purchases') || !$u->spl_purchases->count()) return;
  
	  foreach ($u->spl_purchases as $p) {
		  try {
			  $map = (array) $p->meta('period_end_map');       // may be empty
			  $prodIds = array_map('intval', (array) $p->meta('product_ids'));
			  if (!$prodIds) continue;
  
			  // Pull session meta to find the subscription reference
			  $sess = (array) $p->meta('stripe_session');
			  if (!$sess) continue;
  
			  // Rebuild a minimal session-like object for convenience
			  $sessionObj = (object) $sess;
  
			  // Try to get a subscription object or ID
			  $subObj = null;
			  $subId  = null;
  
			  if (is_object($sessionObj->subscription ?? null)) {
				  $subObj = $sessionObj->subscription;
				  $subId  = (string)($subObj->id ?? '');
			  } elseif (!empty($sess['subscription']) && is_string($sess['subscription'])) {
				  $subId = (string) $sess['subscription'];
			  }
  
			  // If we only have an ID, fetch the subscription
			  if (!$subObj && $subId) {
				  try {
					  $subObj = $client->subscriptions->retrieve($subId, []);
				  } catch (\Throwable $fe) {
					  // If we can't fetch, we may still initialize an end date via resolver
				  }
			  }
  
			  // Derive core flags/timestamps
			  $paused   = false;
			  $canceled = false;
			  $cap      = false; // cancel_at_period_end
			  $now      = time();
  
			  $currentEnd = null;
			  $cancelAt   = null;
			  $endedAt    = null;
  
			  if ($subObj) {
				  $paused     = isset($subObj->pause_collection) && $subObj->pause_collection !== null;
				  $canceled   = (string)($subObj->status ?? '') === 'canceled';
				  $cap        = (bool)($subObj->cancel_at_period_end ?? false);
				  $currentEnd = is_numeric($subObj->current_period_end ?? null) ? (int)$subObj->current_period_end : null;
				  $cancelAt   = is_numeric($subObj->cancel_at ?? null)          ? (int)$subObj->cancel_at          : null;
				  $endedAt    = is_numeric($subObj->ended_at ?? null)           ? (int)$subObj->ended_at           : null;
			  } else {
				  // Fallback: at least try to resolve current period end for annotation/initial map
				  $currentEnd = $this->resolvePeriodEndFromSessionOrApi($sessionObj, $client);
			  }
  
			  // If we couldn't resolve anything at all, skip this purchase
			  if ($currentEnd === null && !$subObj) continue;
  
			  $changed = false;
  
			  foreach ($prodIds as $pid) {
				  // --- paused marker handling ---
				  $flagKey = $pid . '_paused';
				  if ($paused) {
					  if (!array_key_exists($flagKey, $map)) {
						  $map[$flagKey] = 1;
						  $changed = true;
					  }
				  } else {
					  if (array_key_exists($flagKey, $map)) {
						  unset($map[$flagKey]);
						  $changed = true;
					  }
				  }
  
				  // --- effective period end handling ---
				  $existing = isset($map[$pid]) && is_numeric($map[$pid]) ? (int)$map[$pid] : 0;
				  $targetEnd = $existing;
  
				  if ($canceled) {
					  // Choose the most authoritative end:
					  // 1) ended_at (immediate termination), else 2) cancel_at, else 3) current_period_end, else 4) now
					  $end = $endedAt ?: ($cancelAt ?: ($currentEnd ?: $now));
					  if ($end > $targetEnd) $targetEnd = $end;
				  } elseif ($cap && $currentEnd) {
					  // Scheduled to end at period end
					  if ($currentEnd > $targetEnd) $targetEnd = $currentEnd;
				  } elseif ($currentEnd) {
					  // Active: ensure we don't shorten; only raise if newer invoice extended the end
					  if ($currentEnd > $targetEnd) $targetEnd = $currentEnd;
				  }
  
				  if ($targetEnd !== $existing && $targetEnd > 0) {
					  $map[$pid] = $targetEnd;
					  $changed = true;
				  }
			  }
  
			  if ($changed) {
				  $p->meta('period_end_map', $map);
				  try { $p->save(); } catch (\Throwable $e) {}
				  wire('log')->save(
					  StripePaymentLinks::LOG_PL,
					  sprintf('[SYNC] backfill updated purchase %d for user %d', (int)$p->id, (int)$u->id)
				  );
			  }
		  } catch (\Throwable $e) {
			  // Keep the backfill robust; log and continue with other purchases
			  wire('log')->save(StripePaymentLinks::LOG_PL, '[SYNC] backfill error: ' . $e->getMessage());
		  }
	  }
  }
  
/**
 * Update an existing purchase repeater item (UPDATE path).
 *
 * @param \ProcessWire\User $u
 * @param int               $purchaseItemId
 * @param mixed             $sessionObj Expanded or raw session object/array.
 * @param array<int,string> $lines
 * @param array<int,int>    $productIds
 * @return void
 */
  private function persistUpdatePurchase(
	\ProcessWire\User $u,
	int $purchaseItemId,
	$sessionObj,
	array $lines,
	array $productIds
  ): void {
	/** @var \ProcessWire\Users $users */
	$users = $this->wire('users');
	if (!$u->hasField('spl_purchases')) return;
  
	$item = $u->spl_purchases->get("id=$purchaseItemId");
	if (!$item || !$item->id) return;
  
	// created aus Session (Fallback: now)
	$createdTs = 0;
	try {
	  if (is_object($sessionObj) && isset($sessionObj->created))      $createdTs = (int)$sessionObj->created;
	  elseif (is_array($sessionObj) && isset($sessionObj['created'])) $createdTs = (int)$sessionObj['created'];
	} catch (\Throwable $e) {}
	if ($createdTs <= 0) $createdTs = time();
  
	$u->of(false);
	$item->set('purchase_date', $createdTs);
	$item->set('purchase_lines', implode("\n", $lines));
	$users->save($u, ['quiet' => true]);
  
	try {
	  $item->meta('product_ids', array_values(array_map('intval', $productIds)));
  
	  $sessionArr = $sessionObj;
	  if (is_object($sessionObj) && method_exists($sessionObj, 'toArray')) $sessionArr = $sessionObj->toArray();
	  if (is_object($sessionArr)) $sessionArr = (array)$sessionArr;
  
	  $item->meta('stripe_session', is_array($sessionArr) ? $sessionArr : (array)$sessionObj);
	  $item->save();
	} catch (\Throwable $e) {
	  $this->wire('log')->save(\ProcessWire\StripePaymentLinks::LOG_PL, '[SYNC] update meta warning: '.$e->getMessage());
	}
  
	$u->of(true);
  }
  
/**
 * Create a new user similar to the module's behavior (name from email,
 * random password, must_set_password flag, optional title, add 'customer' role).
 *
 * @param string $email
 * @param string $fullName
 * @return \ProcessWire\User|null
 */
  private function createUserLikeModule(string $email, string $fullName = ''): ?\ProcessWire\User {
	/** @var \ProcessWire\Users $users */
	$users = $this->wire('users');
	$san   = $this->wire('sanitizer');
	$roles = $this->wire('roles');
  
	try {
	  $u = new \ProcessWire\User();
	  $u->name  = $san->pageName($email, true);
	  $u->email = $email;
	  $u->pass  = bin2hex(random_bytes(8));
	  if ($u->hasField('must_set_password')) $u->must_set_password = 1;
	  if ($fullName !== '') $u->title = $fullName;
	  $users->save($u, ['quiet' => true]);
  
	  // Rolle 'customer' zuweisen, falls vorhanden
	  try {
		$role = $roles->get('customer');
		if ($role && $role->id && !$u->hasRole($role)) {
		  $u->of(false);
		  $u->roles->add($role);
		  $users->save($u, ['quiet' => true]);
		  $u->of(true);
		}
	  } catch (\Throwable $e) {}
  
	  return $u;
	} catch (\Throwable $e) {
	  $this->wire('log')->save(\ProcessWire\StripePaymentLinks::LOG_PL, '[SYNC] user create error: '.$e->getMessage());
	  return null;
	}
  }
  
  /**
   * Check whether a Stripe session belongs to the given buyer email.
   *
   * @param object $s
   * @param string $target normalized (lowercased) email
   * @return bool
   */
  private function sessionMatchesEmail($s, string $target): bool {
	$e1 = strtolower(trim((string)($this->g($s, ['customer_details','email']) ?? '')));
	$e2 = strtolower(trim((string)($this->g($s, ['customer_email']) ?? '')));
	return ($e1 && $e1 === $target) || ($e2 && $e2 === $target);
  }
	
/**
   * Parse module config into normalized options.
   *
   * @param array $cfg
   * @return array{keys:array,from:int,to:int,dry:bool,upd:bool,mkUsr:bool}
   */
  private function parseOptions(array $cfg): array {
	$allKeys = $this->splitKeys($cfg['stripeApiKeys'] ?? '');
	$useKeys = $this->selectKeys($allKeys, (array)($cfg['pl_sync_keys'] ?? []));
	$fromTs  = (int)($cfg['pl_sync_from'] ?? 0);
	$toTs    = (int)($cfg['pl_sync_to']   ?? 0);
	[$fromTs, $toTs] = $this->normalizeDateRange($fromTs, $toTs);
	return [
	  'keys' => $useKeys,
	  'from' => $fromTs,
	  'to'   => $toTs,
	  'dry'  => (bool)($cfg['pl_sync_dry_run'] ?? true),
	  'upd'  => (bool)($cfg['pl_sync_update_existing'] ?? false),
	  'mkUsr'=> (bool)($cfg['pl_sync_create_missing'] ?? false),
	];
  }

  /**
   * Build standard report header.
   *
   * @param array $cfg
   * @param array $opts
   * @param string $title
   * @param string|null $emailTarget
   * @return array<string>
   */
  private function buildReportHeader(array $cfg, array $opts, string $title, ?string $emailTarget): array {
	$r = [];
	$r[] = "== StripePaymentLinks: $title ==";
	if ($emailTarget !== null) $r[] = 'Email: ' . $emailTarget;
	$r[] = 'Mode: ' . ($opts['dry'] ? 'DRY RUN (no writes)' : 'WRITE');
	$r[] = 'Update existing: ' . ($opts['upd'] ? 'yes' : 'no');
	$r[] = 'Create missing users: ' . ($opts['mkUsr'] ? 'yes' : 'no');
	$r[] = 'From: ' . ($opts['from'] ? date('Y-m-d', $opts['from']) : '—');
	$r[] = 'To:   ' . ($opts['to']   ? date('Y-m-d', $opts['to'])   : '—');
	$r[] = '';
	return $r;
  }
/* ========= Public: run ========= */

  /**
   * Core sync: optionally filter sessions by email.
   *
   * @param array $cfg
   * @param string|null $emailTarget normalized or raw email; null = no filtering
   * @return void
   */
public function runSync(array $cfg, ?string $emailTarget = null): void {
	 $log = $this->wire('log'); 
	 $ses = $this->wire('session');
	 $__tAll = $this->t0();
   
	 $opts = $this->parseOptions($cfg);
   
	 // runtime flags
	 $this->optDry            = $opts['dry'];
	 $this->optUpdateExisting = $opts['upd'];
	 $this->optCreateMissing  = $opts['mkUsr'];
   
	 $title = ($opts['dry']) ? 'Sync (TEST RUN)' : 'Sync';
	 $r = $this->buildReportHeader($cfg, $opts, $title, $emailTarget);
   
	 if (!$opts['keys']) { 
	   $r[] = 'No API keys → abort.'; 
	   $ses->set('pl_sync_report', implode("\n", $r)); 
	   return; 
	 }
	 if (!$this->ensureStripe($ses, $r)) return;
   
	 // normalize target email once
	 $target = ($emailTarget !== null) ? strtolower(trim($emailTarget)) : null;
   
	 // Falls Email-Target: User + Linked-Map einmalig vorab laden
	 $linkedSet = [];
	 if ($target) {
	   $userForTarget = $this->findUserByEmailCached($emailTarget);
	   if ($userForTarget) {
		 $linkedSet = $this->linkedMapForUser($userForTarget); // sessionId => purchaseId
	   }
	 }
   
	 $matched = 0; 
	 $scanned = 0; 
	 $total   = 0;
   
	 foreach ($opts['keys'] as $key) {
	   try {
		 $all = $this->fetchSessionsForKey($key, $opts['from'], $opts['to']);
		 $scanned += count($all);
		 $total   += count($all);
   
		 foreach ($all as $s) {
		   if ($target !== null && !$this->sessionMatchesEmail($s, $target)) continue;
		   if ($target !== null) $matched++;
		   $this->reportSessionRow($s, $r, $key, $linkedSet);
		 }
	   } catch (\Throwable $e) {
		 $r[] = '  [Stripe error] ' . $e->getMessage();
		 $log->save(\ProcessWire\StripePaymentLinks::LOG_PL, '[SYNC core] stripe error: ' . $e->getMessage());
	   }
	 }
   
	 if ($target !== null) {
	   $r[] = '';
	   $r[] = 'Total scanned: ' . $scanned;
	   $r[] = 'Total matched for ' . $emailTarget . ': ' . $matched;
	 } else {
	   $r[] = 'Total sessions found (all pages): ' . $total;
	 }
   
	 $__totalMs = $this->ms($__tAll);
	 $r[] = 'Total duration: ' . $this->fmtDuration($__totalMs);
	 $r[] = $this->optDry ? 'Done (read-only).' : 'Done (writes applied when needed).';
   
	 $ses->set('pl_sync_report', implode("\n", $r));
   }
      
  /**
   * Backward-compatible wrapper: full sync (no email filter).
   */
  public function runSyncFromConfig(array $cfg): void {
	// Respect pl_sync_email if present, but delegieren zentral
	$emailTarget = trim((string)($cfg['pl_sync_email'] ?? ''));
	$this->runSync($cfg, $emailTarget !== '' ? $emailTarget : null);
  }

  /**
   * Backward-compatible wrapper: email-targeted sync.
   */
  public function runSyncForEmail(array $cfg, string $targetEmail): void {
	$this->runSync($cfg, $targetEmail);
  }
  	
 
}