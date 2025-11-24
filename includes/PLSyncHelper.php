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
  
  use PLPurchaseLineHelper;
	
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
  
	$u = $this->findUserByEmailCached($email);
	$linkedId = null;
	if ($u) {
	  $linkedId = $preLinkedMap ? ($preLinkedMap[$sid] ?? null) : ($this->linkedMapForUser($u)[$sid] ?? null);
	}
  
	$status = ($u && $linkedId) ? 'LINKED' : 'MISSING';
	$line  .= ' [' . $status . '] ' . $email;
  
	$client = null;
	try { $client = $this->clientFor((string)$apiKey); } catch (\Throwable $e) {}
	if (!$client || !class_exists('\Stripe\StripeClient')) {
	  $report[] = $line . ' [no Stripe client available]';
	  return;
	}
  
	if ($linkedId && !$this->optUpdateExisting) {
	  if (!$this->optDry && $u) $this->backfillPeriodEndsForUser($u, $client);
	  $report[] = $line . ' ⇒ action: LINKED (no update, backfilled)';
	  return;
	}
  
	$expanded = $this->retrieveExpandedSession($sid, $apiKey);
	if (!$expanded) { $report[] = $line . ' [expand failed]'; return; }
  
	$periodEnd = $this->resolvePeriodEndFromSessionOrApi($expanded, $client);
  
	try {
		// Collect productIds from Stripe line_items (object list), allow 0 (non-access)
		$productIds = [];
		$items = $this->g($expanded, ['line_items']);
		if (!$items) {
	  		try { $items = $this->fetchLineItemsWithClient($apiKey, $sid); }
	  		catch (\Throwable $e) { $report[] = $line . ' [items failed]'; return; }
		}
		$data = (is_object($items) && isset($items->data) && is_array($items->data)) ? $items->data : [];
		foreach ($data as $li) {
	  		$stripePid = $this->liStripeProductId($li); // ← diese object-basierte Helper-Methode BEHALTEN
	  		$mappedId  = $stripePid !== '' ? ($this->mapStripeProductToPageId($stripePid) ?? 0) : 0;
	  		$productIds[] = (int)$mappedId;
		}
		$productIds = array_values(array_unique($productIds));
		$lines = []; // BC: no longer used; we rebuild from meta later
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
		  $this->persistUpdatePurchase($u, (int)$linkedId, $sessionForPersist, $lines, $productIds, $periodEnd);
		  $this->backfillPeriodEndsForUser($u, $client);
		} catch (\Throwable $e) {}
	  }
	  $report[] = $line;
	  return;
	}
  
	// CREATE
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
		$this->persistNewPurchase($u, $sessionForPersist, $lines, $productIds, $periodEnd);
		$this->backfillPeriodEndsForUser($u, $client);
	  } catch (\Throwable $e) {}
	}
	$report[] = $line;
	foreach ($lines as $L) $report[] = '         ' . $L;
	$report[] = '';
  }  
  
  /**
   * Resolve the subscription period end timestamp for a given Stripe Checkout session.
   */
   private function resolvePeriodEndFromSessionOrApi($session, \Stripe\StripeClient $client = null): ?int {
	 if (!$client) return null;
   
	 try {
	   if (is_object($session) && is_object($session->subscription ?? null)) {
		 $sub = $session->subscription;
		 if (!empty($sub->current_period_end)) return (int) $sub->current_period_end;
		 if (!empty($sub->items->data) && is_array($sub->items->data)) {
		   foreach ($sub->items->data as $si) {
			 if (!empty($si->current_period_end)) return (int)$si->current_period_end;
		   }
		 }
	   }
	 } catch (\Throwable $e) {}
   
	 try {
	   $subId = null;
	   if (is_object($session) && is_string($session->subscription ?? null) && $session->subscription !== '') {
		 $subId = (string)$session->subscription;
	   } elseif (is_array($session) && !empty($session['subscription']) && is_string($session['subscription'])) {
		 $subId = (string)$session['subscription'];
	   }
	   if ($subId) {
		 $sub = $client->subscriptions->retrieve($subId, []);
		 if (!empty($sub->current_period_end)) return (int)$sub->current_period_end;
		 if (!empty($sub->items->data) && is_array($sub->items->data)) {
		   foreach ($sub->items->data as $si) {
			 if (!empty($si->current_period_end)) return (int)$si->current_period_end;
		   }
		 }
	   }
	 } catch (\Throwable $e) {
	   $this->wire('log')->save(StripePaymentLinks::LOG_PL, '[SYNC] resolvePeriodEnd fetch failed: '.$e->getMessage());
	 }
   
	 return null;
   } 
// In class PLSyncHelper
   private function persistNewPurchase(
	   \ProcessWire\User $u,
	   $sessionObj,
	   array $lines,            // kept for BC; unused
	   array $productIds,
	   ?int $periodEndTs = null
   ): void {
	   /** @var \ProcessWire\Users $users */
	   $users = $this->wire('users');
	   if (!$u->hasField('spl_purchases')) return;
   
	   // created timestamp
	   $createdTs = 0;
	   try {
		   if (is_object($sessionObj) && isset($sessionObj->created))      $createdTs = (int)$sessionObj->created;
		   elseif (is_array($sessionObj) && isset($sessionObj['created'])) $createdTs = (int)$sessionObj['created'];
	   } catch (\Throwable $e) {}
	   if ($createdTs <= 0) $createdTs = time();
   
	   // create repeater item
	   $u->of(false);
	   $item = $u->spl_purchases->getNew();
	   $item->set('purchase_date', $createdTs);
	   $u->spl_purchases->add($item);
	   $users->save($u, ['quiet' => true]);
   
	   // make sure we have a persisted item with an id
	   if (!$item->id) {
		   $item = $u->spl_purchases->last();
		   if (!$item || !$item->id) { $u->of(true); return; }
	   }
   
	   // extract subscription flags if present
	   $sub = null;
	   try { if (is_object($sessionObj) && is_object($sessionObj->subscription ?? null)) $sub = $sessionObj->subscription; } catch (\Throwable $e) {}
   
	   $canceled = $sub ? ((string)($sub->status ?? '') === 'canceled') : null;
	   $paused   = $sub ? ((isset($sub->pause_collection) && $sub->pause_collection !== null) ? true : false) : null;
	   if ($canceled === true) $paused = false;
   
	   $effectiveEnd = $periodEndTs ?: null;
   
	   $this->plWriteMetasAndRebuild($item, $sessionObj, $productIds, $effectiveEnd, $paused, $canceled);
	   $u->of(true);
   }
   
   private function persistUpdatePurchase(
	   \ProcessWire\User $u,
	   int $purchaseItemId,
	   $sessionObj,
	   array $lines,             // kept for BC; unused
	   array $productIds,
	   ?int $periodEndTs = null
   ): void {
	   /** @var \ProcessWire\Users $users */
	   $users = $this->wire('users');
	   if (!$u->hasField('spl_purchases')) return;
   
	   $item = $u->spl_purchases->get("id=$purchaseItemId");
	   if (!$item || !$item->id) return;
   
	   // determine created timestamp
	   $createdTs = 0;
	   try {
		   if (is_object($sessionObj) && isset($sessionObj->created))      $createdTs = (int)$sessionObj->created;
		   elseif (is_array($sessionObj) && isset($sessionObj['created'])) $createdTs = (int)$sessionObj['created'];
	   } catch (\Throwable $e) {}
	   if ($createdTs <= 0) $createdTs = time();
   
	   // subscription flags (optional)
	   $sub = null;
	   try { if (is_object($sessionObj) && is_object($sessionObj->subscription ?? null)) $sub = $sessionObj->subscription; } catch (\Throwable $e) {}
   
	   $canceled = $sub ? ((string)($sub->status ?? '') === 'canceled') : null;
	   $paused   = $sub ? ((isset($sub->pause_collection) && $sub->pause_collection !== null) ? true : false) : null;
	   if ($canceled === true) $paused = false;
   
	   $effectiveEnd = $periodEndTs ?: null;
   
	   // write
	   $u->of(false);
	   $item->of(false);
	   $item->set('purchase_date', $createdTs);
	   $users->save($u, ['quiet' => true]);
   
	   $this->plWriteMetasAndRebuild($item, $sessionObj, array_values(array_unique(array_map('intval', (array)$productIds))), $effectiveEnd, $paused, $canceled);
	   $u->of(true);
   }
   
   /**
    * Fetch all paid invoices for a subscription (excluding initial creation).
    *
    * @param string $subId Stripe Subscription ID
    * @param string $apiKey Stripe API key
    * @return array Array of invoice objects
    */
   private function fetchRenewalInvoicesForSubscription(string $subId, string $apiKey): array {
       if (!$subId) return [];

       $client = $this->clientFor($apiKey);
       $renewals = [];

       try {
           $params = [
               'subscription' => $subId,
               'status' => 'paid',
               'limit' => 100,
           ];

           $startingAfter = null;
           do {
               $pageParams = $params;
               if ($startingAfter) $pageParams['starting_after'] = $startingAfter;

               $page = $client->invoices->all($pageParams);
               $data = (is_object($page) && isset($page->data) && is_array($page->data)) ? $page->data : [];

               foreach ($data as $inv) {
                   // Skip initial subscription creation invoice
                   if (($inv->billing_reason ?? '') === 'subscription_create') continue;

                   $renewals[] = $inv;
               }

               $startingAfter = count($data) ? end($data)->id : null;
           } while (!empty($page->has_more));

       } catch (\Throwable $e) {
           $this->wire('log')->save(StripePaymentLinks::LOG_PL, '[SYNC] fetchRenewalInvoices error: ' . $e->getMessage());
       }

       return $renewals;
   }

   /**
    * Sync subscription renewals for all users with purchases.
    *
    * @param array $keys Stripe API keys to use
    * @param array &$report Report buffer
    * @return void
    */
   private function syncRenewalsForAllUsers(array $keys, array &$report, ?string $emailTarget = null): void {
       $users = $this->wire('users');
       $renewalCount = 0;
       $userCount = 0;

       $report[] = '';
       $report[] = '--- Syncing Subscription Renewals ---';

       foreach ($users->find("spl_purchases.count>0") as $u) {
           if (!$u->hasField('spl_purchases')) continue;

           // Skip users not matching email target
           $email = $u->email ?: $u->name;
           if ($emailTarget && stripos($email, $emailTarget) === false) continue;

           $userHasRenewals = false;
           $shouldDebug = (bool)$emailTarget; // Only debug when specific email is targeted

           foreach ($u->spl_purchases as $purchase) {
               $session = (array)$purchase->meta('stripe_session');
               if (!$session) continue;

               // Extract subscription ID
               $subId = null;
               $sub = $session['subscription'] ?? null;
               if (is_string($sub) && $sub !== '') {
                   $subId = $sub;
               } elseif (is_array($sub) && !empty($sub['id'])) {
                   $subId = (string)$sub['id'];
               }

               // Debug: Log subscription extraction (only for targeted email)
               if ($shouldDebug) {
                   $this->wire('log')->save(StripePaymentLinks::LOG_PL, "[SYNC DEBUG] User={$email} purchase={$purchase->id} subscription_type=" . gettype($sub) . " subId=" . ($subId ?: 'null'));
               }

               if (!$subId) continue;

               // Try each key until we find invoices
               $invoices = [];
               foreach ($keys as $key) {
                   $invoices = $this->fetchRenewalInvoicesForSubscription($subId, $key);
                   if ($invoices) break;
               }

               // Debug: Log invoice fetch results (only for targeted email)
               if ($shouldDebug) {
                   $this->wire('log')->save(StripePaymentLinks::LOG_PL, "[SYNC DEBUG] subId={$subId} invoices_found=" . count($invoices));
               }

               if (!$invoices) continue;

               // Build renewals structure
               $renewals = (array)$purchase->meta('renewals');
               $changed = false;

               foreach ($invoices as $inv) {
                   $invoiceId = (string)($inv->id ?? '');

                   // Process each line item - lines is a Stripe collection
                   $linesObj = $inv->lines ?? null;
                   $lines = [];
                   if ($linesObj && isset($linesObj->data)) {
                       $lines = is_array($linesObj->data) ? $linesObj->data : iterator_to_array($linesObj->data);
                   }

                   if ($shouldDebug) {
                       $this->wire('log')->save(StripePaymentLinks::LOG_PL, "[SYNC DEBUG] Invoice={$invoiceId} lines_count=" . count($lines) . " lines_type=" . gettype($linesObj->data ?? null));
                   }

                   foreach ($lines as $idx => $line) {
                       // Debug: dump raw line data
                       if ($shouldDebug) {
                           $rawData = is_object($line) ? json_encode($line) : json_encode($line);
                           $this->wire('log')->save(StripePaymentLinks::LOG_PL, "[SYNC DEBUG] Line[$idx] RAW: " . substr($rawData, 0, 800));
                       }

                       $stripeProductId = '';

                       // Handle both object and array structures
                       if (is_object($line)) {
                           // New API: pricing.price_details.product
                           if (isset($line->pricing->price_details->product)) {
                               $prod = $line->pricing->price_details->product;
                               $stripeProductId = is_object($prod) ? (string)($prod->id ?? '') : (string)$prod;
                           // Old API: price.product
                           } elseif (isset($line->price->product)) {
                               $prod = $line->price->product;
                               $stripeProductId = is_object($prod) ? (string)($prod->id ?? '') : (string)$prod;
                           } elseif (isset($line->plan->product)) {
                               $prod = $line->plan->product;
                               $stripeProductId = is_object($prod) ? (string)($prod->id ?? '') : (string)$prod;
                           }
                       } elseif (is_array($line)) {
                           // New API: pricing.price_details.product
                           if (isset($line['pricing']['price_details']['product'])) {
                               $prod = $line['pricing']['price_details']['product'];
                               $stripeProductId = is_array($prod) ? ($prod['id'] ?? '') : (string)$prod;
                           // Old API: price.product
                           } elseif (isset($line['price']['product'])) {
                               $prod = $line['price']['product'];
                               $stripeProductId = is_array($prod) ? ($prod['id'] ?? '') : (string)$prod;
                           } elseif (isset($line['plan']['product'])) {
                               $prod = $line['plan']['product'];
                               $stripeProductId = is_array($prod) ? ($prod['id'] ?? '') : (string)$prod;
                           }
                       }

                       if ($shouldDebug) {
                           $this->wire('log')->save(StripePaymentLinks::LOG_PL, "[SYNC DEBUG] Line[$idx] product=" . ($stripeProductId ?: 'EMPTY'));
                       }

                       if (!$stripeProductId) continue;

                       // Build scope key
                       $mappedId = $this->mapStripeProductToPageId($stripeProductId);
                       $scopeKey = $mappedId ? (string)$mappedId : ('0#' . $stripeProductId);

                       if ($shouldDebug) {
                           $this->wire('log')->save(StripePaymentLinks::LOG_PL, "[SYNC DEBUG] scopeKey={$scopeKey} mappedId=" . ($mappedId ?: 'null') . " invoice={$invoiceId}");
                       }

                       // Check if this invoice already exists for this scope
                       if (!isset($renewals[$scopeKey])) $renewals[$scopeKey] = [];

                       $exists = false;
                       foreach ($renewals[$scopeKey] as $existing) {
                           if (($existing['invoice'] ?? '') === $invoiceId) {
                               $exists = true;
                               break;
                           }
                       }

                       if (!$exists) {
                           $renewals[$scopeKey][] = [
                               'date'    => (int)($inv->created ?? time()),
                               'amount'  => (int)($line->amount ?? 0),
                               'invoice' => $invoiceId,
                               'sub'     => $subId,
                           ];
                           $changed = true;
                           $renewalCount++;
                           if ($shouldDebug) {
                               $this->wire('log')->save(StripePaymentLinks::LOG_PL, "[SYNC DEBUG] ADDED renewal for scopeKey={$scopeKey} invoice={$invoiceId}");
                           }
                       } else {
                           if ($shouldDebug) {
                               $this->wire('log')->save(StripePaymentLinks::LOG_PL, "[SYNC DEBUG] SKIPPED (exists) scopeKey={$scopeKey} invoice={$invoiceId}");
                           }
                       }
                   }
               }

               // Save if changed
               if ($changed && !$this->optDry) {
                   $purchase->of(false);
                   $purchase->meta('renewals', $renewals);
                   $purchase->save(['quiet' => true]);
                   $userHasRenewals = true;
               } elseif ($changed) {
                   $userHasRenewals = true;
               }
           }

           if ($userHasRenewals) {
               $userCount++;
               $email = $u->email ?: $u->name;
               $report[] = "  {$email}: renewals synced";
           }
       }

       $report[] = "Renewals found: {$renewalCount} (across {$userCount} users)";
   }

   private function backfillPeriodEndsForUser(\ProcessWire\User $u, \Stripe\StripeClient $client): void {
	   if (!$u->hasField('spl_purchases') || !$u->spl_purchases->count()) return;
   
	   foreach ($u->spl_purchases as $p) {
		   if (!$p || !$p->id) continue;
		   $p->of(false);
   
		   try {
			   $sess = (array) $p->meta('stripe_session');
			   if (!$sess) continue;
   
			   // build scope keys from line_items
			   $linesIn = $sess['line_items']['data'] ?? [];
			   if (!is_array($linesIn) || !$linesIn) continue;
   
			   $scopeKeys = [];
			   foreach ($linesIn as $li) {
				   if (!is_array($li)) continue;
				   $pp  = $li['price']['product'] ?? null;
				   $sid = (is_array($pp) && !empty($pp['id'])) ? (string)$pp['id'] : ((is_string($pp) && $pp !== '') ? $pp : '');
				   $pidMapped = 0;
				   if ($sid !== '') {
					   $pwp = $this->mapStripeProductToPageId($sid);
					   $pidMapped = $pwp ? (int)$pwp : 0;
				   }
				   $scope = $pidMapped > 0 ? (string)$pidMapped : ('0#' . ($sid !== '' ? $sid : 'unknown'));
				   $scopeKeys[] = $scope;
			   }
			   $scopeKeys = array_values(array_unique($scopeKeys));
   
			   // subscription object (from session or retrieved)
			   $sessionObj = (object)$sess;
			   $subObj = null; $subId = null;
			   if (is_object($sessionObj->subscription ?? null)) {
				   $subObj = $sessionObj->subscription;
				   $subId  = (string)($subObj->id ?? '');
			   } elseif (!empty($sess['subscription']) && is_string($sess['subscription'])) {
				   $subId = (string)$sess['subscription'];
			   }
			   if (!$subObj && $subId) {
				   try { $subObj = $client->subscriptions->retrieve($subId, []); } catch (\Throwable $fe) {}
			   }
   
			   // derive flags/timestamps
			   $canceled = false;
			   $paused   = false;
			   $cap      = false;
			   $now      = time();
   
			   $currentEnd = null;
			   $cancelAt   = null;
			   $endedAt    = null;
   
			   if ($subObj) {
				   $canceled = ((string)($subObj->status ?? '')) === 'canceled';
				   $paused   = !$canceled && (isset($subObj->pause_collection) && $subObj->pause_collection !== null);
				   $cap      = (bool)($subObj->cancel_at_period_end ?? false);
   
				   $currentEnd = is_numeric($subObj->current_period_end ?? null) ? (int)$subObj->current_period_end : null;
				   $cancelAt   = is_numeric($subObj->cancel_at ?? null)          ? (int)$subObj->cancel_at          : null;
				   $endedAt    = is_numeric($subObj->ended_at ?? null)           ? (int)$subObj->ended_at           : null;
			   } else {
				   try { $currentEnd = $this->resolvePeriodEndFromSessionOrApi($sessionObj, $client); } catch (\Throwable $e) {}
			   }
   
			   if ($currentEnd === null && !$subObj) continue;
   
			   $map     = (array) $p->meta('period_end_map');
			   $changed = false;
   
			   foreach ($scopeKeys as $k) {
				   $pKey = $k . '_paused';
				   $cKey = $k . '_canceled';
   
				   // flags
				   if ($canceled) {
					   if (!isset($map[$cKey])) { $map[$cKey] = 1; $changed = true; }
					   if (isset($map[$pKey]))  { unset($map[$pKey]); $changed = true; }
				   } else {
					   if ($paused) { if (!isset($map[$pKey])) { $map[$pKey] = 1; $changed = true; } }
					   else         { if (isset($map[$pKey]))  { unset($map[$pKey]); $changed = true; } }
					   if (isset($map[$cKey])) { unset($map[$cKey]); $changed = true; }
				   }
   
				   // raise end only
				   $existing  = isset($map[$k]) && is_numeric($map[$k]) ? (int)$map[$k] : 0;
				   $targetEnd = $existing;
   
				   if ($canceled) {
					   $end = $endedAt ?: ($cancelAt ?: ($currentEnd ?: $now));
					   if ($end > $targetEnd) $targetEnd = $end;
				   } elseif ($cap && $currentEnd) {
					   if ($currentEnd > $targetEnd) $targetEnd = $currentEnd;
				   } elseif ($currentEnd) {
					   if ($currentEnd > $targetEnd) $targetEnd = $currentEnd;
				   }
   
				   if ($targetEnd !== $existing && $targetEnd > 0) {
					   $map[$k] = $targetEnd;
					   $changed = true;
				   }
			   }
   
			   if ($changed) {
				   $p->meta('period_end_map', $map);
				   $this->plRebuildLinesAndSave($p);
			   }
		   } catch (\Throwable $e) {
			   // swallow per-item and continue
		   }
	   }
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
     	'expand' => ['line_items', 'line_items.data.price.product', 'customer', 'subscription'],
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
   
	 // Sync subscription renewals
	 $this->syncRenewalsForAllUsers($opts['keys'], $r, $emailTarget);

	 $__totalMs = $this->ms($__tAll);
	 $r[] = '';
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