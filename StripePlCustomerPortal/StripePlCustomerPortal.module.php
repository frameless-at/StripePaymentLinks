<?php namespace ProcessWire;

use ProcessWire\Page;
use ProcessWire\User;
use ProcessWire\WireData;
use ProcessWire\Module;
use Stripe\StripeClient;
use Stripe\Exception\ApiErrorException;

/**
 * StripePlCustomerPortal
 *
 * Provides a customer overview page rendered via a real template (/site/templates/spl_account.php).
 * - Creates template "spl_account" and page "/account" on install.
 * - Template file prints $modules->get('StripePlCustomerPortal')->renderAccount();
 *
 * Requires: ProcessWire 3.0.210+, StripePaymentLinks.
 */
class StripePlCustomerPortal extends WireData implements Module {

  /**
   * Module metadata.
   *
   * @return array
   */
  public static function getModuleInfo(): array {
    return [
      'title'    => 'StripePaymentLinks Customer Portal',
      'version'  => '0.1.6',
      'summary'  => 'Customer overview at /account using a dedicated template (spl_account).',
      'author'   => 'frameless Media',
      'autoload' => true,
      'singular' => true,
      'requires' => ['ProcessWire>=3.0.210', 'StripePaymentLinks'],
      'icon'     => 'user-circle',
    ];
  }

  /* ========================= Lifecycle ========================= */

  /**
   * Initialize module: ensure template + page exist and attach hooks.
   *
   * Ensures the account template and page exist after module refresh/upgrades.
   *
   * @return void
   */
  public function init(): void {
     $this->ensureAccountTemplateAndPage();

     $this->addHookBefore('Session::redirect', function(HookEvent $e) {
       if($this->user->isLoggedin()) return;

       $sess = $this->wire()->session;
       $cfg  = $this->wire()->config;
       $input = $this->wire()->input;

       if ($input->get('spl_logout')) { return; }

       $req  = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/', '/') . '/';
       $to   = rtrim(parse_url((string)$e->arguments(0), PHP_URL_PATH) ?? '/', '/') . '/';
       $root = rtrim($cfg->urls->root, '/') . '/';

       if($req === '/account/' && $to === $root) {
         $sess->set('pl_open_login', 1);
         $sess->set('pl_intended_url',
           ($cfg->https ? 'https://' : 'http://') . $cfg->httpHost . $cfg->urls->root . 'account/'
         );
       }
     });

     $this->addHookAfter('Page::render', function(HookEvent $e) {
       $sess = $this->wire()->session;
       if($this->user->isLoggedin() || !$sess->get('pl_open_login')) return;
       $sess->remove('pl_open_login');

       $html = (string)$e->return;
       if(stripos($html, 'id="loginModal"') === false) return;

       $e->return = preg_replace(
         '~</body>~i',
         '<script>document.addEventListener("DOMContentLoaded",()=>{let m=document.getElementById("loginModal");if(m&&window.bootstrap)bootstrap.Modal.getOrCreateInstance(m).show();});</script></body>',
         $html,
         1
       );
     });

     $this->addHookBefore('Page::render', function(HookEvent $e) {
       $input = $this->wire('input');
       if(!$input->get('spl_logout')) return;
       $s = $this->wire('session');
       $s->logout();
       $s->remove('pl_open_login');
       $s->remove('pl_intended_url');
       $this->wire('session')->redirect($this->wire('config')->urls->root);
     });

     $this->addHook('/stripepaymentlinks/api', function(HookEvent $e) {
       $this->handleProfileUpdate($e);
     });

     $this->addHookAfter('StripePaymentLinks::t', function(HookEvent $e) {
       $intended = (string)$this->wire('session')->get('pl_intended_url');
       if($intended === '' || strpos($intended, '/account/') === false) return;
       $key = (string)$e->arguments(0);
       if ($key === 'modal.login.title') { $e->return = $this->tLocal('modal.login.title'); return; }
       if ($key === 'modal.login.body')  { $e->return = $this->tLocal('modal.login.body');  return; }
     });
   }

  /**
   * Create template, template file and page on install.
   *
   * @return void
   */
  public function ___install(): void {
    $this->ensureAccountTemplateAndPage(true);
  }

  /**
   * Keep things intact on upgrade.
   *
   * @param mixed $from
   * @param mixed $to
   * @return void
   */
  public function ___upgrade($from, $to): void {
    $this->ensureAccountTemplateAndPage(true);
  }

  /**
   * Optional clean-up on uninstall: remove page; drop template if unused.
   *
   * The physical template file is left untouched (it may have been customized).
   *
   * @return void
   */
  public function ___uninstall(): void {
    $pages     = $this->wire('pages');
    $templates = $this->wire('templates');

    if ($p = $pages->get('/account/')) {
      try { $pages->delete($p, true); } catch (\Throwable $e) { /* ignore */ }
    }

    if ($tpl = $templates->get('spl_account')) {
      // delete only if no pages use it anymore
      if (!$pages->count("template=spl_account")) {
        try { $templates->delete($tpl); } catch (\Throwable $e) { /* ignore */ }
      }
    }
    // Leave the physical file untouched (may have been modified).
  }

  /**
   * Create a Stripe Billing Portal session for the current user and redirect.
   * - Lives entirely in StripePlCustomerPortal (no SPL changes).
   * - Uses SPL's stored secret key if present; optional local override.
   * - Read-only; creates a session server-side and 302-redirects to Stripe.
   *
   * Trigger it by linking to: /account/?action=billing_portal
   */

/** Create a Stripe Billing Portal session (prefers ?customer=, else falls back). */
   protected function handleBillingPortalRedirect(): void {

       $wire    = $this->wire();
       $user    = $wire->user;
       $input   = $wire->input;
       $session = $wire->session;
       $log     = $wire->log;
       $config  = $wire->config;

       // must be logged in
       if(!$user || !$user->id) {
           $login = $this->loginUrl() ?: $config->urls->root;
           $session->redirect($login);
           return;
       }

       // --- 1) Prefer explicit ?customer= if present and valid ---
       $cidParam = trim((string)$input->get->text('customer'));
       $customerId = null;
       if ($cidParam !== '' && preg_match('~^cus_[A-Za-z0-9]+$~', $cidParam)) {
           $customerId = $cidParam;
       }

       // --- 2) Fallback: derive from the user's purchases (first match) ---
       if (!$customerId && $user->hasField('spl_purchases')) {
           foreach ($user->spl_purchases as $purchase) {
               $meta = (array) $purchase->meta('stripe_session');
               if (!empty($meta['customer'])) {
                   $raw = $meta['customer'];
                   if (is_string($raw)) {
                       $customerId = $raw;
                   } elseif (is_array($raw) && isset($raw['id'])) {
                       $customerId = (string)$raw['id'];
                   } elseif (is_object($raw) && isset($raw->id)) {
                       $customerId = (string)$raw->id;
                   }
                   if ($customerId) break;
               }
           }
       }

       if(!$customerId) {
           $log->error("Portal billing_portal: NO CUSTOMER ID (user={$user->id})");
           $this->emitApiError(403, 'No Stripe customer ID found.');
       }

       // resolve secret
       $secret = $this->detectStripeSecretFromSpl();
       if($secret === '') {
           $this->emitApiError(500, 'Stripe secret not configured.');
       }

       // load Stripe SDK if present
       $sdk = $this->wire('config')->paths->siteModules . 'StripePaymentLinks/vendor/stripe-php/init.php';
       if (is_file($sdk)) {
           require_once $sdk;
       }

       // --- sanitize/normalize return_url to absolute and same host ---
       $raw     = trim((string)$input->get->text('return'));
       $host    = (string)$config->httpHost;
       $scheme  = $config->https ? 'https://' : 'http://';
       $default = $scheme . $host . $config->urls->root . 'account/';

       if ($raw === '' || !preg_match('~^https?://~i', $raw)) {
           if ($raw !== '' && str_starts_with($raw, '/')) {
               $returnUrl = $scheme . $host . $raw;
           } else {
               $returnUrl = $default;
           }
       } else {
           $returnUrl = $raw;
       }
       try {
           $ru = parse_url($returnUrl);
           $sameHost = !empty($ru['host']) && strcasecmp($ru['host'], $host) === 0;
           if (!$sameHost || !filter_var($returnUrl, FILTER_VALIDATE_URL)) {
               $returnUrl = $default;
           }
       } catch (\Throwable $e) {
           $returnUrl = $default;
       }

       // --- create portal session ---
       try {
           $stripe = new \Stripe\StripeClient($secret);
           $bp = $stripe->billingPortal->sessions->create([
               'customer'   => $customerId,
               'return_url' => $returnUrl,
           ]);

           if (empty($bp->url)) {
               $log->error("Portal billing_portal: empty session URL (user={$user->id})");
               $this->emitApiError(502, 'Unable to open Stripe customer portal.');
           }

           $session->redirect((string)$bp->url);
           return;

       } catch (\Throwable $e) {
           $this->emitApiError(500, $e->getMessage());
       }
   }

   /* Try to read the Stripe secret key from SPL module config.
   * Falls back to empty string if not found.
   */
  protected function detectStripeSecretFromSpl(): string {
      $modules = $this->wire('modules');

      // 1) Try SPL config directly
      $splCfg = is_object($modules) ? (array)$modules->getConfig('StripePaymentLinks') : [];
      foreach($splCfg as $v) {
          $v = (string)$v;
          if(str_starts_with($v, 'sk_live_') || str_starts_with($v, 'sk_test_')) {
              return $v;
          }
      }

      // 2) Try instance property (if SPL exposes it via public get)
      $spl = $modules ? $modules->get('StripePaymentLinks') : null;
      if($spl) {
          // If your SPL exposes a getter like $spl->getSecretKey(), use it here instead.
          if(method_exists($spl, 'getSecretKey')) {
              $k = (string)$spl->getSecretKey();
              if($k !== '') return $k;
          }
      }

      return '';
  }

  /**
   * Attempt to include Stripe SDK from SPL's vendor path (if not globally loaded).
   */
  protected function attemptLoadStripeSdk(): void {
      $paths = $this->wire('config')->paths;
      $candidates = [
          $paths->siteModules . 'StripePaymentLinks/vendor/autoload.php',
          $paths->root . 'vendor/autoload.php', // in case of a project-wide composer
      ];
      foreach($candidates as $file) {
          if(is_file($file)) {
              @require_once $file;
              if(class_exists(\Stripe\StripeClient::class)) return;
          }
      }
  }

  /**
   * Small helper to emit a minimal JSON error (used by the handler on failure).
   * Keeps output consistent with front-end expectations.
   */
  protected function emitApiError(int $status, string $message): void {
      http_response_code($status);
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
      if(function_exists('fastcgi_finish_request')) fastcgi_finish_request();
      exit;
  }

  /**
   * Resolve a login URL (used if someone hits the handler while logged out).
   * Replace with your own if you already have a method for this.
   */
  protected function loginUrl(): ?string {
      $p = $this->wire('pages')->get('template=admin, name=login');
      return $p && $p->id ? $p->url : null;
  }

  /**
   * Helper: main StripePaymentLinks module instance.
   *
   * @return \ProcessWire\StripePaymentLinks
   */
  private function spl(): \ProcessWire\StripePaymentLinks {
    /** @var \ProcessWire\StripePaymentLinks $mod */
    $mod = $this->modules->get('StripePaymentLinks');
    return $mod;
  }

  /**
   * Local UI texts (internationalization wrapper).
   *
   * @return array
   */
  private function i18n(): array {
    return [
      // Headings / UI
      'ui.purchases.title'    => $this->_('Your purchases'),
      'ui.table.no_purchases' => $this->_('No purchases found.'),
      'ui.table.head.date'    => $this->_('Date'),
      'ui.table.head.product' => $this->_('Product'),
      'ui.table.head.status'  => $this->_('Status'),

      // Button
      'button.edit' => $this->_('Edit my data'),

      // Profile modal
      'profile.title'          => $this->_('Edit my data'),
      'profile.intro'          => $this->_('Update your account information below.'),
      'profile.save'           => $this->_('Save'),
      'profile.cancel'         => $this->_('Cancel'),
      'label.name'             => $this->_('Full name'),
      'label.password'         => $this->_('Password'),
      'label.password_confirm' => $this->_('Confirm password'),

      // Login-required text (used to override SPL's t())
      'modal.login.title' => $this->_('Customer Login'),
      'modal.login.body'  => $this->_('Please sign in to view your purchases.'),

      // Status strings (with placeholders)
      'status.active_until'   => $this->_('Active until {date}'),
      'status.expired_on'     => $this->_('Expired on {date}'),
      'status.paused'         => $this->_('Paused'),
      'status.canceled'       => $this->_('Canceled'),
      'status.canceled_until' => $this->_('Canceled (until {date})'),

      // API responses (local)
      'api.csrf_invalid'    => $this->_('Invalid CSRF'),
      'api.not_signed_in'   => $this->_('Not signed in.'),
      'api.profile_updated' => $this->_('Profile updated.'),

      // Navigation
      'link.login'   => $this->_('Customer Login'),
      'link.logout'  => $this->_('Logout'),
      'link.account' => $this->_('My Account'),
      'link.invoice' => $this->_('Billing'),
    ];
  }

  /**
   * Get a local text by key.
   *
   * @param string $key
   * @return string
   */
  private function tLocal(string $key): string {
    static $L = null;
    if ($L === null) $L = $this->i18n();
    return $L[$key] ?? '';
  }

  /**
   * Tiny formatter: replaces {token} with given values.
   *
   * @param string $key
   * @param array $repl
   * @return string
   */
  private function tLocalFmt(string $key, array $repl): string {
    $txt = $this->tLocal($key);
    return strtr($txt, $repl);
  }

  /**
   * All online products that require access (based on configured product templates).
   *
   * @return \ProcessWire\PageArray
   */
  private function findAccessProducts(): \ProcessWire\PageArray {
    $pages  = $this->wire('pages');
    $san    = $this->wire('sanitizer');
    $tpls   = $this->getProductTemplateNames();
    $tplSel = $tpls ? ('template=' . implode('|', $tpls) . ', ') : '';
    // if no templates are configured, still search for requires_access
    $sel = $tplSel . 'requires_access=1, include=hidden, sort=-created';
    return $pages->find($sel);
  }

  /**
   * Create/verify template + file + page.
   *
   * @param bool $writeFile If true, write the template file even if it exists.
   * @return void
   */
   private function ensureAccountTemplateAndPage(bool $writeFile = false): void {
     $templates   = $this->wire('templates');
     $fieldgroups = $this->wire('fieldgroups');
     $pages       = $this->wire('pages');
     $config      = $this->wire('config');
     $roles       = $this->wire('roles');

     // --- 1) Fieldgroup: get or create
     /** @var \ProcessWire\Fieldgroup|null $fg */
     $fg = $fieldgroups->get('fg_spl_account');
     if (!$fg || !$fg->id) {
       $fg = new \ProcessWire\Fieldgroup();
       $fg->name = 'fg_spl_account';
       $fieldgroups->save($fg);
     }

     // --- 2) Template: get or create
     /** @var \ProcessWire\Template|null $tpl */
     $tpl = $templates->get('spl_account');
     if (!$tpl || !$tpl->id) {
       $tpl = new \ProcessWire\Template();
       $tpl->name       = 'spl_account';
       $tpl->fieldgroup = $fg;
       $tpl->noChildren = 1;
       $tpl->useRoles   = 1;
       $templates->save($tpl);

       // Default access setup (only applied on creation)
       $guest    = $roles->get('guest');
       $customer = $roles->get('customer');

       if ($guest && method_exists($tpl, 'removeRole')) $tpl->removeRole($guest, 'view');
       if ($customer && method_exists($tpl, 'addRole'))  $tpl->addRole($customer, 'view');

       $tpl->set('noAccess', 2);        // redirect/render
       $tpl->set('redirectLogin', '/'); // redirect target
       $templates->save($tpl);
     } else {
       // Ensure correct fieldgroup is assigned, but do NOT override access
       if (!$tpl->fieldgroup || $tpl->fieldgroup->id !== $fg->id) {
         $tpl->fieldgroup = $fg;
         $templates->save($tpl);
       }
     }

     // --- 3) Template file /site/templates/spl_account.php
     $tplFile = rtrim($config->paths->templates, '/\\') . DIRECTORY_SEPARATOR . 'spl_account.php';
     if ($writeFile || !is_file($tplFile)) {
       $code = <<<'PHP'
   <?php namespace ProcessWire;
   /** @var \ProcessWire\Modules $modules */
   $portal = $modules->get('StripePlCustomerPortal');

   $content = '
   <div class="container py-5 mt-5">
     <div class="row">
       <div class="col-lg-10 mx-auto">
         <div class="d-flex align-items-center justify-content-between mb-3">
           <h1 class="mb-0">Hello, ' . $user->title . '</h1>
           ' . $portal->renderHeaderButtons() . '
         </div>
       </div>
     </div>
   </div>';

   $content .= $portal->renderAccount('grid-all');
   PHP;
       if (is_dir($config->paths->templates)) {
         @file_put_contents($tplFile, $code, LOCK_EX);
         @chmod($tplFile, 0660);
       }
     }

     // --- 4) Page /account: create if missing
     $account = $pages->get('/account/');
     if (!$account || !$account->id) {
       $home = $pages->get(1);
       $p = new \ProcessWire\Page();
       $p->template = $tpl;
       $p->parent   = $home;
       $p->name     = 'account';
       $p->title    = 'Account';
       $p->addStatus(\ProcessWire\Page::statusHidden);
       try { $p->save(); } catch (\Throwable $e) { /* ignore */ }
     } else {
       if ((string)$account->template !== 'spl_account') {
         $account->setAndSave('template', $tpl);
       }
     }
   }

  /**
   * Get names of product templates from SPL configuration.
   *
   * @return array
   */
  private function getProductTemplateNames(): array {
    $cfg  = (array) $this->modules->getConfig('StripePaymentLinks');
    $raw  = (array) ($cfg['productTemplateNames'] ?? []);
    $san  = $this->wire('sanitizer');
    return array_values(array_unique(array_filter(array_map([$san, 'name'], $raw))));
  }

 /**
  * Build a localized status label for a purchases row.
  * Accepts the array row from getPurchasesData().
  */
/**
   * Build a consistent badge HTML for a purchase row (used in grid AND table).
   *
   * @param array $r A row from getPurchasesData()
   * @return string HTML <span>…</span>
   */
  private function buildStatusLabel(array $r): string {

      $key  = $r['status_key'] ?? '';
      $date = $r['status_until'] ?? null;

      switch ($key) {

          case 'active_until':
              return sprintf(
                  '<span class="badge text-bg-success rounded-pill shadow-sm">%s</span>',
                  $this->tLocalFmt('status.active_until', ['{date}' => date('Y-m-d', (int)$date)])
              );

          case 'expired_on':
              return sprintf(
                  '<span class="badge text-bg-secondary rounded-pill">%s</span>',
                  $this->tLocalFmt('status.expired_on', ['{date}' => date('Y-m-d', (int)$date)])
              );

          case 'paused':
              return '<span class="badge text-bg-warning rounded-pill">'
                   . $this->tLocal('status.paused')
                   . '</span>';

          case 'canceled':
              return $date
                  ? sprintf(
                        '<span class="badge text-bg-danger rounded-pill">%s</span>',
                        $this->tLocalFmt('status.canceled_until', ['{date}' => date('Y-m-d', (int)$date)])
                    )
                  : '<span class="badge text-bg-danger rounded-pill">'
                    . $this->tLocal('status.canceled')
                    . '</span>';

          case 'active':
              return '<span class="badge text-bg-success rounded-pill">'
                   . $this->tLocal('status.active')
                   . '</span>';

          default:
              return '';
      }
  }
/** Returns normalized purchases: one row per (purchase × product). */
public function getPurchasesData(User $user): array {
  $pages = $this->wire('pages');
  $now   = time();

  // Collect all product IDs → load once
  $pids = [];
  foreach ($user->spl_purchases as $item) {
    $pids = array_merge($pids, array_map('intval', (array) $item->meta('product_ids')));
  }
  $pids = array_values(array_unique(array_filter($pids)));
  $byId = [];
  if ($pids) {
    foreach ($pages->find('id=' . implode('|', $pids) . ', include=all') as $p) $byId[(int)$p->id] = $p;
  }

  $rows = [];
  foreach ($user->spl_purchases as $item) {
    $ts   = (int) $item->created;
    $date = $ts ? date('Y-m-d H:i', $ts) : '';
    $map  = (array) $item->meta('period_end_map');

    foreach ((array) $item->meta('product_ids') as $pidRaw) {
      $pid = (int) $pidRaw;
      $p   = $byId[$pid] ?? null;

      // BUGFIX: Don't skip purchases without page mapping
      // Use fallback data if product page doesn't exist
      $productTitle = '';
      $productUrl   = '';
      $category     = '';
      $thumbUrl     = '';
      $stripeSession = (array) $item->meta('stripe_session'); // Always load for unmapped key lookup

      if ($p && $p->id) {
        // Product page exists - use page data
        $productTitle = (string) $p->title;
        $productUrl   = (bool) $p->get('requires_access') ? $p->httpUrl : '';
        $category     = (string)($p->get('product_category') ?: $p->template->label ?: $p->template->name);

        // First available image field (any name)
        foreach ($p->fields as $f) {
          if ($f->type instanceof \ProcessWire\FieldtypeImage) {
            $imgs = $p->get($f->name);
            if ($imgs && $imgs->count()) {
              $thumbUrl = $imgs->first()->size(800, 600)->url;
            }
            break;
          }
        }
      } else {
        // Product page doesn't exist - extract from Stripe metadata
        $productTitle = $this->extractProductNameFromStripeSession($stripeSession, $pid);
        $category     = 'Stripe Product';
      }

      // Derive status/access
      // BUGFIX: For unmapped purchases (pid=0), period_end_map uses "0#prod_XXX" as key
      $lookupKey = (string)$pid;
      if ($pid === 0 && !empty($stripeSession['line_items']['data'][0]['price']['product']['id'])) {
        $stripeProductId = $stripeSession['line_items']['data'][0]['price']['product']['id'];
        $lookupKey = "0#{$stripeProductId}";
      }

      $endRaw   = $map[$lookupKey] ?? null;
      $paused   = array_key_exists($lookupKey . '_paused', $map);
      $canceled = array_key_exists($lookupKey . '_canceled', $map);

      $statusKey   = '';
      $statusUntil = null;
      $isActive    = null;

      if ($canceled) {
        $statusKey = 'canceled';
        if (is_numeric($endRaw)) $statusUntil = (int) $endRaw;
        $isActive = false;
      } elseif ($paused) {
        $statusKey = 'paused';
        $isActive  = false;
      } elseif (is_numeric($endRaw)) {
        $statusUntil = (int) $endRaw;
        $isActive    = ($statusUntil >= $now);
        $statusKey   = $isActive ? 'active_until' : 'expired_on';
      } else {
        // No period end → treat as timeless access (one-time/lifetime)
        $statusKey = 'active';
        $isActive  = true;
      }

      $rows[] = [
        'purchase_ts'   => $ts,
        'purchase_date' => $date,
        'product_id'    => $pid,
        'product_title' => $productTitle,
        'product_url'   => $productUrl,
        'thumb_url'     => $thumbUrl,
        'category'      => $category,
        'status_key'    => $statusKey,    // 'active'|'active_until'|'expired_on'|'paused'|'canceled'
        'status_until'  => $statusUntil,  // unix ts or null
        'is_active'     => $isActive,     // true|false
      ];
    }
  }

  // spl_free_access: directly granted delivery pages → always active, never in spl_purchases
  if ($user->hasField('spl_free_access') && $user->spl_free_access->count()) {
    $existingPids = array_column($rows, 'product_id');
    foreach ($user->spl_free_access as $deliveryPage) {
      if (!$deliveryPage || !$deliveryPage->id) continue;
      $pid = (int) $deliveryPage->id;
      if (in_array($pid, $existingPids, true)) continue; // already covered by a purchase

      // spl_free_access stores delivery (hidden) pages; parent = sales page with images/title
      $salesPage = ($deliveryPage->parent && $deliveryPage->parent->id)
        ? $deliveryPage->parent
        : $deliveryPage;

      $rows[] = [
        'purchase_ts'   => 0,
        'purchase_date' => '',
        'product_id'    => $pid,
        'product_title' => (string) $salesPage->title,
        'product_url'   => $deliveryPage->httpUrl,
        'thumb_url'     => $this->productThumbUrl($salesPage),
        'category'      => (string)($salesPage->get('product_category') ?: $salesPage->template->label ?: $salesPage->template->name),
        'status_key'    => 'active',
        'status_until'  => null,
        'is_active'     => true,
      ];
    }
  }

  usort($rows, fn($a,$b)=> $b['purchase_ts'] <=> $a['purchase_ts']);
  return $rows;
}

/**
 * Extract product name from Stripe session metadata.
 * Tries multiple fields in order: line_items description, product name, fallback to ID.
 *
 * @param array $stripeSession The stripe_session metadata array
 * @param int $productId The product ID for fallback
 * @return string Product name or "Product #[id]" as fallback
 */
private function extractProductNameFromStripeSession(array $stripeSession, int $productId): string {
  // Try to get line items
  if (isset($stripeSession['line_items']['data']) && is_array($stripeSession['line_items']['data'])) {
    foreach ($stripeSession['line_items']['data'] as $lineItem) {
      if (!is_array($lineItem)) continue;

      // First try: description field
      if (!empty($lineItem['description']) && is_string($lineItem['description'])) {
        return trim($lineItem['description']);
      }

      // Second try: product name from price object
      if (isset($lineItem['price']['product']['name']) && is_string($lineItem['price']['product']['name'])) {
        return trim($lineItem['price']['product']['name']);
      }
    }
  }

  // Fallback: use product ID
  return $productId > 0 ? "Product #$productId" : "Unknown Product";
}

  /* ========================= Rendering ========================= */

  /**
   * Public method so the template can call it.
   *
   * @param string $view
   * @return string
   */
  public function renderAccount(string $view = 'grid'): string {
    $user = $this->wire('user');

    // Not logged in → NO redirect, open modal instead
    if (!$user->isLoggedin()) {
      $session = $this->wire('session');

      // Mark for texts / return URL
      $session->set('pl_open_login', 1); // JS should open modal
      $session->set('pl_intended_url', $this->wire('page')->httpUrl); // target / texts

      // Return a slim wrapper — content will come after login
      return $this->wrapContainer('<div class="my-5"></div>');
    }

    // Early action handling (before rendering any markup)
    if ($this->wire('input')->get->text('action') === 'billing_portal') {
      $this->handleBillingPortalRedirect();
      return ''; // ensure string return type after redirect attempt
    }

    // explicit view parameter OR ?view=table override
    $viewParam = $this->wire('input')->get->text('view');
    if ($viewParam) $view = $viewParam;

    // choose rendering mode
    switch ($view) {
      case 'table':
        $content = $this->renderPurchasesTable($user);
        break;
      case 'grid':
        $content = $this->renderPurchasesGrid($user);
        break;
      case 'grid-all':
        $content = $this->renderPurchasesGridAll($user);
        break;
      default:
        // fallback → future views can be added easily here
        $content = $this->renderPurchasesGrid($user);
        break;
    }

    $content = '<div class="row g-3">' . $content . '</div>';
    return $this->wrapContainer($content . $this->modalProfileEdit($user));
  }

  /**
   * Render the "Edit my data" button.
   *
   * @param array $opts
   * @return string
   */
 public function renderEditButton(array $opts = []): string {
     $label  = $opts['label'] ?? $this->tLocal('button.edit');
     $class  = trim('btn btn-primary d-flex align-items-center ' . ($opts['class'] ?? ''));
     $idAttr = isset($opts['id']) ? ' id="' . htmlspecialchars((string)$opts['id'], ENT_QUOTES) . '"' : '';

     return
       '<button type="button"' . $idAttr . ' class="' . htmlspecialchars($class, ENT_QUOTES) . '"'
       . ' data-bs-toggle="modal" data-bs-target="#profileModal">'
         . '<i class="bi bi-pencil fs-6 d-inline d-md-none"></i>'
         . '<span class="d-none d-md-inline ms-1">' . htmlspecialchars($label, ENT_QUOTES) . '</span>'
       . '</button>';
   }
  /**
   * Renders the top-right button group: view switcher + edit button.
   *
   * @param string $activeView
   * @return string
   */
  public function renderHeaderButtons(string $activeView = 'grid-all'): string {
    $urlBase = $this->wire('page')->url;
    $editBtn = $this->renderEditButton(['class' => 'btn btn-primary btn-sm']);
    $isGrid  = $activeView === 'grid-all';
    $isTable = $activeView === 'table';

    $icons = sprintf(
      '<div class="d-flex align-items-center gap-3 me-4">
        <a href="%1$s?view=grid-all" class="text-secondary %2$s" title="Grid view">
          <i class="bi bi-grid fs-5"></i>
        </a>
        <a href="%1$s?view=table" class="text-secondary %3$s" title="Table view">
          <i class="bi bi-list-ul fs-3"></i>
        </a>
      </div>',
      htmlspecialchars($urlBase, ENT_QUOTES),
      $isGrid ? 'text-primary' : '',
      $isTable ? 'text-primary' : ''
    );

    return '<div class="d-flex align-items-center">' . $icons . $editBtn . '</div>';
  }

  /* ========================= UI bits ========================= */

  /**
   * Wrap content in a Bootstrap container for layout.
   *
   * @param string $inner
   * @return string
   */
  private function wrapContainer(string $inner): string {
    return '<div class="container mb-5"><div class="row"><div class="col-lg-10 mx-auto">' . $inner . '</div></div></div>';
  }

  /**
  * Return a product thumb URL from the first available image field (any name).
  */
  private function productThumbUrl(\ProcessWire\Page $p, int $w = 800, int $h = 600): string {
    // 1) If you ever want to prefer specific field names, you can drop them here:
    $preferred = []; // e.g. ['hero', 'cover', 'images'] — leave empty to just scan all fields

    foreach ($preferred as $fname) {
      if ($p->hasField($fname)) {
        $imgs = $p->get($fname);
        if ($imgs instanceof \ProcessWire\Pageimages && $imgs->count()) {
          return $imgs->first()->size($w, $h)->url;
        }
      }
    }

    // 2) Fallback: scan every field on the page and pick the first image field with images
    foreach ($p->fields as $field) {
      if ($field->type instanceof \ProcessWire\FieldtypeImage) {
        $imgs = $p->get($field->name);
        if ($imgs instanceof \ProcessWire\Pageimages && $imgs->count()) {
          return $imgs->first()->size($w, $h)->url;
        }
      }
    }
    return '';
  }
  /**
   * Render purchased products as cards (one card per product).
   *
   * @param User $user
   * @param array $opts
   * @return string
   */
  public function renderPurchasesGrid(User $user, array $opts = []): string {
    $rows = $this->getPurchasesData($user);
    if (!$rows) return '<p>' . $this->tLocal('ui.table.no_purchases') . '</p>';

    $seen = []; $usable = [];
    foreach ($rows as $r) {
      $keep = ($r['status_key'] === 'active') ||
              ($r['status_key'] === 'active_until' && $r['is_active'] === true);
      if (!$keep) continue;

      // Skip purchases without page mapping (no URL = no valid product page)
      if (empty($r['product_url'])) continue;

      $pid = (int)$r['product_id'];
      if (isset($seen[$pid])) continue;
      $seen[$pid] = true;
      $usable[] = $r;
    }
    if (!$usable) return '<p>' . $this->tLocal('ui.table.no_purchases') . '</p>';

    $css = '<style id="spl-card-overlay-css">
  .spl-card{position:relative;overflow:hidden;border:0}
  .spl-card .card-img-top{display:block;width:100%;height:auto}
  .spl-card .spl-grad{position:absolute;left:0;right:0;bottom:0;top:50%;
    background:linear-gradient(to top,rgba(0,0,0,.5) 0%,rgba(0,0,0,0) 100%)}
  .spl-card .spl-title{position:absolute;left:0;right:0;bottom:10px;padding:16px 18px;
    text-align:center;color:#fff;font-weight:700;text-shadow:0 1px 2px rgba(0,0,0,.6)}
  .spl-card.spl-no-img .position-relative{min-height:120px;background:#343a40}
  .spl-card.spl-no-img .spl-grad{display:none}
  .spl-card.spl-no-img .spl-title{top:0;bottom:0;display:flex;align-items:center;justify-content:center}
  </style>';

    $badge = function(array $r): string {
      if (($r['status_key'] ?? '') === 'active_until' && !empty($r['status_until'])) {
        return '<span class="badge text-bg-success rounded-pill shadow-sm">'
             . $this->buildStatusLabel($r)
             . '</span>';
      }
      return '';
    };

    $out = $css;
    foreach ($usable as $r) {
      $title  = htmlspecialchars($r['product_title'], ENT_QUOTES);
      $imgTag  = $r['thumb_url'] ? '<img class="card-img-top" src="' . htmlspecialchars($r['thumb_url'], ENT_QUOTES) . '" alt="">' : '';
      $noImg   = $r['thumb_url'] ? '' : ' spl-no-img';
      $anchor  = $r['product_url'] ? '<a href="' . htmlspecialchars($r['product_url'], ENT_QUOTES) . '" class="stretched-link"></a>' : '';

      $out .= '
        <div class="col-12 col-sm-6 col-lg-4">
          <div class="card spl-card' . $noImg . ' shadow-sm">
            <div class="position-relative">
              ' . $imgTag . '
              <div class="spl-grad"></div>
              <div class="spl-title"><h3 class="m-0">' . $title . '</h3></div>
              <div class="position-absolute top-0 end-0 m-2">' . $badge($r) . '</div>
            </div>
            ' . $anchor . '
          </div>
        </div>';
    }
    return $out;
  }
  /**
   * Grid: purchased products on top (using renderPurchasesGrid), below "not yet purchased" in gray.
   *
   * @param User $user
   * @return string
   */
  public function renderPurchasesGridAll(User $user): string {
    // 1) active/purchased cards
    $ownedHtml = $this->renderPurchasesGrid($user);

    // 2) collect active-owned product IDs (only those with valid page mapping)
    $rows = $this->getPurchasesData($user);
    $ownedActiveIds = [];
    foreach ($rows as $r) {
      if (($r['status_key'] === 'active' ||
           ($r['status_key'] === 'active_until' && $r['is_active'] === true))
          && !empty($r['product_url'])) {
        $ownedActiveIds[(int) $r['product_id']] = true;
      }
    }

    // spl_free_access: also exclude delivery page ID + parent (sales page) ID
    // so they never appear in the gray/unowned section regardless of requires_access mapping
    $user = $this->wire('user');
    if ($user->hasField('spl_free_access')) {
      foreach ($user->spl_free_access as $deliveryPage) {
        if (!$deliveryPage || !$deliveryPage->id) continue;
        $ownedActiveIds[(int) $deliveryPage->id] = true;
        if ($deliveryPage->parent && $deliveryPage->parent->id) {
          $ownedActiveIds[(int) $deliveryPage->parent->id] = true;
        }
      }
    }

    // 3) find all gated products that are NOT actively owned
    $all = $this->findAccessProducts();
    $unowned = [];
    foreach ($all as $p) {
      if (!isset($ownedActiveIds[(int) $p->id])) $unowned[] = $p;
    }
    if (!$unowned) return $ownedHtml;

    // 4) CSS only for gray overlay
    $css = '<style id="spl-gray-cards">
  .spl-card.spl-gray .card-img-top{filter:grayscale(100%);opacity:.9}
  .spl-card.spl-gray:hover .card-img-top{filter:none;opacity:1}
  .spl-card.spl-gray.spl-no-img .position-relative{background:#6c757d}
  </style>';

    $out = $ownedHtml . $css;

    // 5) render unowned cards in gray
    foreach ($unowned as $p) {
      $title = htmlspecialchars((string) $p->title, ENT_QUOTES);
      $thumb  = $this->productThumbUrl($p);
      $img    = $thumb ? '<img class="card-img-top" src="' . htmlspecialchars($thumb, ENT_QUOTES) . '" alt="">' : '';
      $noImg  = $thumb ? '' : ' spl-no-img';

      $out .= '
        <div class="col-12 col-sm-6 col-lg-4">
          <div class="card spl-card spl-gray' . $noImg . ' shadow-sm">
            <div class="position-relative">
              ' . $img . '
              <div class="spl-grad"></div>
              <div class="spl-title"><h3 class="m-0">' . $title . '</h3></div>
            </div>
            <a href="' . htmlspecialchars($p->httpUrl, ENT_QUOTES) . '" class="stretched-link"></a>
          </div>
        </div>';
    }

    return $out;
  }

/**
 * Render purchases as a compact table (now using buildStatusLabel()).
 *
 * DEBUG MODE: Add ?debug=1 to see period_end_map structure
 */
private function renderPurchasesTable(User $user): string {
  $debugMode = $this->wire('input')->get->int('debug') === 1;
  $debugOutput = '';

  $rows = $this->getPurchasesData($user);
  if (!$rows) return '<p>' . $this->tLocal('ui.table.no_purchases') . '</p>';

  // DEBUG: Show raw data
  if ($debugMode) {
    $debugOutput .= '<div class="alert alert-warning mb-4"><h4>🔍 DEBUG MODE</h4>';

    foreach ($user->spl_purchases as $item) {
      $map = (array) $item->meta('period_end_map');
      $pids = (array) $item->meta('product_ids');
      $session = (array) $item->meta('stripe_session');

      $debugOutput .= '<hr><strong>Purchase Item #' . $item->id . '</strong><br>';
      $debugOutput .= '<strong>product_ids:</strong> ' . htmlspecialchars(json_encode($pids)) . '<br>';
      $debugOutput .= '<strong>period_end_map KEYS:</strong> <code>' . implode(', ', array_keys($map)) . '</code><br>';
      $debugOutput .= '<strong>period_end_map DATA:</strong><pre style="font-size:11px;">' . htmlspecialchars(json_encode($map, JSON_PRETTY_PRINT)) . '</pre>';

      // Show line_items structure
      if (isset($session['line_items']['data'][0])) {
        $li = $session['line_items']['data'][0];
        $debugOutput .= '<strong>First line_item:</strong><br>';
        $debugOutput .= '  - description: ' . htmlspecialchars($li['description'] ?? 'N/A') . '<br>';
        $debugOutput .= '  - price.product: ' . htmlspecialchars(json_encode($li['price']['product'] ?? 'N/A')) . '<br>';
      }
    }
    $debugOutput .= '</div>';
  }

  $resolveCustomerId = function(User $u, int $productId): ?string {
    if (!$u->hasField('spl_purchases') || !$u->spl_purchases->count()) return null;

    $items = iterator_to_array($u->spl_purchases);
    usort($items, fn($a,$b)=>((int)$b->created)<=>((int)$a->created));

    foreach ($items as $it) {
      $meta = (array)$it->meta('stripe_session');
      if (!$meta) continue;

      $pids = array_map('intval', (array)$it->meta('product_ids'));
      if (!in_array($productId, $pids, true)) continue;

      $raw = $meta['customer'] ?? null;
      if (is_string($raw) && $raw !== '') return $raw;
      if (is_array($raw) && isset($raw['id'])) return (string)$raw['id'];
      if (is_object($raw) && isset($raw->id)) return (string)$raw->id;
    }
    return null;
  };

  $accountUrl = $this->wire('pages')->get('template=spl_account')->url
              ?: $this->wire('config')->urls->root . 'account/';
  $returnUrl  = $this->wire('page')->httpUrl;

  $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

  $out  = $debugOutput;
  $out .= '<h3 class="mb-3">' . $this->tLocal('ui.purchases.title') . '</h3>';
  $out .= '<div class="table-responsive"><table class="table table-sm align-middle">';
  $out .= '<thead><tr>'
        . '<th style="width:180px;">' . $this->tLocal('ui.table.head.date') . '</th>'
        . '<th>'                      . $this->tLocal('ui.table.head.product') . '</th>'
        . '<th style="width:200px;">' . $this->tLocal('ui.table.head.status') . '</th>'
        . '<th style="width:100px;"></th>'
        . '</tr></thead><tbody>';

  foreach ($rows as $r) {

    $cid = $resolveCustomerId($user, (int)$r['product_id']);

    $invoiceLink = '';
    if ($cid) {
      $bp = $accountUrl
          . '?action=billing_portal'
          . '&customer=' . rawurlencode($cid)
          . '&return='   . rawurlencode($returnUrl);

      $invoiceLink = '<a class="btn btn-sm btn-light" target="_blank" '
                   . 'href="' . $h($bp) . '">'
                   . $h($this->tLocal('link.invoice'))
                   . '</a>';
    }

    $prodHtml = $r['product_url']
      ? '<a href="' . $h($r['product_url']) . '">' . $h($r['product_title']) . '</a>'
      : $h($r['product_title']);

    $status = $this->buildStatusLabel($r);

    $out .= '<tr>'
          . '<td style="white-space:nowrap;">' . $h($r['purchase_date']) . '</td>'
          . '<td>' . $prodHtml . '</td>'
          . '<td>' . $status . '</td>'
          . '<td style="text-align:right">' . $invoiceLink . '</td>'
          . '</tr>';
  }

  $out .= '</tbody></table></div>';
  return $out;
}

  /**
   * Resolve absolute paths to SPL UI bits (ModalRenderer + modal view).
   *
   * @return array
   */
  private function splUiPaths(): array {
    $file = $this->modules->getModuleFile('StripePaymentLinks'); // path to SPL module file
    $base = dirname($file);
    return [
      'renderer' => $base . '/includes/ui/ModalRenderer.php',
      'view'     => $base . '/includes/views/modal.php',
    ];
  }

  /**
   * Renders the profile edit modal using SPL's renderer/view, but local texts.
   *
   * @param User|null $user
   * @return string
   */
  public function modalProfileEdit(?\ProcessWire\User $user = null): string {
    $user ??= $this->wire('user');
    $paths = $this->splUiPaths();

    if (is_file($paths['renderer'])) require_once $paths['renderer'];
    if (!class_exists('\ProcessWire\ModalRenderer')) return '';

    $spl = $this->spl();

    $ui = new \ProcessWire\ModalRenderer(
      is_file($paths['view']) ? $paths['view'] : null,
      function () use ($spl) { return $spl->wire('session')->CSRF->renderInput(); }
    );

    $h         = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $title     = $this->tLocal('profile.title');
    $introHtml = '<p><b>' . htmlspecialchars($this->tLocal('profile.intro'), ENT_QUOTES, 'UTF-8') . '</b></p>';
    $btnSave   = $this->tLocal('profile.save');
    $btnCancel = $this->tLocal('profile.cancel');

    // new i18n keys (Labels)
    $labelName    = $this->tLocal('label.name');
    $labelPass    = $this->tLocal('label.password');
    $labelPass2   = $this->tLocal('label.password_confirm');
    $prefillTitle = $user ? (string) $user->title : '';

    $modal = [
      'id'    => 'profileModal',
      'title' => $h($title),
      'form'  => [
        'action' => $this->spl()->apiUrl(),
        'op'     => 'profile_update',
        'hidden' => [
          // use return_url here instead of depending on other behavior
          'return_url' => $this->wire('page')->httpUrl,
        ],
        'bodyIntro' => $introHtml,
        'fields'    => [
          ['type' => 'text', 'name' => 'title', 'label' => $labelName, 'value' => $prefillTitle, 'attrs' => ['autocomplete' => 'name']],
          ['type' => 'password', 'name' => 'password', 'label' => $labelPass, 'attrs' => ['autocomplete' => 'new-password']],
          ['type' => 'password', 'name' => 'password_confirm', 'label' => $labelPass2, 'attrs' => ['autocomplete' => 'new-password']],
        ],
        'submitText'  => $btnSave,
        'cancelText'  => $btnCancel,
        'footerClass' => 'modal-footer bg-light-subtle',
      ],
    ];

    return $ui->render($modal);
  }

  /**
   * Render a login / account / logout link depending on user state and location.
   *
   * @param array $opts
   * @return string
   */
  public function renderLoginLink(array $opts = []): string {
    $user      = $this->wire('user');
    $page      = $this->wire('page');
    $session   = $this->wire('session');
    $config    = $this->wire('config');
    $class     = trim((string) ($opts['class'] ?? ''));
    $isAccount = (strpos((string) $page->url, '/account/') === 0);

    // --- not logged in: Login link (open modal) ---
    if (!$user->isLoggedin()) {
      $label = $opts['label'] ?? $this->tLocal('link.login');

      // SPL handles redirect after successful login.
      $session->set('pl_intended_url', $config->urls->root . 'account/');

      $onclick = "var m=document.getElementById('loginModal');"
               . "if(m&&window.bootstrap){bootstrap.Modal.getOrCreateInstance(m).show();return false;}"
               . "return false;";

      return sprintf(
        '<a href="#" class="%s" onclick="%s">%s</a>',
        htmlspecialchars($class, ENT_QUOTES),
        htmlspecialchars($onclick, ENT_QUOTES),
        htmlspecialchars($label, ENT_QUOTES)
      );
    }

    // --- logged in + on /account/ → Logout link ---
    if ($isAccount) {
      $href  = $page->url . '?spl_logout=1';
      $label = $opts['label'] ?? $this->tLocal('link.logout');
      return sprintf(
        '<a href="%s" class="%s">%s</a>',
        htmlspecialchars($href, ENT_QUOTES),
        htmlspecialchars($class, ENT_QUOTES),
        htmlspecialchars($label, ENT_QUOTES)
      );
    }

    // --- logged in elsewhere → My Account link ---
    $label = $opts['label'] ?? $this->tLocal('link.account');
    return sprintf(
      '<a href="%saccount/" class="%s">%s</a>',
      htmlspecialchars($config->urls->root, ENT_QUOTES),
      htmlspecialchars($class, ENT_QUOTES),
      htmlspecialchars($label, ENT_QUOTES)
    );
  }

  /**
   * Helper to return JSON responses.
   *
   * @param array $a
   * @param int $status
   * @return string
   */
  private function j(array $a, int $status = 200): string {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    return json_encode($a, JSON_UNESCAPED_UNICODE);
  }

  /**
   * Handle profile update requests routed through /stripepaymentlinks/api.
   *
   * @param \ProcessWire\HookEvent $e
   * @return void
   */
  private function handleProfileUpdate(\ProcessWire\HookEvent $e): void {
    $input = $this->wire('input');
    if (!$input->requestMethod('POST')) return;
    $op = (string) ($input->post->op ?? $input->post->action ?? '');
    if ($op !== 'profile_update') return;

    $e->replace = true;

    $session = $this->wire('session');
    $users   = $this->wire('users');
    $san     = $this->wire('sanitizer');
    $u       = $this->wire('user');

    if (!$session->CSRF->hasValidToken()) {
      $e->return = $this->j(['ok' => false, 'error' => $this->tLocal('api.csrf_invalid')], 400); return;
    }
    if (!$u->isLoggedin()) {
      $e->return = $this->j(['ok' => false, 'error' => $this->tLocal('api.not_signed_in')], 401); return;
    }

    $newTitle = trim((string) $input->post->text('title'));
    $pass1    = (string) $input->post->text('password');
    $pass2    = (string) $input->post->text('password_confirm');

    if ($pass1 !== '' || $pass2 !== '') {
      if (strlen($pass1) < 8) { $e->return = $this->j(['ok' => false, 'error' => $this->spl()->t('api.password.too_short')]); return; }
      if ($pass1 !== $pass2)  { $e->return = $this->j(['ok' => false, 'error' => $this->spl()->t('api.password.mismatch')]); return; }
    }

    try {
      $u->of(false);
      if ($newTitle !== '' && $newTitle !== (string) $u->title) $u->title = $newTitle;
      if ($pass1 !== '') $u->pass = $pass1;
      $this->wire('users')->save($u, ['quiet' => true]);

      $ret = $this->wire('sanitizer')->url((string) $this->wire('input')->post->return_url) ?: $this->wire('page')->httpUrl;
      $hostOk = true;
      try {
        $ru = parse_url($ret);
        $hostOk = empty($ru['host']) || $ru['host'] === $this->wire('config')->httpHost;
      } catch (\Throwable $ex) { $hostOk = false; }
      if (!$hostOk) $ret = $this->wire('page')->httpUrl;

      $e->return = $this->j(['ok' => true, 'message' => $this->tLocal('api.profile_updated'), 'redirect' => $ret]);
    } catch (\Throwable $ex) {
      $e->return = $this->j(['ok' => false, 'error' => $this->spl()->t('api.server_error')], 500);
    }
  }
}
