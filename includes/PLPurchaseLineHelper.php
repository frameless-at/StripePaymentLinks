<?php namespace ProcessWire;

/**
 * Trait PLSPurchaseLineHelper
 *
 * Provides utility methods to build and manage purchase line entries
 * for StripePaymentLinks, including scope key generation, meta updates,
 * and purchase line rendering.
 */
trait PLPurchaseLineHelper {

  /**
   * Extract the Stripe product ID from an array-based line item.
   *
   * @param array $li  Stripe line item array.
   * @return string    The Stripe product ID or an empty string if not found.
   */
  private function arrayStripeProductId(array $li): string {
	$pp = $li['price']['product'] ?? null;
	if (is_array($pp) && !empty($pp['id'])) {
	  return (string) $pp['id'];
	}
	if (is_string($pp) && $pp !== '') {
	  return $pp;
	}
	return '';
  }

  /**
   * Map a Stripe line item array to a ProcessWire page ID.
   *
   * @param array $li  Stripe line item array.
   * @return int       Page ID or 0 if no mapping found.
   */
  private function pidForArrayLineItem(array $li): int {
	$stripePid = $this->arrayStripeProductId($li);
	if ($stripePid === '') {
	  return 0;
	}
	$pages = $this->wire('pages');
	$p = $pages->get("stripe_product_id=" . $this->wire('sanitizer')->selectorValue($stripePid));
	return ($p && $p->id) ? (int) $p->id : 0;
  }

  /**
   * Generate the scope key for a line item.
   *
   * Mapped products: "<pid>"
   * Unmapped products: "0#<stripe_product_id>"
   *
   * @param array $li  Stripe line item array.
   * @return string    The scope key.
   */
  private function scopeKeyForArrayLineItem(array $li): string {
	$pid = $this->pidForArrayLineItem($li);
	if ($pid > 0) {
	  return (string) $pid;
	}
	$sid = $this->arrayStripeProductId($li);
	if ($sid === '') {
	  // Edge case: no Stripe product ID → stable placeholder
	  return '0#unknown';
	}
	return '0#' . $sid;
  }

  /**
   * Evaluate the exact end date and flags for a given scope key.
   *
   * No fallback or inheritance; returns only explicit entries.
   *
   * @param string $scopeKey  The scope key.
   * @param array  $map       The period_end_map metadata.
   * @return array            ['end' => int, 'paused' => bool, 'canceled' => bool]
   */
  private function bestEndAndFlagsForScopeKey(string $scopeKey, array $map): array {
	$end = 0;
	$paused = false;
	$canceled = false;
	if (array_key_exists($scopeKey, $map) && is_numeric($map[$scopeKey])) {
	  $end = (int) $map[$scopeKey];
	}
	if (array_key_exists($scopeKey . '_paused', $map)) {
	  $paused = true;
	}
	if (array_key_exists($scopeKey . '_canceled', $map)) {
	  $canceled = true;
	}
	if ($canceled) {
	  $paused = false;
	}
	return ['end' => $end, 'paused' => $paused, 'canceled' => $canceled];
  }

  /**
   * Build purchase lines from metadata without fallback.
   *
   * Format per line:
   *   "SCOPE • QTY • NAME • AMOUNT • STATUS/DATE"
   *
   * @param \ProcessWire\Page $purchaseItem  The purchase repeater item.
   * @return string[]                         Array of formatted lines.
   */

   private function buildPurchaseLinesFromMeta(\ProcessWire\Page $purchaseItem, array $scopeOverrides = []): array {
	   $session = $purchaseItem->meta('stripe_session');
	   if (!is_array($session)) {
		 return [];
	   }
   
	   $periodEndMap = (array) $purchaseItem->meta('period_end_map');
	   $linesIn = $session['line_items']['data'] ?? [];
	   if (!is_array($linesIn) || empty($linesIn)) {
		 return [];
	   }
   
	   $sessCurrency = strtoupper((string) ($session['currency'] ?? 'EUR'));
	   $out = [];
   
	   foreach ($linesIn as $li) {
		 if (!is_array($li)) continue;
   
		 // 1) Standard-Scope ermitteln (pid oder "0#<sid>")
		 $scope = $this->scopeKeyForArrayLineItem($li);
   
		 // 2) OVERRIDE anwenden, falls für diese Stripe-Produkt-ID vorhanden
		 $sid = $this->arrayStripeProductId($li);
		 if ($sid !== '' && isset($scopeOverrides[$sid]) && (int)$scopeOverrides[$sid] > 0) {
		   $scope = (string)(int)$scopeOverrides[$sid];
		 }
   
		 $qty  = max(1, (int) ($li['quantity'] ?? 1));
		 $name = (string) ($li['price']['product']['name'] ?? ($li['description'] ?? 'Item'));
   
		 $cents = (int) ($li['amount_total'] ?? ($li['amount'] ?? 0));
		 $cur   = strtoupper((string) ($li['currency'] ?? $sessCurrency));
		 $amountStr = number_format($cents / 100, 2, '.', '') . ' ' . $cur;
   
		 $st = $this->bestEndAndFlagsForScopeKey($scope, $periodEndMap);
		 $suffix = '';
		 if ($st['canceled']) {
		   $suffix = ' • CANCELED' . ($st['end'] ? (' (' . gmdate('Y-m-d', $st['end']) . ')') : '');
		 } elseif ($st['paused']) {
		   $suffix = ' • PAUSED';
		 } elseif ($st['end']) {
		   $suffix = ' • ' . gmdate('Y-m-d', $st['end']);
		 }
   
		 $out[] = "{$scope} • {$qty} • {$name} • {$amountStr}{$suffix}";
	   }
   
	   return $out;
   }
  /**
   * Rebuild purchase_lines field without saving the page.
   *
   * @param \ProcessWire\Page $purchaseItem  The purchase repeater item.
   */
   private function rebuildAndSetPurchaseLines(\ProcessWire\Page $purchaseItem, array $scopeOverrides = []): void {
	 $rebuilt = $this->buildPurchaseLinesFromMeta($purchaseItem, $scopeOverrides);
	 $purchaseItem->set('purchase_lines', implode("\n", $rebuilt));
   }

  /**
   * Normalize session object or array into a consistent array.
   *
   * @param mixed $session  Stripe session object or array.
   * @return array          Normalized array.
   */
  private function plNormalizeSessionArray($session): array {
	if (is_object($session) && method_exists($session, 'toArray')) {
	  $session = $session->toArray();
	}
	if (is_object($session)) {
	  $session = (array) $session;
	}
	return is_array($session) ? $session : [];
  }

/**
   * Write metas (product_ids, stripe_session, period_end_map) and rebuild lines.
   *
   * @param \ProcessWire\Page $item
   * @param mixed             $session         Stripe session object/array.
   * @param int[]             $productIds      Mapped product page IDs.
   * @param int|null          $effectiveEnd    Timestamp to set as period end.
   * @param bool|null         $paused          Pause flag.
   * @param bool|null         $canceled        Cancel flag.
   * @param array             $scopeOverrides  Optional map ['<stripe_product_id>' => <pid>]
   */
  private function plWriteMetasAndRebuild(
	\ProcessWire\Page $item,
	$session,
	array $productIds = [],
	?int $effectiveEnd = null,
	?bool $paused = null,
	?bool $canceled = null,
	array $scopeOverrides = []
  ): void {
	if (!$item || !$item->id) return;
	$item->of(false);
  
	// 1) Normalize session
	if (is_object($session) && method_exists($session, 'toArray')) {
	  $session = $session->toArray();
	}
	if (is_object($session)) {
	  $session = (array) $session;
	}
	$sessionArr = is_array($session) ? $session : [];
  
	// 2) Ensure product_ids meta
	if (empty($productIds)) {
	  $linesIn = $sessionArr['line_items']['data'] ?? [];
	  if (is_array($linesIn)) {
		foreach ($linesIn as $li) {
		  if (!is_array($li)) continue;
		  $productIds[] = (int) $this->pidForArrayLineItem($li);
		}
	  }
	  $productIds = array_values(array_unique(array_map('intval', $productIds)));
	}
	$item->meta('product_ids', $productIds);
	$item->meta('stripe_session', $sessionArr);
  
	// 3) Collect scope keys and identify recurring items
	$scopeKeys = [];
	$recurringScopeKeys = []; // Track which scope keys are recurring
	$linesIn = $sessionArr['line_items']['data'] ?? [];
	$this->wire('log')->save('stripepaymentlinks', 'DEBUG plWriteMetasAndRebuild: line_items count: ' . (is_array($linesIn) ? count($linesIn) : 0));
	$this->wire('log')->save('stripepaymentlinks', 'DEBUG plWriteMetasAndRebuild: effectiveEnd param: ' . ($effectiveEnd ?: 'NULL'));
	if (is_array($linesIn)) {
	  foreach ($linesIn as $li) {
		if (!is_array($li)) continue;

		// Determine scope key
		$sid = $this->arrayStripeProductId($li);
		$priceType = (string)($li['price']['type'] ?? '');
		$productName = (string)($li['price']['product']['name'] ?? ($li['description'] ?? 'unknown'));
		$this->wire('log')->save('stripepaymentlinks', 'DEBUG plWriteMetasAndRebuild: line_item - name: ' . $productName . ', stripe_product: ' . $sid . ', price.type: ' . $priceType);

		if ($sid !== '' && isset($scopeOverrides[$sid]) && (int)$scopeOverrides[$sid] > 0) {
		  $scopeKey = (string)(int)$scopeOverrides[$sid];
		  $scopeKeys[] = $scopeKey;
		  // Check if this is a recurring price
		  if ($priceType === 'recurring') {
			$recurringScopeKeys[$scopeKey] = true;
			$this->wire('log')->save('stripepaymentlinks', 'DEBUG plWriteMetasAndRebuild: Marked as RECURRING: ' . $scopeKey);
		  } else {
			$this->wire('log')->save('stripepaymentlinks', 'DEBUG plWriteMetasAndRebuild: Marked as ONE-TIME: ' . $scopeKey);
		  }
		  continue;
		}

		$scope = $this->scopeKeyForArrayLineItem($li);
		if ($scope !== '') {
		  $scopeKeys[] = $scope;
		  // Check if this is a recurring price
		  if ($priceType === 'recurring') {
			$recurringScopeKeys[$scope] = true;
			$this->wire('log')->save('stripepaymentlinks', 'DEBUG plWriteMetasAndRebuild: Marked as RECURRING: ' . $scope);
		  } else {
			$this->wire('log')->save('stripepaymentlinks', 'DEBUG plWriteMetasAndRebuild: Marked as ONE-TIME: ' . $scope);
		  }
		}
	  }
	}
	$scopeKeys = array_values(array_unique($scopeKeys));
	$this->wire('log')->save('stripepaymentlinks', 'DEBUG plWriteMetasAndRebuild: Final scopeKeys: ' . json_encode($scopeKeys));
	$this->wire('log')->save('stripepaymentlinks', 'DEBUG plWriteMetasAndRebuild: Final recurringScopeKeys: ' . json_encode(array_keys($recurringScopeKeys)));

	// 4) Update period_end_map only for recurring scope keys
	$map = (array) $item->meta('period_end_map');
	foreach ($scopeKeys as $k) {
	  $pKey = $k . '_paused';
	  $cKey = $k . '_canceled';

	  // Raise end only for RECURRING items
	  if ($effectiveEnd && isset($recurringScopeKeys[$k])) {
		$map[$k] = max((int)($map[$k] ?? 0), $effectiveEnd);
		$this->wire('log')->save('stripepaymentlinks', 'DEBUG plWriteMetasAndRebuild: SET period_end for ' . $k . ' to ' . $effectiveEnd);
	  } elseif ($effectiveEnd && !isset($recurringScopeKeys[$k])) {
		$this->wire('log')->save('stripepaymentlinks', 'DEBUG plWriteMetasAndRebuild: SKIPPED period_end for one-time product ' . $k);
	  }
  
	  // Flags: canceled dominates paused
	  if ($canceled === true) {
		unset($map[$pKey]);
		$map[$cKey] = 1;
	  } elseif ($canceled === false) {
		unset($map[$cKey]);
		if ($paused === true) {
		  $map[$pKey] = 1;
		} elseif ($paused === false) {
		  unset($map[$pKey]);
		}
	  } else {
		if ($paused === true) {
		  $map[$pKey] = 1;
		} elseif ($paused === false) {
		  unset($map[$pKey]);
		}
	  }
	}
	$item->meta('period_end_map', $map);
  
	// 5) Rebuild purchase_lines and save (mit Overrides)
	$rebuilt = $this->buildPurchaseLinesFromMeta($item, $scopeOverrides);
	$item->set('purchase_lines', implode("\n", $rebuilt));
	$this->wire('pages')->save($item);
  }

  /**
   * Rebuild purchase_lines and save the page.
   *
   * @param \ProcessWire\Page $item
   */

   private function plRebuildLinesAndSave(\ProcessWire\Page $item, array $scopeOverrides = []): void {
	   if (!$item || !$item->id) {
		 return;
	   }
	   $item->of(false);
	   $rebuilt = $this->buildPurchaseLinesFromMeta($item, $scopeOverrides);
	   $item->set('purchase_lines', implode("\n", $rebuilt));
	   $this->wire('pages')->save($item);
   }

}