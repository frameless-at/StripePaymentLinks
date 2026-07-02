<?php namespace ProcessWire;

use ProcessWire\Page;
use ProcessWire\PageArray;
use ProcessWire\User;
use ProcessWire\Wire;
use ProcessWire\Field;
use ProcessWire\Template;
use ProcessWire\InputfieldWrapper;

/**
 * PLFreebies — lead-capture / freebies service for StripePaymentLinks core.
 *
 * Formerly the standalone StripePlFreebies module; integrated into core so the
 * feature ships with SPL but stays DORMANT until configured (Freebie templates).
 * StripePlCustomerPortal is OPTIONAL (for identical cards + /account/ integration).
 *
 * No page/URL of its own. The core module exposes the template-callable methods
 * (renderRegisterForm/renderFreebies/getFreebiesData/hasFreebieAccess/
 * requireFreebieAccess/grantFreebie) and delegates here. Self-signup creates a
 * `member` user + must_set_password and grants the freebie; opt-in via ?access= link.
 */
class PLFreebies extends Wire {

  protected StripePaymentLinks $mod;
  public function __construct(StripePaymentLinks $mod) { $this->mod = $mod; }

  /** TTL of the magic link token (minutes). */
  const MAGIC_TTL_MINUTES = 60 * 24 * 3; // 3 days – freebies kept intentionally low-barrier

  /* ========================= Lifecycle ========================= */

  /**
   * Registers the freebie hooks. Called from StripePaymentLinks::init(). Every hook
   * self-gates (plf_freebie field / showRegisterLink config / freebie_register op),
   * so on installs without any freebie configuration they stay completely inert.
   */
  public function initHooks(): void {
    // Own op on the shared SPL API endpoint (like StripePlCustomerPortal::profile_update)
    $this->addHook('/stripepaymentlinks/api', function(\ProcessWire\HookEvent $e) {
      $this->handleApi($e);
    });

    // Optional auto-integration into the /account/ hub: only when CustomerPortal is present.
    // Attach freebie cards to its hookable extension point → they appear in the account grid
    // on every installation, without any template adjustment.
    if ($this->wire("modules")->isInstalled('StripePlCustomerPortal')) {
      $this->addHookAfter('StripePlCustomerPortal::accountAppendCards', function(\ProcessWire\HookEvent $e) {
        // Cards belong in a grid view, not in the table view.
        $view = (string) $e->arguments(1);
        if ($view === 'table') return;
        $user = $e->arguments(0);
        if (!($user instanceof User)) $user = $this->wire('user');
        $e->return .= $this->renderFreebieCards($user);
      });
    }

    // Prompt the "set your password" modal on freebie pages too, so a member who
    // arrived via a magic link (must_set_password) gets reminded there as well.
    $this->addHookAfter('StripePaymentLinks::promptSetPasswordOnPage', function(\ProcessWire\HookEvent $e) {
      if ($e->return) return;
      $page = $e->arguments(0);
      if ($page instanceof Page && $page->id && $page->hasField('plf_freebie') && $page->get('plf_freebie')) {
        $e->return = true;
      }
    });

    // Auto-gate: any page flagged plf_freebie=1 is access-gated automatically —
    // analogous to SPL's requires_access. The CMS checkbox alone is enough; no
    // requireFreebieAccess() call in the template. Runs before the template (and
    // before SPL's handleAccessParam) so the ?access= magic link is consumed first.
    $this->addHookBefore('Page::render', function(\ProcessWire\HookEvent $e) {
      $page = $e->object;
      if (!($page instanceof Page) || !$page->id) return;
      if ($page->id !== (int) $this->wire('page')->id) return; // only the main requested page
      if (!$page->hasField('plf_freebie') || !$page->get('plf_freebie')) return;
      $this->requireFreebieAccess($page);
    });

    // Auto-open the register modal after a requireFreebieAccess() redirect:
    // injects the modal + auto-open script (+ AJAX handler, if not already present).
    $this->addHookAfter('Page::render', function(\ProcessWire\HookEvent $e) {
      $session   = $this->wire('session');
      $freebieId = (int) $session->get('plf_open_register');
      if (!$freebieId) return;

      // Logged in → clean up the flag, open nothing
      if ($this->wire('user')->isLoggedin()) { $session->remove('plf_open_register'); return; }

      $html = (string) $e->return;
      if (stripos($html, '</body>') === false) return; // only complete HTML pages
      $session->remove('plf_open_register');

      $freebie = $this->wire('pages')->get($freebieId);
      $modal = $this->renderRegisterModal(['freebie' => ($freebie && $freebie->id) ? $freebie : null]);
      if ($modal === '') return;

      $inject = $modal;
      // Only ship SPL's AJAX form handler if it isn't already on the page anyway
      if (strpos($html, 'data-ajax="pw-json"') === false) {
        $inject .= $this->mod->modal()->globalAjaxHandlerJs();
      }
      $inject .= '<script>
        (function(){
          function open(){
            if(!window.bootstrap){ return setTimeout(open, 50); }
            var el = document.getElementById("plfRegisterModal");
            if(el) bootstrap.Modal.getOrCreateInstance(el).show();
          }
          if(document.readyState==="loading") document.addEventListener("DOMContentLoaded", open);
          else open();
        })();
      </script>';

      $e->return = preg_replace('~</body>~i', $inject . '</body>', $html, 1);
    });

    // Provide the registration modal (#plfRegisterModal) wherever SPL's login modal
    // appears, so core's "register" link (pl_login_procedure → showRegisterLink) has
    // a target. The login procedure is decided by SPL core; this addon only supplies
    // the modal it owns. The AJAX handler already ships with SPL's render() output.
    $this->addHookAfter('Page::render', function(\ProcessWire\HookEvent $e) {
      if (!(bool) $this->mod->showRegisterLink) return;
      $html = (string) $e->return;
      if (stripos($html, 'id="loginModal"') === false) return;       // only where the login modal is
      if (stripos($html, 'id="plfRegisterModal"') !== false) return; // already present (e.g. gate auto-open)

      $account = $this->wire('pages')->get('/account/');
      $return  = ($account && $account->id) ? $account->httpUrl : $this->wire('pages')->get('/')->httpUrl;
      $modal   = $this->renderRegisterModal(['return_url' => $return]);
      if ($modal === '') return;

      $e->return = preg_replace('~</body>~i', $modal . '</body>', $html, 1);
    });
  }

  /**
   * Config-gated provisioning. Called from the core module's Modules::saveConfig
   * hook when the StripePaymentLinks config is saved. Each piece is provisioned
   * independently by what's actually enabled: the `member` role for any signup
   * (registration link / register template / freebies), register-template fields
   * for a dedicated register page, and the freebie fields for Freebie templates.
   * Nothing enabled → nothing provisioned (a pure-selling install / plain core
   * upgrade never gets these fields): feature present, but dormant.
   *
   * @param array $data The saved StripePaymentLinks config.
   */
  public function provision(array $data): void {
    $freebieTpls = (array) ($data['freebieTemplateNames'] ?? []);
    $regTpl      = (string) ($data['freebieRegisterTemplate'] ?? '');
    $showReg     = !empty($data['showRegisterLink']);

    // The `member` role is needed for ANY member signup — registration alone
    // (showRegisterLink) is enough; it does NOT depend on freebie templates.
    if ($showReg || $regTpl !== '' || $freebieTpls) $this->ensureMemberRole();

    // Register template fields (only for a dedicated register page).
    if ($regTpl !== '') $this->ensureRegisterFields($regTpl);

    // Freebie gating fields (only when Freebie templates are configured).
    if ($freebieTpls) {
      $this->ensureFreebieField($freebieTpls);
      $this->ensurePlFreeAccessField($freebieTpls);
    }
    // Nothing configured → nothing provisioned (feature stays dormant).
  }

  /**
   * Ensure the `member` role exists — this module's own access role for freebies
   * (analogous to SPL's `customer`). Makes StripePlFreebies work standalone: the
   * role is created on install/config-save so createOrGetMember()/hasFreebieAccess()
   * have it. Idempotent; reuses an existing `member` role if present.
   */
  private function ensureMemberRole(): void {
    $roles = $this->wire('roles');
    if ($roles->get('member')->id) return;
    try {
      $role = $roles->add('member');
      if (!$role || !$role->id) throw new \Exception('roles->add("member") failed');
    } catch (\Throwable $e) {
      $this->wire('log')->save('stripeplfreebies', 'ensureMemberRole error: ' . $e->getMessage());
    }
  }

  /* ========================= SPL integration ========================= */

  /** Absolute paths to SPL's UI building blocks (ModalRenderer + modal view). */
  private function splUiPaths(): array {
    $base = dirname($this->wire("modules")->getModuleFile('StripePaymentLinks'));
    return [
      'renderer' => $base . '/includes/ui/ModalRenderer.php',
      'view'     => $base . '/includes/views/modal.php',
    ];
  }

  private function j(array $a, int $status = 200): string {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    return json_encode($a, JSON_UNESCAPED_UNICODE);
  }

  /* ========================= Texts (i18n) ========================= */

  private function i18n(): array {
    return [
      'register.title'        => $this->_('Get free access'),
      'register.intro'        => $this->_('Enter your details and we’ll email you an access link.'),
      'register.submit'       => $this->_('Send me the link'),
      'register.error'        => $this->_('Something went wrong. Please try again.'),
      'register.cancel'       => $this->_('Cancel'),
      'label.email'           => $this->_('Email'),
      'label.name'            => $this->_('Name'),
      'mail.subject'          => $this->_('Your free access'),
      'mail.greeting'         => $this->_('Hi {name},'),
      'mail.body'             => $this->_('thanks for your interest! Click the button below to access your free content:'),
      'mail.body_existing'    => $this->_('you already have an account. Click the button below to sign in directly without a password — your existing password still works, of course.'),
      'mail.cta'              => $this->_('Open now'),
      'mail.consent'          => $this->_('By clicking the link you agree that we may send you emails. You can withdraw this consent at any time.'),
      'mail.intro_default'    => $this->_('thanks for your interest!'),
      'mail.signature'        => $this->_('Warm regards'),
      'mail.footer'           => $this->_('This email was sent automatically.'),
      'freebies.granted'      => $this->_('Your freebies'),
      'freebies.available'    => $this->_('More free content'),
      'freebies.none'         => $this->_('No freebies yet.'),
      'api.ok_generic'        => $this->_('Thanks! If everything is correct, you’ll receive an email with your access link shortly.'),
      'api.csrf_invalid'      => $this->_('Invalid security token.'),
      'api.email_missing'     => $this->_('Please enter a valid email address.'),
    ];
  }

  private function tLocal(string $key): string {
    static $L = null;
    if ($L === null) $L = $this->i18n();
    return $L[$key] ?? $key;
  }

  /* ========================= Public methods (template API) ========================= */

  /**
   * Checks whether a user has access to a freebie.
   *
   * Freebies are lead magnets: the email address is the price, paid once. Any
   * member (i.e. anyone who has registered / handed over their address) may
   * therefore access ALL freebies — no per-freebie grant required. The explicit
   * per-freebie grant (plf_free_access) only serves as a fallback for logged-in
   * users without the member role.
   */
  public function hasFreebieAccess(User $user, Page $freebie): bool {
    if ($user->isSuperuser()) return true;
    if (!$user->isLoggedin()) return false;
    if ($user->hasRole('member')) return true;
    if (!$user->hasField('plf_free_access')) return false;
    return (bool) $user->plf_free_access->has($freebie);
  }

  /**
   * Grants a freebie to a user (idempotent).
   */
  public function grantFreebie(User $user, Page $freebie): void {
    if (!$user->hasField('plf_free_access')) return;
    if ($user->plf_free_access->has($freebie)) return;
    $user->of(false);
    $user->plf_free_access->add($freebie);
    $this->wire('users')->save($user, ['quiet' => true]);
  }

  /**
   * Guard for freebie templates: no access → register modal/redirect.
   * Call at the top of the template of a protected freebie page.
   */
  public function requireFreebieAccess(Page $freebie, string $registerUrl = ''): void {
    $user  = $this->wire('user');
    $input = $this->wire('input');

    // Process the magic link FIRST: the auto-gate hook runs before SPL's
    // handleAccessParam() in _ah_main → otherwise the redirect would intercept the
    // ?access=/?t= token. handleAccessParam() is public → call it here beforehand.
    if (!$user->isLoggedin() && ($input->get('access') || $input->get('t'))) {
      $this->mod->handleAccessParam();
      $user = $this->wire('user'); // forceLogin changed the current user
    }

    if ($this->hasFreebieAccess($user, $freebie)) return;

    $session = $this->wire('session');
    $session->set('pl_intended_url', $freebie->httpUrl);          // target after magic link
    $session->set('plf_open_register', (int) $freebie->id);       // open register modal

    // Loop-safe: NEVER redirect to the gated page itself (otherwise endless loop).
    $home = $this->wire('pages')->get('/')->httpUrl;
    $dest = $registerUrl ?: ($this->resolveRegisterUrl($freebie) ?: $home);
    if (rtrim($dest, '/') === rtrim($freebie->httpUrl, '/')) $dest = $home;
    $session->redirect($dest, false);
  }

  /**
   * Resolves the register page generically (configurable):
   *  1) child of the freebie using the configured register template (per-freebie)
   *  2) globally configured register page
   *  3) '' → caller uses the fallback (home + auto-open modal)
   */
  private function resolveRegisterUrl(?Page $freebie = null): string {
    $p = $this->resolveRegisterPage($freebie);
    return ($p && $p->id) ? $p->httpUrl : '';
  }

  /** Like resolveRegisterUrl, but returns the Page (for mail texts from its fields). */
  public function resolveRegisterPage(?Page $freebie = null): ?Page {
    $tplName = $this->wire('sanitizer')->name((string) $this->mod->get('freebieRegisterTemplate'));
    if ($tplName !== '' && $freebie && $freebie->id) {
      $child = $freebie->child("template=$tplName, include=all");
      if ($child && $child->id) return $child;
    }
    $pid = (int) $this->mod->get('freebieRegisterPage');
    if ($pid) {
      $p = $this->wire('pages')->get($pid);
      if ($p && $p->id) return $p;
    }
    return null;
  }

  /**
   * Structured freebie data (build your own markup).
   * $opts['pages'] = PageArray of freebies (otherwise empty).
   */
  public function getFreebiesData(?User $user = null, array $opts = []): array {
    $user ??= $this->wire('user');
    /** @var PageArray $pages */
    $pages = $opts['pages'] ?? $this->findFreebies();
    $rows = [];
    foreach ($pages as $p) {
      $thumb = '';
      if ($p->hasField('images') && $p->images->count()) {
        $thumb = $p->images->first()->size(800, 600)->url;
      }
      $rows[] = [
        'id'      => (int) $p->id,
        'title'   => (string) $p->title,
        'url'     => $p->httpUrl,
        'thumb'   => $thumb,
        'granted' => $this->hasFreebieAccess($user, $p),
      ];
    }
    return $rows;
  }

  /**
   * Freebie grid: granted ones on top (full color), available ones below (teaser).
   * Counterpart to StripePlCustomerPortal::renderPurchasesGridAll().
   */
  /**
   * Bare card columns (col-*) of the freebies + cardCss — for hooking into an
   * EXISTING .row g-3, e.g. directly after the products via
   * StripePlCustomerPortal::renderAccount($view, $appendCards).
   * Identical appearance to the purchased products (renderCard, single source).
   */
  public function renderFreebieCards(?User $user = null, array $opts = []): string {
    /** @var PageArray $pages */
    $pages = $opts['pages'] ?? $this->findFreebies();
    if (!$pages || !$pages->count()) return '';

    // CustomerPortal is OPTIONAL: if present, use its card renderer for an identical
    // look; otherwise a slim own fallback card (Bootstrap).
    $cp = $this->wire("modules")->isInstalled('StripePlCustomerPortal')
        ? $this->wire("modules")->get('StripePlCustomerPortal') : null;

    $out = '';
    foreach ($pages as $p) {
      $thumb = ($p->hasField('images') && $p->images->count())
        ? $p->images->first()->size(800, 600)->url : '';
      $out .= $cp
        ? $cp->renderCard((string) $p->title, $p->httpUrl, $thumb, '', '', $p)
        : $this->fallbackCard((string) $p->title, $p->httpUrl, $thumb);
    }
    return ($cp ? $cp->cardCss() : '') . $out;
  }

  /** Slim fallback card if StripePlCustomerPortal is not installed. */
  private function fallbackCard(string $title, string $url, string $thumbUrl = ''): string {
    $h   = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
    $img = $thumbUrl ? '<img class="card-img-top" src="' . $h($thumbUrl) . '" alt="">' : '';
    return '<div class="col-12 col-sm-6 col-lg-4"><div class="card h-100 shadow-sm">'
         . $img . '<div class="card-body"><h4 class="card-title mb-0">'
         . '<a href="' . $h($url) . '" class="stretched-link text-decoration-none text-reset">'
         . $h($title) . '</a></h4></div></div></div>';
  }

  /** Standalone freebie grid (renderFreebieCards in its own .row g-3). */
  public function renderFreebies(?User $user = null, array $opts = []): string {
    $cards = $this->renderFreebieCards($user, $opts);
    return $cards === '' ? '' : '<div class="row g-3">' . $cards . '</div>';
  }

  /**
   * Resolves the freebie for a register page: its parent, provided that parent's
   * template is configured as a freebie template or is marked via plf_freebie.
   * (Register pages are children of the freebie, see resolveRegisterPage.)
   */
  private function freebieForRegisterPage(?Page $registerPage): ?Page {
    if (!$registerPage || !$registerPage->id) return null;
    $parent = $registerPage->parent;
    if (!$parent || !$parent->id) return null;
    $names = $this->getFreebieTemplateNames();
    if ($names && in_array((string) $parent->template->name, $names, true)) return $parent;
    if ($parent->hasField('plf_freebie') && $parent->plf_freebie) return $parent;
    return null;
  }

  /**
   * Freebie context (id + return_url). Derives the freebie itself — explicitly via
   * opts, otherwise from the current register page (parent), otherwise from the
   * session set by the gate. Callers don't have to pass anything.
   */
  private function registerContext(array $opts): array {
    $session = $this->wire('session');
    $page    = $this->wire('page');

    $freebie = $opts['freebie'] ?? null;
    if (!($freebie instanceof Page) || !$freebie->id) $freebie = $this->freebieForRegisterPage($page);

    $freebieId = ($freebie instanceof Page && $freebie->id)
      ? (int) $freebie->id
      : (int) ($opts['freebie_id'] ?? $session->get('plf_open_register'));

    $intended  = (string) $session->get('pl_intended_url');
    $returnUrl = (string) ($opts['return_url']
      ?? (($freebie instanceof Page && $freebie->id) ? $freebie->httpUrl : ($intended ?: $page->httpUrl)));

    return [$freebieId, $returnUrl];
  }

  /**
   * Visible inline registration form (for dedicated register pages).
   * Posts op=freebie_register to SPL's API; SPL's globalAjaxHandlerJs handles
   * the AJAX submit (shows the "check your inbox" success message). $opts['freebie']/['return_url'].
   */
  public function renderRegisterForm(array $opts = []): string {
    [$freebieId, $returnUrl] = $this->registerContext($opts);
    // The inline form IS the registration UI of this page → suppress the auto-open
    // modal (fallback for pages without a form) here, otherwise it would appear twice.
    $this->wire('session')->remove('plf_open_register');

    $spl  = $this->mod;
    $h    = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
    $csrf = $spl->wire('session')->CSRF->renderInput();

    // Button label from the module's own field plf_form_button of the current page.
    $page = $this->wire('page');
    $submitLabel = ($page->hasField('plf_form_button') && trim((string) $page->get('plf_form_button')) !== '')
      ? trim((string) $page->get('plf_form_button')) : $this->tLocal('register.submit');

    // Step 1 = intro (plf_intro, otherwise i18n) + form    →  #plfStep1
    // Step 2 = confirmation (plf_success, otherwise i18n)   →  #plfStep2 (hidden)
    // On successful submit: hide ALL of step 1, show step 2.
    $introHtml = (string) ($opts['introHtml'] ?? '');
    if ($introHtml === '') {
      $introHtml = ($page->hasField('plf_intro') && trim((string) $page->get('plf_intro')) !== '')
        ? (string) $page->get('plf_intro')
        : ((($opts['intro'] ?? true) !== false)
            ? '<h3 class="mb-3 text-center">' . $h($this->tLocal('register.title')) . '</h3><p class="text-center">' . $h($this->tLocal('register.intro')) . '</p>'
            : '');
    }

    $step2 = ($page->hasField('plf_success') && trim((string) $page->get('plf_success')) !== '')
      ? (string) $page->get('plf_success')
      : '<p>' . $h($this->tLocal('api.ok_generic')) . '</p>';

    $errGeneric = json_encode($this->tLocal('register.error'), JSON_UNESCAPED_UNICODE);

    return '
    <div id="plfStep1">
      ' . $introHtml . '
      <form id="plfRegisterForm" action="' . $h($spl->apiUrl()) . '" method="post" class="plf-register-form">
        ' . $csrf . '
        <input type="hidden" name="op" value="freebie_register">
        <input type="hidden" name="freebie_id" value="' . (int) $freebieId . '">
        <input type="hidden" name="return_url" value="' . $h($returnUrl) . '">
        <div class="row g-3 mb-3">
          <div class="col-md-6">
            <label class="form-label">' . $h($this->tLocal('label.name')) . '</label>
            <input type="text" name="name" class="form-control" autocomplete="name">
          </div>
          <div class="col-md-6">
            <label class="form-label">' . $h($this->tLocal('label.email')) . '</label>
            <input type="email" name="email" class="form-control" required autocomplete="email">
          </div>
        </div>
        <div id="plfFormError" class="alert alert-danger" style="display:none"></div>
        <button type="submit" class="btn btn-primary btn-lg">' . $h($submitLabel) . '</button>
      </form>
    </div>
    <div id="plfStep2" style="display:none">' . $step2 . '</div>
    <script>
    (function(){
      var f = document.getElementById("plfRegisterForm");
      if (!f) return;
      f.addEventListener("submit", async function(ev){
        ev.preventDefault();
        var err = document.getElementById("plfFormError");
        if (err) { err.style.display = "none"; err.textContent = ""; }
        var btn = f.querySelector("[type=submit]"); if (btn) btn.disabled = true;
        try {
          var res = await fetch(f.action, { method: "POST", body: new FormData(f), credentials: "same-origin" });
          var j = await res.json();
          if (!j.ok) { if (err) { err.textContent = j.error || ' . $errGeneric . '; err.style.display = "block"; } if (btn) btn.disabled = false; return; }
          var s1 = document.getElementById("plfStep1"); if (s1) s1.style.display = "none";
          var s2 = document.getElementById("plfStep2"); if (s2) s2.style.display = "";
        } catch(e) {
          if (err) { err.textContent = ' . $errGeneric . '; err.style.display = "block"; }
          if (btn) btn.disabled = false;
        }
      });
    })();
    </script>';
  }

  /** Registration form as a Bootstrap modal (#plfRegisterModal) — for the gate auto-open. */
  public function renderRegisterModal(array $opts = []): string {
    $paths = $this->splUiPaths();
    if (is_file($paths['renderer'])) require_once $paths['renderer'];
    if (!class_exists('\ProcessWire\ModalRenderer')) return '';

    $spl = $this->mod;
    $ui = new \ProcessWire\ModalRenderer(
      is_file($paths['view']) ? $paths['view'] : null,
      function () use ($spl) { return $spl->wire('session')->CSRF->renderInput(); }
    );

    [$freebieId, $returnUrl] = $this->registerContext($opts);

    $spec = [
      'id'    => 'plfRegisterModal',
      'title' => htmlspecialchars($this->tLocal('register.title'), ENT_QUOTES),
      'form'  => [
        'action'    => $spl->apiUrl(),
        'op'        => 'freebie_register',
        'hidden'    => ['freebie_id' => (string) $freebieId, 'return_url' => $returnUrl],
        'bodyIntro' => '<p>' . htmlspecialchars($this->tLocal('register.intro'), ENT_QUOTES) . '</p>',
        'fields'    => [
          ['type'=>'text','name'=>'name','label'=>$this->tLocal('label.name'),'attrs'=>['autocomplete'=>'name']],
          ['type'=>'email','name'=>'email','label'=>$this->tLocal('label.email'),'attrs'=>['required'=>true,'autocomplete'=>'email']],
        ],
        'submitText' => $this->tLocal('register.submit'),
        'cancelText' => $this->tLocal('register.cancel'),
      ],
    ];

    return $ui->render($spec);
  }

  /**
   * Sends the freebie access mail (magic link). HOOKABLE: sites build it via
   * addHookBefore('StripePlFreebies::sendFreebieMail', fn, ['replace'=>true]) entirely
   * from their own CMS fields (e.g. this site's ah_register fields, see ready.php).
   * Default: neutral, freebie-suitable mail (NO purchase/withdrawal text).
   *
   * @param User       $user
   * @param Page|null  $freebie       The freebie (for title/image).
   * @param Page|null  $registerPage  The register page (for texts, if present).
   * @param string     $magicUrl      The ?access= link.
   * @return bool
   */
  public function ___sendFreebieMail(User $user, ?Page $freebie, ?Page $registerPage, string $magicUrl, bool $isNew = true): bool {
    $reg    = ($registerPage && $registerPage->id) ? $registerPage : null;
    $parent = $reg ? $reg->parent : ($freebie ?: null);

    // Mail contents from the MODULE'S OWN fields of the register page
    // (ensureRegisterFields creates them). Fallbacks via i18n → works even when
    // unfilled. No site-specific field names.
    $rv = function($fname) use ($reg) { return ($reg && $reg->hasField($fname)) ? trim((string) $reg->get($fname)) : ''; };

    $firstName = trim((string) ($user->get('user_name') ?: $user->title));
    if ($firstName === '') { $at = strpos((string) $user->email, '@'); $firstName = $at !== false ? substr($user->email, 0, $at) : (string) $user->email; }

    $title     = ($freebie && $freebie->id) ? (string) $freebie->title : ($reg ? (string) $reg->title : '');
    $subject   = $rv('plf_mail_subject') !== '' ? $rv('plf_mail_subject') : $this->tLocal('mail.subject') . ($title !== '' ? ' – ' . $title : '');
    $greetRaw  = $rv('plf_mail_greeting');
    $greeting  = $greetRaw !== '' ? strtr($greetRaw, ['VORNAME' => $firstName, 'NAME' => $firstName]) : strtr($this->tLocal('mail.greeting'), ['{name}' => $firstName]);
    // New members: freebie welcome body (CMS plf_mail_body, else i18n mail.body).
    // Existing members re-registering: a distinct "you already have an account"
    // text so they aren't confused. The on-screen response stays generic (no
    // email enumeration); this differentiation is safe because only the real
    // account owner ever reads this mail.
    $bodyHtml  = !$isNew
      ? $this->tLocal('mail.body_existing')
      : ($rv('plf_mail_body') !== '' ? $rv('plf_mail_body') : $this->tLocal('mail.body'));
    $btn       = $rv('plf_mail_button') !== '' ? $rv('plf_mail_button') : $this->tLocal('mail.cta');
    $signature = $this->tLocal('mail.signature');

    // Freebie branding: the freebie's own colour + hero image (so the mail is
    // freebie-branded, not just the generic site layout).
    $src   = $freebie ?: $parent;
    $color = ($src && $src->hasField('color') && $src->get('color')) ? (string) $src->get('color') : '';
    $adjust = function(string $hex, int $steps): string {
      $hex = ltrim($hex, '#');
      if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
      if (strlen($hex) !== 6) return '#0d6efd';
      $r = max(0, min(255, hexdec(substr($hex,0,2)) + $steps));
      $g = max(0, min(255, hexdec(substr($hex,2,2)) + $steps));
      $b = max(0, min(255, hexdec(substr($hex,4,2)) + $steps));
      return sprintf('#%02x%02x%02x', $r, $g, $b);
    };
    $heroUrl = '';
    if ($freebie && $freebie->hasField('images') && $freebie->images->count())      $heroUrl = $freebie->images->first()->size(1200, 600)->httpUrl;
    elseif ($parent && $parent->hasField('images') && $parent->images->count())      $heroUrl = $parent->images->first()->size(1200, 600)->httpUrl;

    // Route through SPL's shared branded layout so the freebie mail keeps the same
    // structure as the other mails. The rich body (plf_mail_body) goes into the raw
    // leadHtml slot; consent + signature map to closingText/signatureName.
    $vars = [
      'preheader'     => mb_substr(trim(strip_tags($bodyHtml)) ?: $greeting, 0, 140),
      'productTitle'  => $title,
      'headline'      => $greeting,
      'leadHtml'      => $bodyHtml,
      'productUrl'    => $magicUrl,
      'ctaText'       => $btn,
      // Consent line only for NEW signups (double opt-in). Existing members have
      // already consented — don't ask them again; just the signature.
      'closingText'   => $isNew ? $this->tLocal('mail.consent') : '',
      'signatureName' => $signature,
    ];
    if ($color !== '') {
      $vars['brandColor'] = '#' . ltrim($color, '#'); // headline + links
      $vars['bgColor']    = $adjust($color, 50);       // lighter outer background
      $vars['btnColor']   = $adjust($color, -50);      // darker CTA button
    }
    if ($heroUrl !== '') $vars['heroUrl'] = $heroUrl;  // full-width hero image (own var, not SPL's logoUrl)

    return $this->mod->mail()->sendLayoutMail($this->mod, (string) $user->email, $subject, $vars);
  }

  /* ========================= API: op=freebie_register ========================= */

  private function handleApi(\ProcessWire\HookEvent $e): void {
    $input = $this->wire('input');
    if (!$input->requestMethod('POST')) return;
    $op = (string) ($input->post->op ?? $input->post->action ?? '');
    if ($op !== 'freebie_register') return;

    $e->replace = true;
    $session   = $this->wire('session');
    $sanitizer = $this->wire('sanitizer');

    if (!$session->CSRF->hasValidToken()) {
      $e->return = $this->j(['ok' => false, 'error' => $this->tLocal('api.csrf_invalid')], 400);
      return;
    }

    $email = $sanitizer->email((string) $input->post->email);
    $name  = trim((string) $input->post->text('name'));
    $freebieId = (int) $input->post->int('freebie_id');
    $returnUrl = $sanitizer->url((string) $input->post->return_url);

    if (!$email) {
      $e->return = $this->j(['ok' => false, 'error' => $this->tLocal('api.email_missing')]);
      return;
    }

    try {
      $res  = $this->createOrGetMember($email, $name);
      $user = $res['user'];

      $freebie = $freebieId ? $this->wire('pages')->get($freebieId) : null;
      if ($freebie && $freebie->id) $this->grantFreebie($user, $freebie);

      // Magic link: ?access=TOKEN → SPL::handleAccessParam() logs in automatically.
      $token = $this->issueAccessToken($user, self::MAGIC_TTL_MINUTES * 60);
      $target = ($freebie && $freebie->id) ? $freebie->httpUrl
              : ($returnUrl ?: $this->wire('pages')->get('/')->httpUrl);
      // The magic link ends up in an email → it MUST be absolute. A posted
      // return_url may be a relative root path (e.g. "/account/").
      if (!preg_match('~^https?://~i', $target)) {
        $cfg    = $this->wire('config');
        $target = ($cfg->https ? 'https://' : 'http://') . $cfg->httpHost . '/' . ltrim($target, '/');
      }
      $glue = (strpos($target, '?') === false) ? '?' : '&';
      $magicUrl = $target . $glue . 'access=' . urlencode($token);

      // Freebie mail (NOT SPL's purchase mail). Hookable: sites can build it via
      // addHookBefore('StripePlFreebies::sendFreebieMail', ... $e->replace=true)
      // entirely from their own CMS fields (see site/ready.php).
      $registerPage = $this->resolveRegisterPage($freebie);
      $this->sendFreebieMail($user, ($freebie && $freebie->id) ? $freebie : null, $registerPage, $magicUrl, (bool) ($res['isNew'] ?? true));

    } catch (\Throwable $ex) {
      $this->wire('log')->save('stripeplfreebies',
        'freebie_register error: ' . $ex->getMessage() . ' @ ' . $ex->getFile() . ':' . $ex->getLine());
      // Intentionally no detail disclosure; generic OK response (no email enumeration).
    }

    // Always generic „OK" (no email enumeration)
    $e->return = $this->j(['ok' => true, 'msg' => $this->tLocal('api.ok_generic')]);
  }

  /* ========================= Helpers: user + token ========================= */

  /**
   * Finds/creates a user with role `member`.
   * (SPL::createUserFromStripe/ensureCustomerRole are protected → here an own,
   *  member-specific variant.)
   *
   * @return array{user: User, isNew: bool}
   */
  private function createOrGetMember(string $email, string $name = ''): array {
    $users     = $this->wire('users');
    $sanitizer = $this->wire('sanitizer');

    $user  = $users->get('email=' . $sanitizer->selectorValue($email));
    $isNew = false;

    if (!$user || !$user->id) {
      $isNew = true;
      $user = new User();
      $user->name  = $sanitizer->pageName($email);
      $user->email = $email;
      $user->pass  = bin2hex(random_bytes(8));
      if ($name && $user->hasField('title')) $user->title = $name;
      if ($user->hasField('must_set_password')) $user->must_set_password = 1;
      $user->addRole('member'); // role before the save → included in the (successful) insert
      try {
        $users->save($user);
      } catch (\Throwable $e) {
        // Context-specific: this registration runs inside the API endpoint hook
        // (/stripepaymentlinks/api, 404 context). There Page::localPath
        // (LanguageSupportPageNames) is not hooked for the user page, and the
        // core PagePaths move hook calls it on creation → exception AFTER the
        // DB insert. (SPL::createUserFromStripe is NOT affected, because it creates
        // users during a normal page render.) The user is persisted
        // → reload and continue; only re-throw on a real failure.
        $reloaded = $users->get('email=' . $sanitizer->selectorValue($email));
        if ($reloaded && $reloaded->id) { $user = $reloaded; }
        else { throw $e; }
      }
    } else {
      $user->of(false);
      if (!$user->hasRole('member')) { $user->addRole('member'); $users->save($user, ['quiet' => true]); }
      if ($name && $user->hasField('title') && (string) $user->title === '') {
        $user->title = $name;
        $users->save($user, ['quiet' => true]);
      }
    }

    return ['user' => $user, 'isNew' => $isNew];
  }

  /**
   * Creates an access_token (+expiry) on the user. Counterpart to SPL's (protected)
   * createAccessToken(); uses the same fields that SPL::handleAccessParam() checks.
   */
  private function issueAccessToken(User $user, int $ttlSeconds): string {
    $token = bin2hex(random_bytes(32));
    $user->of(false);
    if ($user->hasField('access_token'))   $user->access_token   = $token;
    if ($user->hasField('access_expires')) $user->access_expires = time() + max(60, $ttlSeconds);
    $this->wire('users')->save($user, ['quiet' => true]);
    return $token;
  }

  /* ========================= Freebie source (global) ========================= */

  /** Configured freebie template names. */
  private function getFreebieTemplateNames(): array {
    $raw = (array) $this->mod->get('freebieTemplateNames');
    $san = $this->wire('sanitizer');
    return array_values(array_unique(array_filter(array_map([$san, 'name'], $raw))));
  }

  /**
   * Finds all freebies globally: pages with plf_freebie=1 (optionally restricted
   * to the configured templates). NO dependency on a fixed page.
   */
  public function findFreebies(): PageArray {
    $names = $this->getFreebieTemplateNames();
    $sel = 'plf_freebie=1, include=hidden, sort=-created';
    if ($names) $sel = 'template=' . implode('|', $names) . ', ' . $sel;
    return $this->wire('pages')->find($sel);
  }

  /**
   * Provides the checkbox field `plf_freebie` and attaches it to the configured
   * templates (mirror of SPL::ensureProductFields). Idempotent.
   */
  private function ensureFreebieField(?array $templateNames = null): void {
    $fields    = $this->wire('fields');
    $templates = $this->wire('templates');
    $name      = 'plf_freebie';

    $f = $fields->get($name);
    if (!$f || !$f->id) {
      $f = new Field();
      $f->type        = $this->wire("modules")->get('FieldtypeCheckbox');
      $f->name        = $name;
      $f->label       = $this->_('Freebie');
      $f->description = $this->_('Mark this page as a free, registration-gated freebie.');
      $fields->save($f);
    }

    $names = $templateNames ?? $this->getFreebieTemplateNames();
    foreach ($names as $tn) {
      $t = $templates->get($tn);
      if (!$t || !$t->id) continue;
      if (!$t->fieldgroup->hasField($name)) {
        $t->fieldgroup->add($f);
        $t->fieldgroup->save();
      }
    }
  }

  /**
   * Own grant field `plf_free_access` (FieldtypePage, multi) on the user template.
   * Selectable pages = the configured freebie templates (otherwise the field can
   * neither be filled nor used in the admin). Follows the config: when the freebie
   * templates change, the allowed templates are updated. Idempotent.
   *
   * @param array|null $freebieTemplateNames Freebie templates (otherwise from config).
   */
  private function ensurePlFreeAccessField(?array $freebieTemplateNames = null): void {
    $fields    = $this->wire('fields');
    $templates = $this->wire('templates');
    $name      = 'plf_free_access';

    $f = $fields->get($name);
    if (!$f || !$f->id) {
      $f = new Field();
      $f->type        = $this->wire("modules")->get('FieldtypePage');
      $f->name        = $name;
      $f->label       = $this->_('Freebie access');
      $f->description = $this->_('Freebies this user has been granted access to.');
      $f->set('derefAsPage', 0);                 // PageArray (multi)
      $f->set('inputfield', 'InputfieldAsmSelect');
      $fields->save($f);
    }

    // Selectable pages: bind to the freebie templates AND only allow pages
    // that are actually marked as freebies (plf_freebie=1) — via findPagesSelector.
    $names = $freebieTemplateNames ?? $this->getFreebieTemplateNames();
    $ids   = [];
    foreach ($names as $tn) { $tpl = $templates->get($this->wire('sanitizer')->name($tn)); if ($tpl && $tpl->id) $ids[] = (int) $tpl->id; }
    $ids = array_values(array_unique($ids));

    // include=hidden: freebie pages are usually hidden → otherwise the inputfield finds nothing.
    $wantSel = ($names ? 'template=' . implode('|', $names) . ', ' : '') . 'plf_freebie=1, include=hidden';

    $curIds = array_map('intval', (array) $f->get('template_ids'));
    sort($curIds); $wantIds = $ids; sort($wantIds);
    $curSel = (string) $f->get('findPagesSelector');
    if ($curIds !== $wantIds || $curSel !== $wantSel) {
      $f->set('template_id', $ids[0] ?? 0);     // single (legacy)
      $f->set('template_ids', $ids);            // multiple
      $f->set('findPagesSelector', $wantSel);   // only marked freebies
      $fields->save($f);
    }

    $t = $templates->get('user');
    if ($t && $t->id && !$t->fieldgroup->hasField($name)) {
      $t->fieldgroup->add($f);
      $t->fieldgroup->save();
    }
  }

  /**
   * Creates the complete, module-owned field set on the configured
   * register template, so the module is immediately ready to use on EVERY
   * installation (analogous to SPL::ensureProductFields). Rich-text fields as
   * TinyMCE. Idempotent — existing fields are left untouched.
   *
   * @param string|null $tplName Register template (otherwise from config).
   */
  private function ensureRegisterFields(?string $tplName = null): void {
    $san     = $this->wire('sanitizer');
    $tplName = $san->name(($tplName !== null && $tplName !== '') ? $tplName : (string) $this->mod->get('freebieRegisterTemplate'));
    if ($tplName === '') return;

    $t = $this->wire('templates')->get($tplName);
    if (!$t || !$t->id) return;

    $fields  = $this->wire('fields');
    $hasTiny = $this->wire("modules")->isInstalled('InputfieldTinyMCE');
    $rich    = $hasTiny ? 'InputfieldTinyMCE' : 'InputfieldCKEditor';

    // name => [fieldtype, inputfieldClass|'', label, description]
    $defs = [
      'plf_intro'         => ['FieldtypeTextarea', $rich, $this->_('Text – step 1'),       $this->_('Shown on first page load, together with the registration form.')],
      'plf_form_button'   => ['FieldtypeText',     '',    $this->_('Form button label'),    $this->_('Label of the registration form button.')],
      'plf_redirect'      => ['FieldtypeURL',      '',    $this->_('Redirect after sign-up'),$this->_('Optional. URL to send the user to after a successful sign-up. Empty = the freebie page.')],
      'plf_success'       => ['FieldtypeTextarea', $rich, $this->_('Text – step 2'),       $this->_('Shown after the form is submitted and the confirmation email was sent.')],
      'plf_mail_subject'  => ['FieldtypeText',     '',    $this->_('Email: subject'),       ''],
      'plf_mail_greeting' => ['FieldtypeText',     '',    $this->_('Email: greeting'),      $this->_('e.g. "Hey, VORNAME, …". Placeholders VORNAME / NAME are replaced with the first name.')],
      'plf_mail_body'     => ['FieldtypeTextarea', $rich, $this->_('Email: text'),          $this->_('The email content sent to confirm the address.')],
      'plf_mail_button'   => ['FieldtypeText',     '',    $this->_('Email: button label'),  ''],
    ];

    $changed = false;
    foreach ($defs as $fname => [$ftype, $inputfield, $flabel, $fdesc]) {
      $f = $fields->get($fname);
      if (!$f || !$f->id) {
        $f = new Field();
        $f->type        = $this->wire("modules")->get($ftype);
        $f->name        = $fname;
        $f->label       = $flabel;
        if ($fdesc !== '') $f->description = $fdesc;
        $f->tags        = 'plf';
        if ($ftype === 'FieldtypeTextarea' && $inputfield !== '') {
          $f->set('inputfieldClass', $inputfield);
          $f->set('contentType', 1); // 1 = HTML (markup/HTML content)
        }
        $fields->save($f);
      }
      if (!$t->fieldgroup->hasField($fname)) { $t->fieldgroup->add($f); $changed = true; }
    }
    if ($changed) $t->fieldgroup->save();
  }
}
