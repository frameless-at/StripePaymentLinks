<?php namespace ProcessWire;

trait PLPurchaseLineHelper {

  /** Stripe-Produkt-ID aus array-basiertem line_item extrahieren (robust). */
  private function arrayStripeProductId(array $li): string {
	$pp = $li['price']['product'] ?? null;
	if (is_array($pp) && !empty($pp['id'])) return (string)$pp['id'];
	if (is_string($pp) && $pp !== '')      return $pp;
	return '';
  }

  private function pidForArrayLineItem(array $li): int {
	  $stripePid = $this->arrayStripeProductId($li);
	  if ($stripePid === '') return 0;
  
	  $pages = $this->wire('pages');
	  $p = $pages->get("stripe_product_id=" . $this->wire('sanitizer')->selectorValue($stripePid));
	  return ($p && $p->id) ? (int)$p->id : 0;
  }

  /** Scope-Key pro Zeile:
   *  - gemappt:   "<pid>"   (z.B. "1234")
   *  - ungemappt: "0#<stripe_product_id>" (z.B. "0#prod_abc123")
   */
  private function scopeKeyForArrayLineItem(array $li): string {
	$pid = $this->pidForArrayLineItem($li);
	if ($pid > 0) return (string)$pid;
	$sid = $this->arrayStripeProductId($li);
	if ($sid === '') {
	  // absoluter Sonderfall: keine Stripe-Produkt-ID vorhanden → stabiler Platzhalter pro Zeile
	  // (kommt praktisch nicht vor; trotzdem deterministisch)
	  return '0#unknown';
	}
	return '0#' . $sid;
  }

  /** GENAUE Auswertung für einen Scope-Key (kein Fallback, keine Quervererbung). */
  private function bestEndAndFlagsForScopeKey(string $scopeKey, array $map): array {
	$end = 0; $paused = false; $canceled = false;
	if (array_key_exists($scopeKey, $map) && is_numeric($map[$scopeKey])) {
	  $end = (int)$map[$scopeKey];
	}
	if (array_key_exists($scopeKey . '_paused', $map))   $paused   = true;
	if (array_key_exists($scopeKey . '_canceled', $map)) $canceled = true;
	if ($canceled) $paused = false;
	return ['end' => $end, 'paused' => $paused, 'canceled' => $canceled];
  }

  /**
   * Zeilen vollständig aus Metas erzeugen (ohne Fallback).
   * Format: "SCOPE • QTY • NAME • 12.34 EUR • <Status/Datum>"
   * Wobei SCOPE für gemappte Produkte die PID ist, für ungemappte "0#<stripe_id>".
   */
  private function buildPurchaseLinesFromMeta(\ProcessWire\Page $purchaseItem): array {
	$session = $purchaseItem->meta('stripe_session');
	if (!is_array($session)) return [];

	$periodEndMap = (array) $purchaseItem->meta('period_end_map');
	$linesIn = $session['line_items']['data'] ?? [];
	if (!is_array($linesIn) || !$linesIn) return [];

	$sessCurrency = strtoupper((string)($session['currency'] ?? 'EUR'));
	$out = [];

	foreach ($linesIn as $li) {
	  if (!is_array($li)) continue;

	  $scope = $this->scopeKeyForArrayLineItem($li);
	  $qty   = max(1, (int)($li['quantity'] ?? 1));
	  $name  = (string)($li['price']['product']['name'] ?? ($li['description'] ?? 'Item'));

	  // Betrag/Währung
	  $cents = (int)($li['amount_total'] ?? ($li['amount'] ?? 0));
	  $cur   = strtoupper((string)($li['currency'] ?? $sessCurrency));
	  $amountStr = number_format($cents / 100, 2, '.', '') . ' ' . $cur;

	  // Status für GENAU diesen Scope
	  $st = $this->bestEndAndFlagsForScopeKey($scope, $periodEndMap);
	  $suffix = '';
	  if ($st['canceled']) {
		$suffix = ' • CANCELED' . ($st['end'] ? (' (' . gmdate('Y-m-d', (int)$st['end']) . ')') : '');
	  } elseif ($st['paused']) {
		$suffix = ' • PAUSED';
	  } elseif ($st['end']) {
		$suffix = ' • ' . gmdate('Y-m-d', (int)$st['end']);
	  }

	  $out[] = $scope . ' • ' . $qty . ' • ' . $name . ' • ' . $amountStr . $suffix;
	}

	return $out;
  }

  /** Rebuild + setzen (ohne Save). */
  private function rebuildAndSetPurchaseLines(\ProcessWire\Page $purchaseItem): void {
	$rebuilt = $this->buildPurchaseLinesFromMeta($purchaseItem);
	$purchaseItem->set('purchase_lines', implode("\n", $rebuilt));
  }

  /** Session (Objekt/Array) → Array normalisieren. */
  private function plNormalizeSessionArray($session) {
	if (is_object($session) && method_exists($session, 'toArray')) $session = $session->toArray();
	if (is_object($session)) $session = (array)$session;
	return is_array($session) ? $session : [];
  }

// In trait PLPurchaseLineHelper
  private function plWriteMetasAndRebuild(
	  \ProcessWire\Page $item,
	  $session,
	  array $productIds = [],
	  ?int $effectiveEnd = null,
	  ?bool $paused = null,
	  ?bool $canceled = null
  ): void {
	  if (!$item || !$item->id) return;
	  $item->of(false);
  
	  // 1) normalize session to array
	  if (is_object($session) && method_exists($session, 'toArray')) $session = $session->toArray();
	  if (is_object($session)) $session = (array)$session;
	  $sessionArr = is_array($session) ? $session : [];
  
	  // 2) ensure product_ids (numeric; 0 for unmapped)
	  if (!$productIds) {
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
  
	  // 3) collect exact scope keys
	  $scopeKeys = [];
	  $linesIn = $sessionArr['line_items']['data'] ?? [];
	  if (is_array($linesIn)) {
		  foreach ($linesIn as $li) {
			  if (!is_array($li)) continue;
			  $scope = $this->scopeKeyForArrayLineItem($li);
			  if ($scope !== '') $scopeKeys[] = $scope;
		  }
	  }
	  $scopeKeys = array_values(array_unique($scopeKeys));
  
	  // 4) update period_end_map only for exact keys
	  $map = (array) $item->meta('period_end_map');
  
	  foreach ($scopeKeys as $k) {
		  $pKey = $k . '_paused';
		  $cKey = $k . '_canceled';
  
		  // raise end only (never shorten)
		  if ($effectiveEnd) {
			  $map[$k] = max((int)($map[$k] ?? 0), (int)$effectiveEnd);
		  }
  
		  // flags (canceled dominates paused)
		  if ($canceled === true) {
			  unset($map[$pKey]);
			  $map[$cKey] = 1;
		  } elseif ($canceled === false) {
			  unset($map[$cKey]);
			  if     ($paused === true)  { $map[$pKey] = 1; }
			  elseif ($paused === false) { unset($map[$pKey]); }
		  } else {
			  if     ($paused === true)  { $map[$pKey] = 1; }
			  elseif ($paused === false) { unset($map[$pKey]); }
		  }
	  }
  
	  $item->meta('period_end_map', $map);
  
	  // 5) rebuild lines and save
	  $rebuilt = $this->buildPurchaseLinesFromMeta($item);
	  $item->set('purchase_lines', implode("\n", $rebuilt));
	  $this->wire('pages')->save($item);
  }
  
  private function plRebuildLinesAndSave(\ProcessWire\Page $item): void {
	  if (!$item || !$item->id) return;
	  $item->of(false);
	  $rebuilt = $this->buildPurchaseLinesFromMeta($item);
	  $item->set('purchase_lines', implode("\n", $rebuilt));
	  $this->wire('pages')->save($item);
  }

}