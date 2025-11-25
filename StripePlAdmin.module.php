<?php namespace ProcessWire;

/**
 * ProcessStripePlAdmin
 *
 * Admin page for viewing customer purchases with configurable columns.
 * Displays purchase metadata including Stripe session data.
 */
class ProcessStripePlAdmin extends Process implements ConfigurableModule {

	public static function getModuleInfo(): array {
		return [
			'title'       => 'Stripe PL Admin',
			'version'     => '1.0.0',
			'summary'     => 'View customer purchases with configurable metadata columns.',
			'author'      => 'frameless Media',
			'icon'        => 'table',
			'requires'    => ['StripePaymentLinks'],
			'page'        => [
				'name'   => 'stripe-pl-admin',
				'parent' => 'setup',
				'title'  => 'Stripe Purchases',
			],
		];
	}

	/**
	 * Available column definitions with meta path and label
	 */
	protected array $availableColumns = [
		// Basic info
		'user_email'        => ['label' => 'User Email', 'type' => 'user'],
		'user_name'         => ['label' => 'User Name', 'type' => 'user'],
		'purchase_date'     => ['label' => 'Purchase Date', 'type' => 'field'],
		'purchase_lines'    => ['label' => 'Purchase Lines', 'type' => 'field'],

		// Stripe session meta
		'session_id'        => ['label' => 'Session ID', 'path' => ['stripe_session', 'id']],
		'customer_id'       => ['label' => 'Customer ID', 'path' => ['stripe_session', 'customer', 'id']],
		'customer_email'    => ['label' => 'Customer Email', 'path' => ['stripe_session', 'customer_email']],
		'customer_name'     => ['label' => 'Customer Name', 'path' => ['stripe_session', 'customer', 'name']],
		'payment_status'    => ['label' => 'Payment Status', 'path' => ['stripe_session', 'payment_status']],
		'currency'          => ['label' => 'Currency', 'path' => ['stripe_session', 'currency']],
		'amount_total'      => ['label' => 'Amount Total', 'type' => 'computed', 'compute' => 'computeAmountTotal'],
		'subscription_id'   => ['label' => 'Subscription ID', 'path' => ['stripe_session', 'subscription']],
		'shipping_name'     => ['label' => 'Shipping Name', 'path' => ['stripe_session', 'shipping', 'name']],
		'shipping_address'  => ['label' => 'Shipping Address', 'type' => 'computed', 'compute' => 'computeShippingAddress'],

		// Product mapping
		'product_ids'       => ['label' => 'Product IDs', 'type' => 'meta_array', 'key' => 'product_ids'],
		'product_titles'    => ['label' => 'Product Titles', 'type' => 'computed', 'compute' => 'computeProductTitles'],

		// Subscription status
		'subscription_status' => ['label' => 'Subscription Status', 'type' => 'computed', 'compute' => 'computeSubscriptionStatus'],
		'period_end'        => ['label' => 'Period End', 'type' => 'computed', 'compute' => 'computePeriodEnd'],

		// Line items detail
		'line_items_count'  => ['label' => 'Items Count', 'type' => 'computed', 'compute' => 'computeLineItemsCount'],

		// Renewals
		'renewal_count'     => ['label' => 'Renewal Count', 'type' => 'computed', 'compute' => 'computeRenewalCount'],
		'last_renewal'      => ['label' => 'Last Renewal', 'type' => 'computed', 'compute' => 'computeLastRenewal'],
	];

	/**
	 * Default columns to show
	 */
	public static function getDefaults(): array {
		return [
			'purchasesColumns' => ['user_email', 'purchase_date', 'product_titles', 'amount_total', 'payment_status'],
			'productsColumns' => ['name', 'purchases', 'quantity', 'revenue', 'last_purchase'],
			'itemsPerPage' => 25,
		];
	}

	/**
	 * Available columns for Products tab
	 */
	protected array $availableProductsColumns = [
		'name'            => ['label' => 'Product Name'],
		'purchases'       => ['label' => 'Purchases'],
		'quantity'        => ['label' => 'Quantity'],
		'revenue'         => ['label' => 'Revenue'],
		'last_purchase'   => ['label' => 'Last Purchase'],
		'renewals'        => ['label' => 'Renewals'],
		'page_id'         => ['label' => 'Page ID'],
		'stripe_id'       => ['label' => 'Stripe Product ID'],
	];

	/**
	 * Module configuration
	 */
	public static function getModuleConfigInputfields(array $data): InputfieldWrapper {
		$modules = wire('modules');
		$wrapper = new InputfieldWrapper();

		$defaults = self::getDefaults();
		$data = array_merge($defaults, $data);

		// Purchases Tab
		$tab1 = $modules->get('InputfieldFieldset');
		$tab1->label = 'Purchases';
		$tab1->collapsed = Inputfield::collapsedNo;

		$f = $modules->get('InputfieldAsmSelect');
		$f->name = 'purchasesColumns';
		$f->label = 'Columns for Purchases tab';
		$f->description = 'Select and order the columns to show in the purchases table.';

		$instance = new self();
		foreach ($instance->availableColumns as $key => $col) {
			$f->addOption($key, $col['label']);
		}
		$f->value = $data['purchasesColumns'] ?? $data['adminColumns'] ?? [];
		$tab1->add($f);

		$wrapper->add($tab1);

		// Products Tab
		$tab2 = $modules->get('InputfieldFieldset');
		$tab2->label = 'Products';
		$tab2->collapsed = Inputfield::collapsedNo;

		$f = $modules->get('InputfieldAsmSelect');
		$f->name = 'productsColumns';
		$f->label = 'Columns for Products tab';
		$f->description = 'Select and order the columns to show in the products table.';

		foreach ($instance->availableProductsColumns as $key => $col) {
			$f->addOption($key, $col['label']);
		}
		$f->value = $data['productsColumns'] ?? [];
		$tab2->add($f);

		$wrapper->add($tab2);

		// General settings
		$tab3 = $modules->get('InputfieldFieldset');
		$tab3->label = 'General';
		$tab3->collapsed = Inputfield::collapsedNo;

		$f = $modules->get('InputfieldInteger');
		$f->name = 'itemsPerPage';
		$f->label = 'Items per page';
		$f->value = $data['itemsPerPage'];
		$f->max = 500;
		$tab3->add($f);

		$wrapper->add($tab3);

		return $wrapper;
	}

	/**
	 * Main execute method - renders the purchases table
	 */
	public function ___execute(): string {
		$this->headline('Customer Purchases');
		$this->browserTitle('Purchases');

		// Tab navigation
		$out = $this->renderTabs('purchases');

		$input = $this->wire('input');
		$users = $this->wire('users');
		$sanitizer = $this->wire('sanitizer');

		// Get config
		$columns = $this->purchasesColumns ?: self::getDefaults()['purchasesColumns'];
		$perPage = (int)($this->itemsPerPage ?: 25);

		// Filters
		$filterEmail = $sanitizer->email($input->get('filter_email'));
		$filterProduct = (int)$input->get('filter_product');
		$filterDateFrom = $sanitizer->text($input->get('filter_from'));
		$filterDateTo = $sanitizer->text($input->get('filter_to'));

		// Build filter form
		$out .= $this->renderFilterForm($filterEmail, $filterProduct, $filterDateFrom, $filterDateTo);

		// Collect all purchases
		$allPurchases = [];

		/** @var User $user */
		foreach ($users->find("spl_purchases.count>0") as $user) {
			if ($filterEmail && strtolower($user->email) !== strtolower($filterEmail)) {
				continue;
			}

			foreach ($user->spl_purchases as $item) {
				$purchaseDate = (int)$item->get('purchase_date');

				// Date filters
				if ($filterDateFrom) {
					$fromTs = strtotime($filterDateFrom);
					if ($fromTs && $purchaseDate < $fromTs) continue;
				}
				if ($filterDateTo) {
					$toTs = strtotime($filterDateTo . ' 23:59:59');
					if ($toTs && $purchaseDate > $toTs) continue;
				}

				// Product filter
				if ($filterProduct) {
					$productIds = (array)$item->meta('product_ids');
					if (!in_array($filterProduct, array_map('intval', $productIds))) {
						continue;
					}
				}

				$allPurchases[] = [
					'user' => $user,
					'item' => $item,
					'date' => $purchaseDate,
				];
			}
		}

		// Sort by date descending
		usort($allPurchases, fn($a, $b) => $b['date'] <=> $a['date']);

		// Pagination
		$total = count($allPurchases);
		$page = max(1, (int)$input->get('pg'));
		$offset = ($page - 1) * $perPage;
		$paginated = array_slice($allPurchases, $offset, $perPage);

		// Render table
		$out .= $this->renderTable($paginated, $columns);

		// Pagination and Export in same row
		$out .= $this->renderPaginationRow($total, $perPage, $page);

		return $out;
	}

	/**
	 * Export to CSV
	 */
	public function ___executeExport(): void {
		$input = $this->wire('input');
		$users = $this->wire('users');
		$sanitizer = $this->wire('sanitizer');

		$columns = $this->purchasesColumns ?: self::getDefaults()['purchasesColumns'];

		// Filters
		$filterEmail = $sanitizer->email($input->get('filter_email'));
		$filterProduct = (int)$input->get('filter_product');
		$filterDateFrom = $sanitizer->text($input->get('filter_from'));
		$filterDateTo = $sanitizer->text($input->get('filter_to'));

		// Collect purchases
		$allPurchases = [];
		foreach ($users->find("spl_purchases.count>0") as $user) {
			if ($filterEmail && strtolower($user->email) !== strtolower($filterEmail)) continue;

			foreach ($user->spl_purchases as $item) {
				$purchaseDate = (int)$item->get('purchase_date');

				if ($filterDateFrom && ($ts = strtotime($filterDateFrom)) && $purchaseDate < $ts) continue;
				if ($filterDateTo && ($ts = strtotime($filterDateTo . ' 23:59:59')) && $purchaseDate > $ts) continue;

				if ($filterProduct) {
					$productIds = (array)$item->meta('product_ids');
					if (!in_array($filterProduct, array_map('intval', $productIds))) continue;
				}

				$allPurchases[] = ['user' => $user, 'item' => $item, 'date' => $purchaseDate];
			}
		}

		usort($allPurchases, fn($a, $b) => $b['date'] <=> $a['date']);

		// Output CSV
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="purchases-' . date('Y-m-d-His') . '.csv"');

		$fp = fopen('php://output', 'w');

		// Header row
		$headers = [];
		foreach ($columns as $col) {
			$headers[] = $this->availableColumns[$col]['label'] ?? $col;
		}
		fputcsv($fp, $headers);

		// Data rows
		foreach ($allPurchases as $purchase) {
			$row = [];
			foreach ($columns as $col) {
				$row[] = $this->getColumnValue($purchase['user'], $purchase['item'], $col);
			}
			fputcsv($fp, $row);
		}

		fclose($fp);
		exit;
	}

	/**
	 * Render filter form
	 */
	protected function renderFilterForm(string $email, int $product, string $from, string $to): string {
		$pages = $this->wire('pages');
		$modules = $this->wire('modules');

		// Build form using ProcessWire Inputfields for consistent styling
		/** @var InputfieldForm $form */
		$form = $modules->get('InputfieldForm');
		$form->method = 'get';
		$form->action = './';

		// Email filter
		/** @var InputfieldEmail $f */
		$f = $modules->get('InputfieldEmail');
		$f->name = 'filter_email';
		$f->label = 'Email';
		$f->columnWidth = 25;
		$f->value = $email;
		$f->collapsed = Inputfield::collapsedNever;
		$form->add($f);

		// Product filter
		$mainModule = $modules->get('StripePaymentLinks');
		$tplNames = (array)($mainModule->productTemplateNames ?? []);

		/** @var InputfieldSelect $f */
		$f = $modules->get('InputfieldSelect');
		$f->name = 'filter_product';
		$f->label = 'Product';
		$f->columnWidth = 25;
		$f->addOption('', 'All Products');

		if (!empty($tplNames)) {
			$tplSelector = 'template=' . implode('|', array_map('trim', $tplNames));
			$products = $pages->find("{$tplSelector}, sort=title, include=all");
			foreach ($products as $p) {
				$f->addOption($p->id, $p->title);
			}
		}
		$f->value = $product ?: '';
		$form->add($f);

		// From date
		/** @var InputfieldText $f */
		$f = $modules->get('InputfieldText');
		$f->name = 'filter_from';
		$f->label = 'From';
		$f->attr('type', 'date');
		$f->columnWidth = 15;
		$f->value = $from;
		$form->add($f);

		// To date
		/** @var InputfieldText $f */
		$f = $modules->get('InputfieldText');
		$f->name = 'filter_to';
		$f->label = 'To';
		$f->attr('type', 'date');
		$f->columnWidth = 15;
		$f->value = $to;
		$form->add($f);

		// Buttons wrapper
		/** @var InputfieldMarkup $f */
		$f = $modules->get('InputfieldMarkup');
		$f->name = 'buttons';
		$f->label = ' ';
		$f->columnWidth = 20;
		$f->value = "<button type='submit' class='ui-button ui-state-default'><span class='ui-button-text'>Filter</span></button> ";
		$f->value .= "<a href='{$this->page->url}' class='ui-button ui-state-default ui-priority-secondary'><span class='ui-button-text'>Reset</span></a>";
		$form->add($f);

		return $form->render();
	}

	/**
	 * Render the purchases table
	 */
	protected function renderTable(array $purchases, array $columns): string {
		if (empty($purchases)) {
			return "<p>No purchases found.</p>";
		}

		$table = $this->modules->get('MarkupAdminDataTable');
		$table->setEncodeEntities(false);
		$table->setSortable(true);

		// Header
		$headerRow = [];
		foreach ($columns as $col) {
			$headerRow[] = $this->availableColumns[$col]['label'] ?? $col;
		}
		$table->headerRow($headerRow);

		// Rows
		foreach ($purchases as $purchase) {
			$row = [];
			foreach ($columns as $col) {
				// Handle amount_total directly to avoid escaping issues
				if ($col === 'amount_total') {
					$session = (array)$purchase['item']->meta('stripe_session');
					$lineItems = $session['line_items']['data'] ?? [];
					$total = 0;
					$currency = '';
					foreach ($lineItems as $li) {
						$total += (int)($li['amount_total'] ?? 0);
						if (!$currency) $currency = strtoupper($li['currency'] ?? $session['currency'] ?? '');
					}
					// Add renewal amounts
					$renewals = (array)$purchase['item']->meta('renewals');
					foreach ($renewals as $scopeRenewals) {
						foreach ((array)$scopeRenewals as $renewal) {
							$total += (int)($renewal['amount'] ?? 0);
						}
					}
					$row[] = $total > 0 ? $this->formatPrice($total, $currency) : '';
				} else {
					$row[] = $this->getColumnValue($purchase['user'], $purchase['item'], $col);
				}
			}
			$table->row($row);
		}

		return $table->render();
	}

	/**
	 * Get value for a specific column
	 */
	protected function getColumnValue(User $user, Page $item, string $column): string {
		$colDef = $this->availableColumns[$column] ?? null;
		if (!$colDef) return '';

		$type = $colDef['type'] ?? 'meta_path';

		switch ($type) {
			case 'user':
				if ($column === 'user_email') return (string)$user->email;
				if ($column === 'user_name') return (string)$user->title;
				break;

			case 'field':
				$val = $item->get($column);
				if ($column === 'purchase_date' && is_numeric($val)) {
					return date('Y-m-d H:i', (int)$val);
				}
				if ($column === 'purchase_lines') {
					return str_replace("\n", " | ", trim((string)$val));
				}
				return (string)$val;

			case 'meta_array':
				$key = $colDef['key'] ?? $column;
				$arr = (array)$item->meta($key);
				return implode(', ', $arr);

			case 'computed':
				$method = $colDef['compute'] ?? '';
				if ($method && method_exists($this, $method)) {
					return $this->$method($user, $item);
				}
				break;

			default:
				// meta_path
				if (isset($colDef['path'])) {
					return $this->getMetaPath($item, $colDef['path']);
				}
		}

		return '';
	}

	/**
	 * Get nested meta value by path
	 */
	protected function getMetaPath(Page $item, array $path): string {
		if (empty($path)) return '';

		$key = array_shift($path);
		$data = $item->meta($key);

		if ($data === null) return '';

		foreach ($path as $k) {
			if (is_array($data) && isset($data[$k])) {
				$data = $data[$k];
			} elseif (is_object($data) && isset($data->$k)) {
				$data = $data->$k;
			} else {
				return '';
			}
		}

		if (is_array($data) || is_object($data)) {
			return json_encode($data);
		}

		return (string)$data;
	}

	/**
	 * Compute total amount from line items
	 */
	protected function computeAmountTotal(User $user, Page $item): string {
		$session = (array)$item->meta('stripe_session');
		$lineItems = $session['line_items']['data'] ?? [];

		$total = 0;
		$currency = '';
		foreach ($lineItems as $li) {
			$total += (int)($li['amount_total'] ?? 0);
			if (!$currency) $currency = strtoupper($li['currency'] ?? $session['currency'] ?? '');
		}

		if ($total === 0) return '';

		return $this->formatPrice($total, $currency);
	}

	/**
	 * Format price for display
	 * Format: € 3409,00 (EUR) or $ 3409.00 (USD) or CURRENCY 3409.00
	 */
	protected function formatPrice(int $cents, string $currency): string {
		$amount = $cents / 100;

		if ($currency === 'EUR') {
			return '€ ' . number_format($amount, 2, ',', '');
		} elseif ($currency === 'USD') {
			return '$ ' . number_format($amount, 2, '.', '');
		} else {
			return $currency . ' ' . number_format($amount, 2, '.', '');
		}
	}

	/**
	 * Compute shipping address
	 */
	protected function computeShippingAddress(User $user, Page $item): string {
		$session = (array)$item->meta('stripe_session');
		$shipping = $session['shipping']['address'] ?? [];

		if (empty($shipping)) return '';

		$parts = array_filter([
			$shipping['line1'] ?? '',
			$shipping['line2'] ?? '',
			$shipping['postal_code'] ?? '',
			$shipping['city'] ?? '',
			$shipping['country'] ?? '',
		]);

		return implode(', ', $parts);
	}

	/**
	 * Compute product titles from IDs and stripe session line items
	 */
	protected function computeProductTitles(User $user, Page $item): string {
		$pages = $this->wire('pages');
		$productIds = (array)$item->meta('product_ids');
		$session = (array)$item->meta('stripe_session');
		$lineItems = $session['line_items']['data'] ?? [];

		$titles = [];
		$mappedStripeIds = [];

		// First get titles from mapped product IDs
		foreach ($productIds as $pid) {
			$pid = (int)$pid;
			if ($pid === 0) continue;
			$p = $pages->get($pid);
			if ($p && $p->id) {
				$titles[] = $p->title;
				// Track the stripe_product_id if available
				if ($p->hasField('stripe_product_id') && $p->stripe_product_id) {
					$mappedStripeIds[] = $p->stripe_product_id;
				}
			}
		}

		// Then add unmapped products from line items
		foreach ($lineItems as $li) {
			$stripeProductId = $li['price']['product']['id'] ?? ($li['price']['product'] ?? '');
			if (is_array($stripeProductId)) $stripeProductId = $stripeProductId['id'] ?? '';

			// Skip if already mapped
			if (in_array($stripeProductId, $mappedStripeIds)) continue;

			// Get product name from line item
			$name = $li['price']['product']['name']
				?? $li['description']
				?? $li['price']['nickname']
				?? '';

			if ($name && !in_array($name, $titles)) {
				$titles[] = $name;
			}
		}

		return implode(', ', $titles);
	}

	/**
	 * Compute subscription status
	 */
	protected function computeSubscriptionStatus(User $user, Page $item): string {
		$map = (array)$item->meta('period_end_map');

		if (empty($map)) {
			return '–';
		}

		$statuses = [];
		$processedKeys = [];

		// Iterate over all keys in period_end_map
		foreach ($map as $key => $val) {
			// Skip flag keys
			if (strpos($key, '_paused') !== false || strpos($key, '_canceled') !== false) {
				continue;
			}

			$processedKeys[] = $key;
			$pausedKey = $key . '_paused';
			$canceledKey = $key . '_canceled';

			if (isset($map[$canceledKey])) {
				$statuses[] = 'canceled';
			} elseif (isset($map[$pausedKey])) {
				$statuses[] = 'paused';
			} elseif (is_numeric($val)) {
				$end = (int)$val;
				if ($end < time()) {
					$statuses[] = 'expired';
				} else {
					$statuses[] = 'active';
				}
			}
		}

		if (empty($statuses)) {
			return '–';
		}

		$statuses = array_unique($statuses);
		return implode(', ', $statuses);
	}

	/**
	 * Compute period end dates
	 */
	protected function computePeriodEnd(User $user, Page $item): string {
		$map = (array)$item->meta('period_end_map');

		$dates = [];
		foreach ($map as $key => $val) {
			// Skip flag keys
			if (strpos($key, '_paused') !== false || strpos($key, '_canceled') !== false) {
				continue;
			}
			if (is_numeric($val)) {
				$dates[] = date('Y-m-d', (int)$val);
			}
		}

		return implode(', ', array_unique($dates));
	}

	/**
	 * Compute line items count
	 */
	protected function computeLineItemsCount(User $user, Page $item): string {
		$session = (array)$item->meta('stripe_session');
		$lineItems = $session['line_items']['data'] ?? [];
		return (string)count($lineItems);
	}

	/**
	 * Compute renewal count
	 */
	protected function computeRenewalCount(User $user, Page $item): string {
		$renewals = (array)$item->meta('renewals');
		$count = 0;
		foreach ($renewals as $scopeRenewals) {
			$count += count((array)$scopeRenewals);
		}
		return $count > 0 ? (string)$count : '';
	}

	/**
	 * Compute last renewal date
	 */
	protected function computeLastRenewal(User $user, Page $item): string {
		$renewals = (array)$item->meta('renewals');
		$lastDate = 0;

		foreach ($renewals as $scopeRenewals) {
			foreach ((array)$scopeRenewals as $renewal) {
				$date = (int)($renewal['date'] ?? 0);
				if ($date > $lastDate) $lastDate = $date;
			}
		}

		return $lastDate > 0 ? date('Y-m-d', $lastDate) : '';
	}

	/**
	 * Render pagination row with pager and export button
	 */
	protected function renderPaginationRow(int $total, int $perPage, int $currentPage, string $exportAction = 'export'): string {
		$input = $this->wire('input');

		$out = "<div style='display:flex;justify-content:space-between;align-items:center;'>";

		// Left side: Pagination
		if ($total > $perPage) {
			$totalPages = (int)ceil($total / $perPage);
			$baseParams = $input->get->getArray();
			unset($baseParams['pg']);

			$out .= "<ul class='uk-pagination' style='margin-left:0;'>";

			// Previous
			if ($currentPage > 1) {
				$baseParams['pg'] = $currentPage - 1;
				$url = './?' . http_build_query($baseParams);
				$out .= "<li aria-label='Vorherige Seite' class='MarkupPagerNavPrevious'><a href='{$url}'><span><i class='fa fa-angle-left'></i></span></a></li>";
			}

			// Page numbers
			$range = 2;
			$start = max(1, $currentPage - $range);
			$end = min($totalPages, $currentPage + $range);

			// First page
			if ($start > 1) {
				$baseParams['pg'] = 1;
				$url = './?' . http_build_query($baseParams);
				$out .= "<li aria-label='Seite 1' class='MarkupPagerNavFirst'><a href='{$url}'><span>1</span></a></li>";
				if ($start > 2) {
					$out .= "<li class='MarkupPagerNavSeparator'><span>&hellip;</span></li>";
				}
			}

			// Middle pages
			for ($i = $start; $i <= $end; $i++) {
				$classes = [];
				if ($i == $currentPage) $classes[] = 'uk-active MarkupPagerNavOn';
				if ($i == 1) $classes[] = 'MarkupPagerNavFirstNum';
				if ($i == $totalPages) $classes[] = 'MarkupPagerNavLastNum';
				$classStr = implode(' ', $classes);
				$aria = ($i == $currentPage) ? " aria-current='true'" : '';

				$baseParams['pg'] = $i;
				$url = './?' . http_build_query($baseParams);
				$out .= "<li aria-label='Seite {$i}'" . ($classStr ? " class='{$classStr}'" : '') . "{$aria}><a href='{$url}'><span>{$i}</span></a></li>";
			}

			// Last page
			if ($end < $totalPages) {
				if ($end < $totalPages - 1) {
					$out .= "<li class='MarkupPagerNavSeparator'><span>&hellip;</span></li>";
				}
				$baseParams['pg'] = $totalPages;
				$url = './?' . http_build_query($baseParams);
				$out .= "<li aria-label='Seite {$totalPages}' class='MarkupPagerNavLast'><a href='{$url}'><span>{$totalPages}</span></a></li>";
			}

			// Next
			if ($currentPage < $totalPages) {
				$baseParams['pg'] = $currentPage + 1;
				$url = './?' . http_build_query($baseParams);
				$out .= "<li aria-label='Nächste Seite' class='MarkupPagerNavNext'><a href='{$url}'><span><i class='fa fa-angle-right'></i></span></a></li>";
			}

			$out .= "</ul>";
		} else {
			$out .= "<div></div>";
		}

		// Right side: Export button
		$exportParams = $input->get->getArray();
		unset($exportParams['pg']);
		$exportUrl = $this->page->url . $exportAction . '/' . (!empty($exportParams) ? '?' . http_build_query($exportParams) : '');
		$out .= "<a href='{$exportUrl}' class='uk-button uk-button uk-button-secondary'><i class='fa fa-download'></i> Export CSV</a>";

		$out .= "</div>";

		return $out;
	}

	/**
	 * Render tab navigation
	 */
	protected function renderTabs(string $active): string {
		$baseUrl = $this->page->url;
		$configUrl = $this->wire('config')->urls->admin . 'module/edit/?name=ProcessStripePlAdmin';

		$tabs = [
			'purchases' => ['url' => $baseUrl, 'label' => 'Purchases'],
			'products' => ['url' => $baseUrl . 'products/', 'label' => 'Products'],
		];

		$out = "<div style='display:flex;justify-content:space-between;align-items:center;margin-bottom:20px'>";

		$out .= "<ul class='WireTabs uk-tab' role='tablist' style='margin:0'>";
		foreach ($tabs as $key => $tab) {
			$liClass = ($key === $active) ? 'uk-active' : '';
			$aClass = ($key === $active) ? 'pw-active' : '';
			$out .= "<li class='{$liClass}' role='presentation'><a href='{$tab['url']}' class='{$aClass}' role='tab'>{$tab['label']}</a></li>";
		}
		$out .= "</ul>";

		$out .= "<a href='{$configUrl}&collapse_info=1' class='ui-link'><i class='fa fa-cog'></i> Columns</a>";

		$out .= "</div>";

		return $out;
	}

	/**
	 * Products overview - aggregated by product
	 */
	public function ___executeProducts(): string {
		$this->headline('Products Overview');
		$this->browserTitle('Products');

		$out = $this->renderTabs('products');

		$input = $this->wire('input');
		$users = $this->wire('users');
		$pages = $this->wire('pages');

		// Aggregate data per product
		$productData = [];

		foreach ($users->find("spl_purchases.count>0") as $user) {
			foreach ($user->spl_purchases as $item) {
				$session = (array)$item->meta('stripe_session');
				$lineItems = $session['line_items']['data'] ?? [];
				$productIds = (array)$item->meta('product_ids');
				$purchaseDate = (int)$item->get('purchase_date');

				// Process each line item
				foreach ($lineItems as $li) {
					$stripeProductId = $li['price']['product']['id'] ?? ($li['price']['product'] ?? '');
					if (is_array($stripeProductId)) $stripeProductId = $stripeProductId['id'] ?? '';

					$productName = $li['price']['product']['name']
						?? $li['description']
						?? $li['price']['nickname']
						?? 'Unknown';

					$amount = (int)($li['amount_total'] ?? 0);
					$currency = strtoupper($li['currency'] ?? $session['currency'] ?? 'EUR');
					$quantity = (int)($li['quantity'] ?? 1);

					// Find mapped page ID
					$pageId = 0;
					foreach ($productIds as $pid) {
						$pid = (int)$pid;
						if ($pid === 0) continue;
						$p = $pages->get($pid);
						if ($p && $p->id && $p->hasField('stripe_product_id') && $p->stripe_product_id === $stripeProductId) {
							$pageId = $pid;
							$productName = $p->title;
							break;
						}
					}

					// Use stripe product ID as key for unmapped
					$key = $pageId ?: ('stripe:' . $stripeProductId);

					if (!isset($productData[$key])) {
						$productData[$key] = [
							'name' => $productName,
							'page_id' => $pageId,
							'stripe_id' => $stripeProductId,
							'count' => 0,
							'quantity' => 0,
							'revenue' => 0,
							'currency' => $currency,
							'last_purchase' => 0,
							'renewals' => 0,
						];
					}

					$productData[$key]['count']++;
					$productData[$key]['quantity'] += $quantity;
					$productData[$key]['revenue'] += $amount;
					if ($purchaseDate > $productData[$key]['last_purchase']) {
						$productData[$key]['last_purchase'] = $purchaseDate;
					}
				}

				// Aggregate renewals - add to revenue
				$renewals = (array)$item->meta('renewals');
				foreach ($renewals as $scopeKey => $scopeRenewals) {
					// Match scope key to product key
					$renewalKey = null;
					if (is_numeric($scopeKey) && (int)$scopeKey > 0) {
						$renewalKey = (int)$scopeKey;
					} elseif (strpos($scopeKey, '0#') === 0) {
						$renewalKey = 'stripe:' . substr($scopeKey, 2);
					}

					if ($renewalKey && isset($productData[$renewalKey])) {
						foreach ((array)$scopeRenewals as $renewal) {
							$productData[$renewalKey]['renewals']++;
							$productData[$renewalKey]['revenue'] += (int)($renewal['amount'] ?? 0);
						}
					}
				}
			}
		}

		// Sort by count descending
		uasort($productData, fn($a, $b) => $b['count'] <=> $a['count']);

		// Get configured columns
		$columns = $this->productsColumns ?: self::getDefaults()['productsColumns'];
		$perPage = (int)($this->itemsPerPage ?: 25);

		// Pagination
		$total = count($productData);
		$page = max(1, (int)$input->get('pg'));
		$offset = ($page - 1) * $perPage;
		$paginatedData = array_slice($productData, $offset, $perPage, true);

		// Render table
		if (empty($productData)) {
			$out .= "<p>No products found.</p>";
		} else {
			$table = $this->modules->get('MarkupAdminDataTable');
			$table->setEncodeEntities(false);
			$table->setSortable(true);

			// Dynamic header
			$headers = [];
			foreach ($columns as $col) {
				$headers[] = $this->availableProductsColumns[$col]['label'] ?? $col;
			}
			$table->headerRow($headers);

			// Dynamic rows
			foreach ($paginatedData as $data) {
				$row = [];
				foreach ($columns as $col) {
					switch ($col) {
						case 'name':
							$name = htmlspecialchars($data['name']);
							if ($data['page_id']) {
								$editUrl = $this->wire('config')->urls->admin . "page/edit/?id={$data['page_id']}";
								$name = "<a href='{$editUrl}'>{$name}</a>";
							}
							$row[] = $name;
							break;
						case 'purchases':
							$row[] = $data['count'];
							break;
						case 'quantity':
							$row[] = $data['quantity'];
							break;
						case 'revenue':
							$row[] = $this->formatPrice($data['revenue'], $data['currency']);
							break;
						case 'last_purchase':
							$row[] = $data['last_purchase'] ? date('Y-m-d', $data['last_purchase']) : '-';
							break;
						case 'renewals':
							$row[] = $data['renewals'] ?: '-';
							break;
						case 'page_id':
							$row[] = $data['page_id'] ?: '-';
							break;
						case 'stripe_id':
							$row[] = $data['stripe_id'] ?: '-';
							break;
						default:
							$row[] = '';
					}
				}
				$table->row($row);
			}

			$out .= "<div style='margin-top:-1px'>" . $table->render() . "</div>";
		}

		// Pagination and Export
		$out .= $this->renderPaginationRow($total, $perPage, $page, 'exportProducts');

		return $out;
	}

	/**
	 * Export products to CSV
	 */
	public function ___executeExportProducts(): void {
		$users = $this->wire('users');
		$pages = $this->wire('pages');

		// Aggregate data (same as executeProducts)
		$productData = [];

		foreach ($users->find("spl_purchases.count>0") as $user) {
			foreach ($user->spl_purchases as $item) {
				$session = (array)$item->meta('stripe_session');
				$lineItems = $session['line_items']['data'] ?? [];
				$productIds = (array)$item->meta('product_ids');
				$purchaseDate = (int)$item->get('purchase_date');

				foreach ($lineItems as $li) {
					$stripeProductId = $li['price']['product']['id'] ?? ($li['price']['product'] ?? '');
					if (is_array($stripeProductId)) $stripeProductId = $stripeProductId['id'] ?? '';

					$productName = $li['price']['product']['name']
						?? $li['description']
						?? $li['price']['nickname']
						?? 'Unknown';

					$amount = (int)($li['amount_total'] ?? 0);
					$currency = strtoupper($li['currency'] ?? $session['currency'] ?? 'EUR');
					$quantity = (int)($li['quantity'] ?? 1);

					$pageId = 0;
					foreach ($productIds as $pid) {
						$pid = (int)$pid;
						if ($pid === 0) continue;
						$p = $pages->get($pid);
						if ($p && $p->id && $p->hasField('stripe_product_id') && $p->stripe_product_id === $stripeProductId) {
							$pageId = $pid;
							$productName = $p->title;
							break;
						}
					}

					$key = $pageId ?: ('stripe:' . $stripeProductId);

					if (!isset($productData[$key])) {
						$productData[$key] = [
							'name' => $productName,
							'page_id' => $pageId,
							'stripe_id' => $stripeProductId,
							'count' => 0,
							'quantity' => 0,
							'revenue' => 0,
							'currency' => $currency,
							'last_purchase' => 0,
							'renewals' => 0,
						];
					}

					$productData[$key]['count']++;
					$productData[$key]['quantity'] += $quantity;
					$productData[$key]['revenue'] += $amount;
					if ($purchaseDate > $productData[$key]['last_purchase']) {
						$productData[$key]['last_purchase'] = $purchaseDate;
					}
				}

				// Aggregate renewals - add to revenue
				$renewals = (array)$item->meta('renewals');
				foreach ($renewals as $scopeKey => $scopeRenewals) {
					$renewalKey = null;
					if (is_numeric($scopeKey) && (int)$scopeKey > 0) {
						$renewalKey = (int)$scopeKey;
					} elseif (strpos($scopeKey, '0#') === 0) {
						$renewalKey = 'stripe:' . substr($scopeKey, 2);
					}

					if ($renewalKey && isset($productData[$renewalKey])) {
						foreach ((array)$scopeRenewals as $renewal) {
							$productData[$renewalKey]['renewals']++;
							$productData[$renewalKey]['revenue'] += (int)($renewal['amount'] ?? 0);
						}
					}
				}
			}
		}

		uasort($productData, fn($a, $b) => $b['count'] <=> $a['count']);

		$columns = $this->productsColumns ?: self::getDefaults()['productsColumns'];

		// Output CSV
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="products-' . date('Y-m-d-His') . '.csv"');

		$fp = fopen('php://output', 'w');

		// Header row
		$headers = [];
		foreach ($columns as $col) {
			$headers[] = $this->availableProductsColumns[$col]['label'] ?? $col;
		}
		fputcsv($fp, $headers);

		// Data rows
		foreach ($productData as $data) {
			$row = [];
			foreach ($columns as $col) {
				switch ($col) {
					case 'name':
						$row[] = $data['name'];
						break;
					case 'purchases':
						$row[] = $data['count'];
						break;
					case 'quantity':
						$row[] = $data['quantity'];
						break;
					case 'revenue':
						$cents = $data['revenue'];
						$symbol = ($data['currency'] === 'EUR') ? '€' : $data['currency'];
						$row[] = $symbol . ' ' . number_format($cents / 100, 2, ',', '');
						break;
					case 'last_purchase':
						$row[] = $data['last_purchase'] ? date('Y-m-d', $data['last_purchase']) : '';
						break;
					case 'renewals':
						$row[] = $data['renewals'] ?: '';
						break;
					case 'page_id':
						$row[] = $data['page_id'] ?: '';
						break;
					case 'stripe_id':
						$row[] = $data['stripe_id'] ?: '';
						break;
					default:
						$row[] = '';
				}
			}
			fputcsv($fp, $row);
		}

		fclose($fp);
		exit;
	}
}
