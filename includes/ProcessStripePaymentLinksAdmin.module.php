<?php namespace ProcessWire;

/**
 * ProcessStripePaymentLinksAdmin
 *
 * Admin page for viewing customer purchases with configurable columns.
 * Displays purchase metadata including Stripe session data.
 */
class ProcessStripePaymentLinksAdmin extends Process implements ConfigurableModule {

	public static function getModuleInfo(): array {
		return [
			'title'       => 'Stripe Payment Links Admin',
			'version'     => '1.0.0',
			'summary'     => 'View customer purchases with configurable metadata columns.',
			'author'      => 'frameless Media',
			'icon'        => 'table',
			'requires'    => ['StripePaymentLinks'],
			'page'        => [
				'name'   => 'stripe-purchases',
				'parent' => 'access',
				'title'  => 'Purchases',
			],
			'permission'  => 'stripe-purchases-view',
			'permissions' => [
				'stripe-purchases-view' => 'View Stripe Purchases'
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
	];

	/**
	 * Default columns to show
	 */
	public static function getDefaults(): array {
		return [
			'adminColumns' => ['user_email', 'purchase_date', 'product_titles', 'amount_total', 'payment_status'],
			'itemsPerPage' => 25,
		];
	}

	/**
	 * Module configuration
	 */
	public static function getModuleConfigInputfields(array $data): InputfieldWrapper {
		$modules = wire('modules');
		$wrapper = new InputfieldWrapper();

		$defaults = self::getDefaults();
		$data = array_merge($defaults, $data);

		// Column selection
		$f = $modules->get('InputfieldAsmSelect');
		$f->name = 'adminColumns';
		$f->label = 'Columns to display';
		$f->description = 'Select and order the columns to show in the purchases table.';

		$instance = new self();
		foreach ($instance->availableColumns as $key => $col) {
			$f->addOption($key, $col['label']);
		}
		$f->value = $data['adminColumns'];
		$wrapper->add($f);

		// Items per page
		$f = $modules->get('InputfieldInteger');
		$f->name = 'itemsPerPage';
		$f->label = 'Items per page';
		$f->value = $data['itemsPerPage'];
		$f->min = 10;
		$f->max = 500;
		$wrapper->add($f);

		return $wrapper;
	}

	/**
	 * Main execute method - renders the purchases table
	 */
	public function ___execute(): string {
		$this->headline('Customer Purchases');
		$this->browserTitle('Purchases');

		$input = $this->wire('input');
		$users = $this->wire('users');
		$sanitizer = $this->wire('sanitizer');

		// Get config
		$columns = $this->adminColumns ?: self::getDefaults()['adminColumns'];
		$perPage = (int)($this->itemsPerPage ?: 25);

		// Filters
		$filterEmail = $sanitizer->email($input->get('filter_email'));
		$filterProduct = (int)$input->get('filter_product');
		$filterDateFrom = $sanitizer->text($input->get('filter_from'));
		$filterDateTo = $sanitizer->text($input->get('filter_to'));

		// Build filter form
		$out = $this->renderFilterForm($filterEmail, $filterProduct, $filterDateFrom, $filterDateTo);

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

		// Pagination links
		if ($total > $perPage) {
			$out .= $this->renderPagination($total, $perPage, $page);
		}

		// Export link
		$exportUrl = $this->page->url . 'export/?' . http_build_query($input->get->getArray());
		$out .= "<p style='margin-top:1em;'><a href='{$exportUrl}' class='ui-button'><i class='fa fa-download'></i> Export CSV</a></p>";

		return $out;
	}

	/**
	 * Export to CSV
	 */
	public function ___executeExport(): void {
		$input = $this->wire('input');
		$users = $this->wire('users');
		$sanitizer = $this->wire('sanitizer');

		$columns = $this->adminColumns ?: self::getDefaults()['adminColumns'];

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

		$out = "<form method='get' class='InputfieldForm' style='margin-bottom:1em;'>";
		$out .= "<div style='display:flex;gap:1em;flex-wrap:wrap;align-items:end;'>";

		// Email filter
		$out .= "<div><label>Email</label><input type='email' name='filter_email' value='" . htmlspecialchars($email) . "' style='width:200px;'></div>";

		// Product filter
		$products = $pages->find('requires_access=1, sort=title');
		$out .= "<div><label>Product</label><select name='filter_product' style='width:200px;'>";
		$out .= "<option value=''>All Products</option>";
		foreach ($products as $p) {
			$sel = ($p->id === $product) ? 'selected' : '';
			$out .= "<option value='{$p->id}' {$sel}>" . htmlspecialchars($p->title) . "</option>";
		}
		$out .= "</select></div>";

		// Date range
		$out .= "<div><label>From</label><input type='date' name='filter_from' value='" . htmlspecialchars($from) . "'></div>";
		$out .= "<div><label>To</label><input type='date' name='filter_to' value='" . htmlspecialchars($to) . "'></div>";

		$out .= "<div><button type='submit' class='ui-button'>Filter</button></div>";
		$out .= "<div><a href='{$this->page->url}' class='ui-button ui-priority-secondary'>Reset</a></div>";

		$out .= "</div></form>";

		return $out;
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
				$value = $this->getColumnValue($purchase['user'], $purchase['item'], $col);
				$row[] = htmlspecialchars((string)$value);
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

		return number_format($total / 100, 2) . ' ' . $currency;
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
	 * Compute product titles from IDs
	 */
	protected function computeProductTitles(User $user, Page $item): string {
		$pages = $this->wire('pages');
		$productIds = (array)$item->meta('product_ids');

		$titles = [];
		foreach ($productIds as $pid) {
			$pid = (int)$pid;
			if ($pid === 0) continue;
			$p = $pages->get($pid);
			if ($p && $p->id) {
				$titles[] = $p->title;
			}
		}

		return implode(', ', $titles);
	}

	/**
	 * Compute subscription status
	 */
	protected function computeSubscriptionStatus(User $user, Page $item): string {
		$map = (array)$item->meta('period_end_map');
		$productIds = (array)$item->meta('product_ids');

		$statuses = [];
		foreach ($productIds as $pid) {
			$pid = (int)$pid;
			if ($pid === 0) continue;

			$key = (string)$pid;
			$pausedKey = $key . '_paused';
			$canceledKey = $key . '_canceled';

			if (isset($map[$canceledKey])) {
				$statuses[] = 'canceled';
			} elseif (isset($map[$pausedKey])) {
				$statuses[] = 'paused';
			} elseif (isset($map[$key]) && is_numeric($map[$key])) {
				$end = (int)$map[$key];
				if ($end < time()) {
					$statuses[] = 'expired';
				} else {
					$statuses[] = 'active';
				}
			} else {
				$statuses[] = 'lifetime';
			}
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
			if (strpos($key, '_') !== false) continue; // skip flags
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
	 * Render pagination
	 */
	protected function renderPagination(int $total, int $perPage, int $currentPage): string {
		$totalPages = ceil($total / $perPage);
		$input = $this->wire('input');

		$out = "<div style='margin-top:1em;'>";
		$out .= "<span>Page {$currentPage} of {$totalPages} ({$total} purchases)</span> ";

		$baseParams = $input->get->getArray();

		if ($currentPage > 1) {
			$baseParams['pg'] = $currentPage - 1;
			$out .= "<a href='?" . http_build_query($baseParams) . "' class='ui-button'>&laquo; Prev</a> ";
		}

		if ($currentPage < $totalPages) {
			$baseParams['pg'] = $currentPage + 1;
			$out .= "<a href='?" . http_build_query($baseParams) . "' class='ui-button'>Next &raquo;</a>";
		}

		$out .= "</div>";

		return $out;
	}
}
