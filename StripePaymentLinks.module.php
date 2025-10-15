<?php namespace ProcessWire;
require_once __DIR__ . '/includes/PLPurchaseLineHelper.php';

use ProcessWire\Page;
use ProcessWire\User;
use ProcessWire\InputfieldWrapper;
use ProcessWire\WireData;
use ProcessWire\Module;
use ProcessWire\ConfigurableModule;

/**
 * StripePaymentLinks
 *
 * Stripe payment-link redirects, purchase mapping, magic-link soft login,
 * consolidated access emails and Bootstrap modals.
 *
 * Split services live in /includes:
 *  - PLMailService
 *  - PLModalService
 *  - PLApiController
 *
 * PHP 8+, ProcessWire 3.0.210+
 */
 
class StripePaymentLinks extends WireData implements Module, ConfigurableModule {
	
	use PLPurchaseLineHelper;

	/**
 	* Returns ProcessWire module info array.
 	*
 	* @return array Module metadata for ProcessWire.
 	*/
	public static function getModuleInfo(): array {
		return [
			'title'       => 'StripePaymentLinks',
			'version'     => '1.0.11', 
			'summary'     => 'Stripe payment-link redirects, user/purchases, magic link, mails, modals.',
			'author'      => 'frameless Media',
			'autoload'    => true,
			'singular'    => true,
			'icon'        => 'credit-card',
			'requires'    => ['PHP>=8.0', 'ProcessWire>=3.0.210'],
		];
	}

	/** Paths */
	protected string $stripeSdkPath;
	protected string $moduleMailLayout;

	/** Log channels */
	public const LOG_PL   = 'stripepaymentlinks';
	public const LOG_MAIL = 'mail';
	public const LOG_SEC  = 'security';

	/** Services (lazy) */
	private ?PLMailService   $mailService  = null;
	private ?PLModalService  $modalService = null;
	private ?PLApiController $apiService   = null;

	/**
	 * Returns the default internationalized texts used throughout the module.
	 * Keys use the schema: CHANNEL.TYPE.IDENT.
	 *
	 * @return array Associative array of text keys and values.
	 */
	private function defaultTexts(): array {
	  return [
		// ===== MAIL: summary (1..n products) =====
		'mail.single.subject'        => $this->_('Your access'),
		'mail.single.preheader'      => $this->_('You now have access to “{title}”.'),
		'mail.single.body'           => $this->_('You now have access to “{title}”.'),
		'mail.single.cta'            => $this->_('Start now'),
		'mail.single.title'          => $this->_(''),

		'mail.multi.subject'         => $this->_('Your accesses'),
		'mail.multi.preheader'       => $this->_('You unlocked multiple online products: {list}.'),
		'mail.multi.body'            => $this->_('You unlocked multiple online products: {list}.'),
		'mail.multi.cta'             => $this->_('Start now'),
		'mail.multi.title'           => $this->_(''),

		// Common mail elements
		'mail.common.header_tagline'   => $this->_('Access'),
		'mail.common.info_label'       => $this->_('Online product'),
		'mail.common.extra_heading'    => $this->_('Your other access links'),
		'mail.common.closing_text'     => $this->_('Enjoy!'),
		'mail.common.footer_note'      => $this->_('This email was sent automatically.'),
		'mail.common.product_fallback' => $this->_('Product'),
		'mail.common.direct_link' 	   => $this->_('Direct link'),
		'mail.common.greeting'         => $this->_('Hello'),
		
		// ===== MAIL: password reset =====
		'mail.resetpwd.subject'      => $this->_('Reset your password'),
		'mail.resetpwd.preheader'    => $this->_('Click the button to set a new password. The link is time-limited.'),
		'mail.resetpwd.body'         => $this->_('You requested a password reset. Click the button to set a new password.'),
		'mail.resetpwd.cta'          => $this->_('Set new password'),
		'mail.resetpwd.title'        => $this->_('Reset password'),
		'mail.resetpwd.notice' 		 => $this->_('If you did not request a password reset, just ignore this email.'),

		// ===== MODAL: notices =====
		'modal.loginrequired.title'  => $this->_('Sign in'),
		'modal.loginrequired.body'   => $this->_('Please sign in to open this online product.'),
		'modal.expiredaccess.title'  => $this->_('Link expired'),
		'modal.expiredaccess.body'   => $this->_('Your access link is invalid or expired. Please sign in or request a new password-reset link.'),

		// ===== MODAL: login =====
		'modal.login.title'          => $this->_('I already have access'),
		'modal.login.body'           => $this->_(''),
		'modal.login.submit'         => $this->_('Sign in'),
		'modal.login.forgot_link'    => $this->_('Forgot password?'),
		'modal.login.open'           => $this->_('Sign in'),

		// ===== MODAL: request reset =====
		'modal.resetreq.title'       => $this->_('Reset password'),
		'modal.resetreq.body'        => $this->_('Enter your email — we’ll send you a link.'),
		'modal.resetreq.submit'      => $this->_('Send link'),

		// ===== MODAL: reset =====
		'modal.resetexpired.title'   => $this->_('Reset link expired'),
		'modal.resetexpired.body'    => $this->_('Your password reset link is invalid or has expired. You can request a new one.'),
		'modal.resetexpired.request' => $this->_('Request new link'),
		
		// ===== MODAL: set new password via reset link =====
		'modal.resetset.title'       => $this->_('Choose a new password'),
		'modal.resetset.body'        => $this->_('Please set your new password now.'),
		'modal.resetset.submit'      => $this->_('Save'),

		// ===== MODAL: set password directly after purchase =====
		'modal.setpwd.title'         => $this->_('Welcome!'),
		'modal.setpwd.intro'         => $this->_('Hi {firstname}, please set your personal password now. Afterwards you can sign in anytime using {email}.'),
		'modal.setpwd.btn_submit'    => $this->_('Save'),

		// ===== MODAL: duplicate purchase notice =====
		'modal.already_purchased.title' => $this->_('Already purchased'),
		'modal.already_purchased.body'  => $this->_('You already own this product. You can use your existing access.'),
		
		// ===== MODAL: shared labels/buttons =====
		'modal.common.label_password'         => $this->_('Password'),
		'modal.common.label_password_confirm' => $this->_('Confirm password'),
		'modal.common.label_email'            => $this->_('Email'),
		'modal.notice.close'                  => $this->_('Close'),
		'modal.notice.cancel'                 => $this->_('Cancel'),
		'modal.notice.title'                  => $this->_('Notice'),
		'modal.notice.ok'                     => $this->_('OK'),

		// ===== UI (thank-you page access block) =====
		'ui.access.single_hint'     => $this->_('Here is your additional purchased product'),
		'ui.access.multi_hint'      => $this->_('Here are your additional purchased products'),

		// ===== API/JSON responses =====
		'api.method_not_allowed'    => $this->_('Method not allowed'),
		'api.csrf_invalid'          => $this->_('Invalid CSRF'),
		'api.unknown_action'        => $this->_('Unknown action'),

		'api.login.missing_fields'  => $this->_('Please enter email and password.'),
		'api.login.invalid'         => $this->_('Email or password is incorrect.'),
		'api.login.success'         => $this->_('Signed in successfully.'),

		'api.setpwd.not_logged_in'  => $this->_('Not signed in.'),
		'api.setpwd.already_set'    => $this->_('Password was already set.'),
		'api.password.too_short'    => $this->_('At least 8 characters.'),
		'api.password.mismatch'     => $this->_('Passwords do not match.'),
		'api.server_error'          => $this->_('Server error.'),

		'api.resetreq.ok_generic'   => $this->_('If the email exists, a link has been sent.'),
		'api.resetreq.not_config'   => $this->_('Password reset is not configured on the server (fields missing).'),

		'api.resetpwd.token_missing'=> $this->_('Token missing.'),
		'api.resetpwd.token_invalid'=> $this->_('Token invalid or expired.'),
		'api.resetpwd.success'      => $this->_('Password updated.'),

		// ===== JS/AJAX error texts =====
		'ui.ajax.error_generic'     => $this->_('Error'),
		'ui.ajax.error_server'      => $this->_('Server error.'),
	  ];
	}


	/* ========================= Lifecycle ========================= */

	/**
	 * Initializes the module, sets up paths, hooks, and verifies mail layout presence.
	 *
	 * @inheritDoc
	 */
	public function init(): void {
		$this->stripeSdkPath    = __DIR__ . '/vendor/stripe-php/init.php';
		$this->moduleMailLayout = __DIR__ . '/includes/mail/layout.html.php';
		if (!is_file($this->moduleMailLayout)) {
			$this->wire('log')->save(self::LOG_MAIL, 'layout.html.php missing in module (includes/mail); using minimal HTML fallback.');
		}

		// Inject Bootstrap (CSS/JS) early into <head> to avoid FOUC
		$this->addHookAfter('Page::render', function(\ProcessWire\HookEvent $e) {
			$html = (string)$e->return;
			$e->return = $this->renderBootstrapFallback($html);
		});

		$this->addHook('/stripepaymentlinks/api/stripe-webhook', function($event) {
			$this->api()->handleStripeWebhook($event);
		});
		
		$this->addHook('/stripepaymentlinks/api', function($event) {
			$this->api()->handle($event);
		});

		$this->addHookAfter('Modules::saveConfig', function($e){
			$m    = $e->arguments(0);
			$data = (array) $e->arguments(1);
			$name = $m instanceof \ProcessWire\Module ? $m->className() : (string) $m;
			if ($name !== 'StripePaymentLinks') return;

			$this->ensureFields();
			$this->ensureProductFields($data['productTemplateNames'] ?? null);
			$this->ensureCustomerRoleExists();
			$this->triggerSyncStripeCustomers($data);
		});
		$this->addHookAfter('Pages::saveReady', function(\ProcessWire\HookEvent $event) {
			$page = $event->arguments(0);
			$productTemplates = array_map('trim', $this->productTemplateNames ?? []);
			if (in_array($page->template->name, $productTemplates)
				&& $page->isChanged('requires_access')
				&& $page->requires_access
				&& !empty($page->stripe_product_id)){
					$this->updateUserAccessAndNotify($page);
			}
		});
	}
	
	/**
	 * Installs the module, ensuring required fields and roles are present.
	 *
	 * @return void
	 */
	public function ___install(): void {
		$this->ensureFields();
		$this->ensureProductFields();
		$this->ensureCustomerRoleExists();
	}
	
	/**
	 * Upgrades the module, re-ensuring required fields and roles.
	 *
	 * @param mixed $fromVersion Previous version.
	 * @param mixed $toVersion New version.
	 * @return void
	 */
	public function ___upgrade($fromVersion, $toVersion): void {
		$this->ensureFields();
		$this->ensureProductFields();
		$this->ensureCustomerRoleExists();
	}
	
	/**
	 * Uninstalls the module, detaching fields, removing roles, deleting logs, and cleaning up.
	 *
	 * @return void
	 */
	public function ___uninstall(): void {
		$fields     = $this->wire('fields');
		$templates  = $this->wire('templates');
		$roles      = $this->wire('roles');
		$users      = $this->wire('users');
		$log        = $this->wire('log');
		$config     = $this->wire('config');
	
		// --- sets we manage ---
		$userFieldNames = [
			'must_set_password',
			'access_token',
			'access_expires',
			'reset_token',
			'reset_expires',
			'spl_purchases', // Repeater
		];
		$repeaterInnerNames = [
			'purchase_date',
			'purchase_lines',
		];
		$productFieldNames = [
			'allow_multiple_purchases',
			'requires_access',
			'stripe_product_id',
			'access_mail_addon_txt',
		];
	
		// --- helper: field usage across all templates ---
		$isFieldInUse = function(\ProcessWire\Field $f) use ($templates): bool {
			foreach ($templates as $t) {
				/** @var \ProcessWire\Template $t */
				if ($t->fieldgroup && $t->fieldgroup->has($f)) return true;
			}
			return false;
		};
	
		// --- helper: remove field from a template (and context) if present ---
		$detachFieldFromTemplate = function(string $tplName, \ProcessWire\Field $f) use ($templates, $fields) {
			/** @var \ProcessWire\Template|null $tpl */
			$tpl = $templates->get($tplName);
			if (!$tpl || !$tpl->id) return;
			$fg = $tpl->fieldgroup;
			if ($fg->has($f)) {
				// remove context first (if any)
				$ctx = $fg->getField($f, true);
				if ($ctx) {
					try { $fields->deleteFieldgroupContext($ctx, $fg); } catch (\Throwable $e) {}
				}
				$fg->remove($f);
				try { $fg->save(); } catch (\Throwable $e) {}
			}
		};
	
		// 1) Detach from user template
		/** @var \ProcessWire\Template|null $userTpl */
		$userTpl = $templates->get('user');
		if ($userTpl && $userTpl->id) {
			foreach ($userFieldNames as $fname) {
				if (!($f = $fields->get($fname)) || !$f->id) continue;
				$detachFieldFromTemplate('user', $f);
			}
		}
	
		// 2) Detach from product templates (module config)
		$tplNames = array_values(array_unique(array_filter((array)($this->productTemplateNames ?? []))));
		foreach ($tplNames as $tplName) {
			foreach ($productFieldNames as $fname) {
				if (!($f = $fields->get($fname)) || !$f->id) continue;
				$detachFieldFromTemplate($tplName, $f);
			}
		}
	
		// 3) Delete role "customer"
		try {
			$role = $roles->get('customer');
			if ($role && $role->id) {
				// remove from all users
				foreach ($users as $u) {
					/** @var \ProcessWire\User $u */
					if ($u->hasRole($role)) {
						$u->of(false);
						$u->roles->remove($role);
						try { $users->save($u, ['quiet' => true]); } catch (\Throwable $e) {}
					}
				}
				// delete role
				try { $roles->delete($role); } catch (\Throwable $e) {}
			}
		} catch (\Throwable $e) {}
	
		// 4) Delete fields (only if not used elsewhere)
		// 4a) Delete repeater (this will also remove its repeater template/items)
		if (($purchases = $fields->get('spl_purchases')) && $purchases->id) {
			// ensure detached from all templates
			foreach ($templates as $t) {
				/** @var \ProcessWire\Template $t */
				if ($t->fieldgroup && $t->fieldgroup->has($purchases)) {
					$fg = $t->fieldgroup;
					$fg->remove($purchases);
					try { $fg->save(); } catch (\Throwable $e) {}
				}
			}
			try { $fields->delete($purchases); } catch (\Throwable $e) {}
		}
	
		// 4b) Delete inner repeater fields if unused
		foreach ($repeaterInnerNames as $fname) {
			if (($f = $fields->get($fname)) && $f->id && !$isFieldInUse($f)) {
				try { $fields->delete($f); } catch (\Throwable $e) {}
			}
		}
	
		// 4c) Delete user fields if unused
		foreach (['must_set_password','access_token','access_expires','reset_token','reset_expires'] as $fname) {
			if (($f = $fields->get($fname)) && $f->id && !$isFieldInUse($f)) {
				try { $fields->delete($f); } catch (\Throwable $e) {}
			}
		}
	
		// 4d) Delete product fields if unused
		foreach ($productFieldNames as $fname) {
			if (($f = $fields->get($fname)) && $f->id && !$isFieldInUse($f)) {
				try { $fields->delete($f); } catch (\Throwable $e) {}
			}
		}
	
		// 5) Delete module-specific logs
		try {
			$logsPath = rtrim($config->paths->logs, '/\\');

			$plLog = $logsPath . DIRECTORY_SEPARATOR . self::LOG_PL . '.txt';
			if (is_file($plLog)) @unlink($plLog);
			
			$mailLog = $logsPath . DIRECTORY_SEPARATOR . self::LOG_MAIL . '.txt';
			if (is_file($mailLog)) @unlink($mailLog);
			
			$secLog = $logsPath . DIRECTORY_SEPARATOR . self::LOG_SEC . '.txt';
			if (is_file($secLog)) @unlink($secLog);

		} catch (\Throwable $e) {}
	
		// Done
	}
	
	/* ========================= Public API ========================= */
	
	/**
	 * Renders the appropriate modals, access blocks, and JS for the current page context.
	 *
	 * Handles product access gating, thank-you blocks, password reset flows, and modal rendering.
	 *
	 * @param Page $currentPage The current ProcessWire page.
	 * @return string Rendered HTML for modals, access blocks, and scripts.
	 */
	public function render(Page $currentPage): string{
		$this->processCheckout($currentPage);
		$this->handleAccessParam();
	
		$u       = $this->wire('user');
		$session = $this->wire('session');
		$input   = $this->wire('input');
		$pages   = $this->wire('pages');
	
		$queuedNotice    = $session->get('modal_notice');
		$hasQueuedNotice = is_array($queuedNotice) && !empty($queuedNotice['id'] ?? '');
		$out = '';
	
		// Is this page a delivery/product page? (only those should hide the button block)
		$isAccessPage = $this->productRequiresAccess($currentPage) === true;
	
		// 1) Gate product/delivery pages that require access
		if ($isAccessPage) {
			$hasAccess = $u->isSuperuser()
					|| ($u->isLoggedin() && $this->hasActiveAccess($u, $currentPage));
	
			if (!$hasAccess) {
				$sales = $currentPage->parent();
				if ($sales && $sales->id) {
					$session->set('pl_intended_url', $currentPage->httpUrl);
					$this->modal()->queueLoginRequiredModal();
					$session->redirect($sales->httpUrl, false);
					return '';
				}
			}
		}
	
		// 2) Thank-you button block (only on non-access pages)
		$links = $session->get('pl_access_links');
		if (is_array($links) && count($links)) {
			if (!$isAccessPage) {
				$out .= $this->modal()->renderAccessButtonsBlock($links);
			}
			// always clear after first render/suppress
			$session->remove('pl_access_links');
		}
	
		// 3) Reset token handling (validate BEFORE rendering reset modal)
		$resetToken       = $input->get->text('reset');
		$usernameForReset = '';
		$shouldOpenReset  = false;
	
		if ($resetToken) {
			$now = time();
			/** @var \ProcessWire\User|null $uByToken */
			$uByToken = $this->wire('users')->get("reset_token=$resetToken, reset_expires>=$now");
			if ($uByToken && $uByToken->id) {
				// Valid token → prefill username and plan to open reset modal
				$usernameForReset = (string) $uByToken->email;
				$shouldOpenReset  = true;
			} else {
				// Invalid/expired token → queue friendly modal that offers to request a new one
				$this->modal()->queueResetTokenExpiredModal([
					'action'     => $this->apiUrl(),
					'return_url' => $currentPage->httpUrl,
				]);
			}
		}
	
		// 4) Modals (render only what is needed)
		$out .= $this->modal()->modalLogin([
			'action'       => $this->apiUrl(),
			'redirect_url' => $currentPage->url ?: $pages->get('/')->url,
		]);
	
		$out .= $this->modal()->modalResetRequest([
			'action'     => $this->apiUrl(),
			'return_url' => $currentPage->httpUrl,
		]);
	
		// Render "set new password" modal only when the token is valid
		if ($shouldOpenReset) {
			$out .= $this->modal()->modalResetSet([
				'action'   => $this->apiUrl(),
				'username' => $usernameForReset,
			]);
	
			// Inject token + auto-open ONLY for valid token
			$out .= '<script>
				document.addEventListener("DOMContentLoaded", function(){
					var fld = document.getElementById("resetTokenField");
					if (fld) fld.value = "'. addslashes($resetToken) .'";
					var m = document.getElementById("resetSetModal");
					if (m && window.bootstrap) bootstrap.Modal.getOrCreateInstance(m).show();
				});
			</script>';
		}
	
		// 5) After purchase: force set-password modal when needed (on access pages)
		if ($isAccessPage
			&& $u->isLoggedin()
			&& $u->hasField('must_set_password') && $u->must_set_password) {
	
			$out .= $this->modal()->modalSetPassword([
				'action'   => $this->apiUrl(),
				'user'     => $u,
				'username' => (string) $u->email,
			]);
	
			if (!$hasQueuedNotice) {
				$out .= '<script>
					document.addEventListener("DOMContentLoaded", function(){
						var el = document.getElementById("setPassModal");
						if (el && window.bootstrap) bootstrap.Modal.getOrCreateInstance(el).show();
					});
				</script>';
			}
		}
	
		// 6) Strip ?access when logged in
		if ($u->isLoggedin() && $this->wire('input')->get->text('access')) {
			$out .= '<script>
				(function(){
					var url = new URL(window.location.href);
					if (url.searchParams.has("access")) {
						url.searchParams.delete("access");
						window.history.replaceState({}, "", url.pathname + (url.searchParams.toString()? "?"+url.searchParams.toString() : "") + url.hash);
					}
				})();
			</script>';
		}
	
		// 7) One-off notices + global AJAX handler
		$out .= $this->modal()->renderModalNotice();
		$out .= $this->modal()->globalAjaxHandlerJs();
		
		return $out;
	}
						
/**
	 * Processes Stripe checkout: verifies session, maps purchases, logs in user,
	 * appends PERIOD END for subscriptions, and stores a per-product period_end_map in meta.
	 *
	 * - Appends " • YYYY-MM-DD" to purchase_lines only for recurring items.
	 * - Stores raw Stripe session (array) in meta 'stripe_session'.
	 * - Stores per-product period end timestamps in meta 'period_end_map' => [productId => unix_ts].
	 */
public function processCheckout(Page $currentPage): void {
		 $input   = $this->wire('input');
		 $session = $this->wire('session');
		 $users   = $this->wire('users');
	 
		 $sessionId = $input->get->text('session_id');
		 if (!$sessionId) return;
	 
		 // Idempotency (per browser session)
		 $processed = $session->get('pl_processed_sessions') ?: [];
		 if (in_array($sessionId, $processed, true)) return;
	 
		 // Stripe SDK
		 $sdk = $this->stripeSdkPath ?? (__DIR__ . '/vendor/stripe-php/init.php');
		 if (!is_file($sdk)) return;
		 require_once $sdk;
	 
		 $keys = $this->getStripeKeys();
		 if (!count($keys)) return;
	 
		 try {
			 // Retrieve session + keep a client for possible subscription fetch
			 $bundle = $this->retrieveCheckoutSessionWithKeys($sessionId, $keys);
			 if (!$bundle) return;
	 
			 /** @var \Stripe\Checkout\Session $checkoutSession */
			 $checkoutSession = $bundle['session'];
			 /** @var \Stripe\StripeClient $stripe */
			 $stripe = $bundle['client'];
	 
			 if (($checkoutSession->payment_status ?? null) !== 'paid') return;
	 
			 // Buyer data
			 $email = $checkoutSession->customer_details->email
				   ?? ($checkoutSession->customer->email ?? null)
				   ?? $checkoutSession->customer_email
				   ?? null;
	 
			 $fullName = $checkoutSession->customer_details->name
					  ?? ($checkoutSession->customer->name ?? null)
					  ?? ($checkoutSession->shipping->name ?? null)
					  ?? '';
	 
			 if (!$email) return;
	 
			 // Create or update user
			 $ui    = $this->createUserFromStripe($email, $fullName);
			 /** @var \ProcessWire\User $buyer */
			 $buyer = $ui['user'];
			 $isNew = $ui['isNew'];
	 
			 // ---- determine subscription period end (supports expanded or fetched subscription) ----
			 $getPeriodEnd = function($sessionObj, \Stripe\StripeClient $client): ?int {
				 // a) expanded subscription object present?
				 if (is_object($sessionObj->subscription ?? null)) {
					 $sub = $sessionObj->subscription;
					 if (!empty($sub->current_period_end)) return (int) $sub->current_period_end;
					 if (!empty($sub->items->data) && is_array($sub->items->data)) {
						 foreach ($sub->items->data as $si) {
							 if (!empty($si->current_period_end)) return (int)$si->current_period_end;
						 }
					 }
				 }
				 // b) subscription id available? fetch it
				 if (is_string($sessionObj->subscription ?? null) && $sessionObj->subscription !== '') {
					 try {
						 $sub = $client->subscriptions->retrieve($sessionObj->subscription, []);
						 if (!empty($sub->current_period_end)) return (int)$sub->current_period_end;
						 if (!empty($sub->items->data) && is_array($sub->items->data)) {
							 foreach ($sub->items->data as $si) {
								 if (!empty($si->current_period_end)) return (int)$si->current_period_end;
							 }
						 }
					 } catch (\Throwable $e) {
						 // ignore
					 }
				 }
				 return null;
			 };
			 $subscriptionPeriodEnd = $getPeriodEnd($checkoutSession, $stripe); // unix ts or null
	 
			 // ---- collect productIds, accessProducts, and flags ----
			 $accessProducts    = [];
			 $allMapped         = [];
			 $unmapped          = [];
			 $alreadyDisallowed = false;
			 $productIds        = []; // include 0 for unmapped/non-access
	 
			 $liArray = (array) ($checkoutSession->line_items->data ?? []);
			 foreach ($liArray as $li) {
				 $label = ($li->price->product->name ?? null)
					   ?? ($li->description ?? null)
					   ?? ($li->price->nickname ?? null)
					   ?? 'unknown item';
	 
				 $pwProduct = $this->mapStripeLineItemToProduct($li);
				 $pid       = $pwProduct ? (int) $pwProduct->id : 0;
				 $productIds[] = $pid;
	 
				 if ($pwProduct && $pwProduct->id) {
					 $allMapped[$pwProduct->id] = $pwProduct;
	 
					 $allowsMultiple = $this->productAllowsMultiple($pwProduct);
					 $requiresAccess = $this->productRequiresAccess($pwProduct);
					 $alreadyActive  = $this->hasActiveAccess($buyer, $pwProduct);
	 
					 if ($requiresAccess) $accessProducts[$pwProduct->id] = $pwProduct;
					 if ($alreadyActive && !$allowsMultiple) $alreadyDisallowed = true;
				 } else {
					 $unmapped[] = (string) $label;
				 }
			 }
			 $productIds = array_values(array_unique(array_map('intval', $productIds)));
	 
			 // ---- derive subscription flags + effective end (canceled > paused) ----
			 $sub      = (is_object($checkoutSession->subscription ?? null)) ? $checkoutSession->subscription : null;
			 $canceled = $sub ? ((string)($sub->status ?? '') === 'canceled') : null; // null = unknown
			 $paused   = $sub ? ((isset($sub->pause_collection) && $sub->pause_collection !== null) ? true : false) : null;
			 if ($canceled === true) $paused = false;
	 
			 $effectiveEnd = $subscriptionPeriodEnd ?: null; // start with resolved period end if any
			 if ($sub) {
				 $currentEnd = is_numeric($sub->current_period_end ?? null) ? (int)$sub->current_period_end : null;
				 $cancelAt   = is_numeric($sub->cancel_at ?? null)          ? (int)$sub->cancel_at          : null;
				 $endedAt    = is_numeric($sub->ended_at ?? null)           ? (int)$sub->ended_at           : null;
				 $cap        = (bool)($sub->cancel_at_period_end ?? false);
	 
				 if ($canceled) {
					 $effectiveEnd = $endedAt ?: ($cancelAt ?: ($currentEnd ?: ($effectiveEnd ?: time())));
				 } elseif ($cap && $currentEnd) {
					 $effectiveEnd = max((int)($effectiveEnd ?? 0), (int)$currentEnd);
				 } elseif ($currentEnd) {
					 $effectiveEnd = $effectiveEnd ? max($effectiveEnd, $currentEnd) : $currentEnd;
				 }
			 }
	 
			 // ---- persist repeater item via single helper (Trait writes metas + lines) ----
			 if ($buyer->hasField('spl_purchases')) {
				 $buyer->of(false);
				 $item = $buyer->spl_purchases->getNew();
	 
				 $purchaseTs = (isset($checkoutSession->created) && is_numeric($checkoutSession->created))
					 ? (int) $checkoutSession->created
					 : time();
	 
				 $item->set('purchase_date', $purchaseTs);
				 $buyer->spl_purchases->add($item);
				 $this->wire('users')->save($buyer, ['quiet' => true]);
	 
				 // Write metas + rebuild lines once
				 $this->plWriteMetasAndRebuild($item, $checkoutSession, $productIds, $effectiveEnd, $paused, $canceled);
				 $buyer->of(true);
			 }
	 
			 // Enforce login
			 if (!$this->wire('user')->isLoggedin() || $this->wire('user')->id !== $buyer->id) {
				 $this->wire('session')->forceLogin($buyer);
			 }
	 
			 // Access links (only for gated products; generic thanks page otherwise)
			 $links = [];
			 $token = null;
			 if (!empty($accessProducts)) {
				 foreach ($accessProducts as $p) {
					 $url = $p->httpUrl;
					 if ($isNew) {
						 if ($token === null) {
							 $ttlMinutes = (int)($this->accessTokenTtlMinutes ?: 30);
							 $token = $this->createAccessToken($buyer, $ttlMinutes * 60);
						 }
						 $glue = (strpos($url, '?') === false) ? '?' : '&';
						 $url .= $glue . 'access=' . urlencode($token);
					 }
					 $links[] = ['title' => (string)$p->title, 'url' => $url, 'id' => (int)$p->id];
				 }
				 $session->set('pl_access_links', $links);
			 }
	 
			 // Send access summary mail (policy)
			 if (!empty($links) && $this->shouldSendAccessMail($isNew)) {
				 $this->mail()->sendAccessSummaryMail($this, $buyer, $links);
			 }
	 
			 // Duplicate purchase notice
			 if ($alreadyDisallowed) $this->modal()->queueAlreadyPurchasedModal();
	 
			 // Mark session processed
			 $processed[] = $sessionId;
			 $session->set('pl_processed_sessions', $processed);
			 
			 $this->wire('log')->save(
				 self::LOG_PL,
				 '[INFO] Processed Stripe session ' .
				 json_encode([
					 'session'        => $sessionId,
					 'email'          => $email,
					 'mapped'         => count($allMapped),
					 'access'         => count($accessProducts),
					 'unmapped'       => $unmapped ? implode(', ', $unmapped) : '-',
					 'newUser'        => $isNew ? 'yes' : 'no',
					 'subPeriodEnd'   => $subscriptionPeriodEnd ?: '-',
				 ], JSON_UNESCAPED_SLASHES)
			 );
			 
	 
		 } catch (\Throwable $e) {
			$this->wire('log')->save(self::LOG_PL, 'processCheckout error: '.$e->getMessage().' '.$sessionId);
	   	 	return;
	 	 }
	 }

	/**
	  * Handles access tokens in ?access query parameter for soft logins or expired notices.
	  *
	  * @return void
	  */
	public function handleAccessParam(): void{
		$input   = $this->wire('input');
		$session = $this->wire('session');
		$users   = $this->wire('users');
		$user    = $this->wire('user');

		$token = $input->get->text('access');
		if (!$token) return;

		if ($user->isLoggedin()) return;

		if (strlen($token) < 40) {
			$this->wire('log')->save(self::LOG_SEC, 'Received obviously invalid access token '.$token);
			$this->modal()->queueExpiredAccessModal();
			return;
		}

		$now = time();
		/** @var \ProcessWire\User $u */
		$u = $users->get("access_token=$token, access_expires>=$now");
		if (!$u || !$u->id) {
			$this->wire('log')->save(self::LOG_SEC, 'Access token not found or expired '.$token);
			$this->modal()->queueExpiredAccessModal();
			return;
		}

		if (!$user->isLoggedin() || $user->id !== $u->id) {
			$session->forceLogin($u);
			$this->wire('log')->save(self::LOG_SEC, 'Soft-login via valid access token '.$u->id);
		}
	}

	/* ========================= Text access ========================= */

	/**
	 * Determines if access mail should be sent after checkout, based on policy and user type.
	 *
	 * @param bool $isNewUser True if this is a newly created user.
	 * @return bool True if access mail should be sent.
	 */
	private function shouldSendAccessMail(bool $isNewUser): bool {
		$policy = (string)($this->accessMailPolicy ?? 'newUsersOnly');
		return match ($policy) {
			'never'        => false,
			'always'       => true,
			'newUsersOnly' => $isNewUser,
			default        => $isNewUser,
		};
	}
	
	/**
	 * Retrieves a default text by key from internationalized module texts.
	 *
	 * @param string $key Text key identifier.
	 * @return string The translated text or empty string if not found.
	 */
	public function t(string $key): string {
		$D = $this->defaultTexts();
		return $D[$key] ?? '';
	}

	/**
	 * Returns the absolute path to the mail layout template, considering site override.
	 *
	 * @return string Path to mail layout template.
	 */
	 public function mailLayoutPath(): string {
		 $layoutPath = (string)($this->mailTemplatePath ?? '');
		 if ($layoutPath !== '') {
			 // URL → auf Serverpfad mappen
			 if (preg_match('~^https?://~i', $layoutPath)) {
				 $urlsRoot  = rtrim($this->wire('config')->urls->root, '/');
				 $pathsRoot = rtrim($this->wire('config')->paths->root, '/');
				 $rel = preg_replace('~^https?://[^/]+~i', '', $layoutPath);  // /site/…
				 if ($urlsRoot !== '' && str_starts_with($rel, $urlsRoot)) {
					 $rel = substr($rel, strlen($urlsRoot));                  // relativ ab /
				 }
				 $layoutPath = $pathsRoot . '/' . ltrim($rel, '/');
			 }
			 // Relativ ab /site/… erlauben
			 if (str_starts_with($layoutPath, '/site/')) {
				 $layoutPath = $this->wire('config')->paths->root . ltrim($layoutPath, '/');
			 }
			 if (is_file($layoutPath)) return $layoutPath;
	 
			 $this->wire('log')->save(self::LOG_MAIL, 'Mail layout not found: ' . $layoutPath . ' – using module default.');
		 }
		 return $this->moduleMailLayout;
	 }

	/**
	 * Returns the absolute API endpoint URL for the module.
	 *
	 * @return string API URL.
	 */
	public function apiUrl(): string {
		return rtrim($this->wire('config')->urls->root, '/') . '/stripepaymentlinks/api';
	}

	/* ========================= Services (lazy) ========================= */

	/**
	 * Returns a lazily-loaded PLMailService instance.
	 *
	 * @return PLMailService
	 */
	public function mail(): PLMailService {
		if (!$this->mailService) { require_once __DIR__ . '/includes/PLMailService.php'; $this->mailService = new PLMailService(); }
		return $this->mailService;
	}
	
	/**
	 * Returns a lazily-loaded PLModalService instance.
	 *
	 * @return PLModalService
	 */
	public function modal(): PLModalService {
		if (!$this->modalService) { require_once __DIR__ . '/includes/PLModalService.php'; $this->modalService = new PLModalService($this); }
		return $this->modalService;
	}
	
	/**
	 * Returns a lazily-loaded PLApiController instance.
	 *
	 * @return PLApiController
	 */
	public function api(): PLApiController {
		if (!$this->apiService) { require_once __DIR__ . '/includes/PLApiController.php'; $this->apiService = new PLApiController($this); }
		return $this->apiService;
	}
	
	// In class StripePaymentLinks (irgendwo bei den public-Helpers)
	public function mapStripeProductToPageId(string $stripeProductId): ?int {
		if ($stripeProductId === '') return null;
	
		$pages = $this->wire('pages');
		$san   = $this->wire('sanitizer');
	
		$tplNames = array_values(array_filter((array)($this->productTemplateNames ?? [])));
		$tplNames = array_map([$san, 'name'], $tplNames);
		$tplSel   = $tplNames ? ('template=' . implode('|', $tplNames) . ', ') : '';
	
		$sid = $san->selectorValue($stripeProductId);
	
		if ($tplSel) {
			$p = $pages->get($tplSel . "stripe_product_id=$sid");
			if ($p && $p->id) return (int)$p->id;
		}
		$p = $pages->get("stripe_product_id=$sid");
		return ($p && $p->id) ? (int)$p->id : null;
	}

	/**
	 * Maps a Stripe checkout line item to a corresponding product Page in ProcessWire.
	 *
	 * @param mixed $li Stripe line item object.
	 * @return Page|null The matched product Page or null if not found.
	 */
	public function mapStripeLineItemToProduct($li): ?\ProcessWire\Page {
		$pages     = $this->wire('pages');
		$sanitizer = $this->wire('sanitizer');

		$get = function($src, array $path) {
			$cur = $src;
			foreach ($path as $k) {
				if (is_object($cur)) {
					$cur = $cur->$k ?? ( ($cur instanceof \ArrayAccess && isset($cur[$k])) ? $cur[$k] : null );
				} elseif (is_array($cur)) {
					$cur = array_key_exists($k, $cur) ? $cur[$k] : null;
				} else return null;
				if ($cur === null) return null;
			}
			return $cur;
		};

		$stripeProductId = $get($li, ['price','product','id']);
		if(!$stripeProductId) {
			$prod = $get($li, ['price','product']);
			if (is_string($prod) && $prod !== '') $stripeProductId = $prod;
		}

		$stripeName =
			$get($li, ['price','product','name']) ??
			$get($li, ['description']) ??
			$get($li, ['price','nickname']) ?? '';

		$tplNames = array_values(array_filter(array_map(
			fn($n) => $sanitizer->name($n),
			(array)($this->productTemplateNames ?? [])
		)));
		$tplSel = $tplNames ? ('template=' . implode('|', $tplNames) . ', ') : '';

		if ($stripeProductId) {
			$sid = $sanitizer->selectorValue((string)$stripeProductId);
			$p = $pages->get($tplSel . "stripe_product_id=$sid");
			if ($p && $p->id) return $p;
			$p = $pages->get("stripe_product_id=$sid");
			if ($p && $p->id) return $p;
		}

		if ($stripeName !== '') {
			$t = $sanitizer->selectorValue((string)$stripeName);
			$p = $pages->get($tplSel . "title=$t");
			if ($p && $p->id) return $p;
		}
		return null;
	}

	/* =========================  helpers ========================= */
	
	// public, so other classes (like PLApiController) can rebuild lines after meta changes
	public function rebuildPurchaseLines(\ProcessWire\Page $purchaseItem): void {
		// Uses the trait's internal, single-source-of-truth renderer
		$this->plRebuildLinesAndSave($purchaseItem);
	}
	
	/**
	 * Ensures required user fields and repeater structure exist for purchases.
	 *
	 * Adds fields to the user template and configures fieldgroup contexts.
	 *
	 * @return void
	 */
	protected function ensureFields(): void {
		$fields    = $this->wire('fields');
		$templates = $this->wire('templates');
		$modules   = $this->wire('modules');

		/** @var \ProcessWire\Template|null $userTpl */
		$userTpl = $templates->get('user');
		if(!$userTpl || !$userTpl->id) {
			$this->wire('log')->save(self::LOG_PL, 'ensureFields: user template not found');
			return;
		}

		$ensure = function(string $name, string $typeClass, array $props = []) use ($fields, $modules): \ProcessWire\Field {
			$f = $fields->get($name);
			if(!$f || !$f->id) {
				$f = new \ProcessWire\Field();
				$f->name = $name;
				$f->type = $modules->get($typeClass);
			}
			foreach($props as $k => $v) $f->$k = $v;
			$fields->save($f);
			return $f;
		};

		if(($title = $fields->get('title')) && !$userTpl->fieldgroup->has($title)) {
			$userTpl->fieldgroup->add($title);
			$userTpl->fieldgroup->save();
		}

		$fMust  = $ensure('must_set_password', 'FieldtypeCheckbox', ['label' => 'User must set password']);
		$fAT    = $ensure('access_token',      'FieldtypeText',     ['label' => 'Access token']);
		$fATExp = $ensure('access_expires',    'FieldtypeInteger',  ['label' => 'Access token expiry']);
		$fRT    = $ensure('reset_token',       'FieldtypeText',     ['label' => 'Reset token']);
		$fRTExp = $ensure('reset_expires',     'FieldtypeInteger',  ['label' => 'Reset expiry']);

		$fg = $userTpl->fieldgroup;
		$changed = false;
		foreach([$fMust, $fAT, $fATExp, $fRT, $fRTExp] as $f) {
			if(!$fg->has($f)) { $fg->add($f); $changed = true; }
		}
		if($changed) $fg->save();

		foreach(['must_set_password','access_token','access_expires','reset_token','reset_expires'] as $name) {
			$base = $fields->get($name);
			if(!$base || !$fg->has($base)) continue;
			$ctx = $fg->getField($base, true);
			$ctx->collapsed = \ProcessWire\Inputfield::collapsedHidden;
			$fields->saveFieldgroupContext($ctx, $fg);
		}

		$purchases = $ensure('spl_purchases', 'FieldtypeRepeater', ['label' => 'Purchases']);
		/** @var \ProcessWire\Template $repTpl */
		$repTpl = $purchases->type->getRepeaterTemplate($purchases);
		$repFg  = $repTpl->fieldgroup;

		$purchaseDate = $ensure('purchase_date', 'FieldtypeDatetime', ['label' => 'Purchase date']);
		$purchaseLines = $ensure('purchase_lines', 'FieldtypeTextarea', [
			'label' => 'Purchase lines',
			'notes' => 'One line per item: PRODUCT_ID • QTY • PRODUCT_TITLE • TOTAL • PERIOD END (for subscriptions)',
		]);
				
		$repChanged = false;
			if(!$repFg->has($purchaseDate))  { $repFg->add($purchaseDate);  $repChanged = true; }
			if(!$repFg->has($purchaseLines)) { $repFg->add($purchaseLines); $repChanged = true; }
			if($repChanged) $repFg->save();

		if(!$fg->has($purchases)) {
			$fg->add($purchases);
			$fg->save();
		}
	}

	/**
	 * Ensures required product fields are present on configured product templates.
	 *
	 * @param array|null $templateNames List of template names to ensure fields on.
	 * @return void
	 */
	 protected function ensureProductFields(?array $templateNames = null): void {
		 $fields     = $this->wire('fields');
		 $templates  = $this->wire('templates');
		 $modules    = $this->wire('modules');
		 $sanitizer  = $this->wire('sanitizer');
	 
		 // Helper to create/update a field
		 $ensure = function(string $name, string $typeClass, string $label = '') use ($fields, $modules): \ProcessWire\Field {
			 $f = $fields->get($name);
			 if(!$f || !$f->id) {
				 $f = new \ProcessWire\Field();
				 $f->name = $name;
				 $f->columnWidth = 33;
				 $f->type = $modules->get($typeClass);
			 }
			 if($label !== '') $f->label = $label;
			 $fields->save($f);
			 return $f;
		 };
	 
		 // Create/ensure product fields
		 $fStripe = $ensure('stripe_product_id',        'FieldtypeText',     'Stripe Product ID');
		 $fReq    = $ensure('requires_access',          'FieldtypeCheckbox', 'Product: requires access/delivery page');
		 $fAllow  = $ensure('allow_multiple_purchases', 'FieldtypeCheckbox', 'Product: allows multiple purchases');
	 	 $fAddon  = $ensure('access_mail_addon_txt',    'FieldtypeText',     'Access Mail Intro Text (optional)');

		 // Determine templates to attach to
		 if ($templateNames === null) {
			 $templateNames = (array)($this->productTemplateNames ?? []);
		 }
		 $templateNames = array_values(array_unique(array_filter(array_map(
			 fn($n) => $sanitizer->name($n), $templateNames
		 ))));
	 
		 foreach ($templateNames as $tplName) {
			 /** @var \ProcessWire\Template|null $t */
			 $t = $templates->get($tplName);
			 if(!$t || !$t->id) continue;
	 
			 $fg = $t->fieldgroup;
	 
			 // Attach fields if missing
			 $changed = false;
			 foreach ([$fStripe, $fReq, $fAllow, $fAddon] as $f) {
				 if(!$fg->has($f)) { $fg->add($f); $changed = true; }
			 }
			 if ($changed) $fg->save();
	 
			 // Context: show stripe_product_id only when requires_access=1
			 $ctx = $fg->getFieldContext($fStripe);
			 $ctx->showIf = 'requires_access=1';
			 $fields->saveFieldgroupContext($ctx, $fg);
			 
			 $ctxAddon = $fg->getFieldContext($fAddon);
			 $ctxAddon->columnWidth = 100;
			 $fields->saveFieldgroupContext($ctxAddon, $fg);
		 }
	 }	
/** Collect Stripe API keys from config (multi-line textarea). */
	private function getStripeKeys(): array {
		$cfgKeys = $this->stripeApiKeys ?? [];
		if (is_string($cfgKeys)) {
			$cfgKeys = preg_split('~\r\n|\r|\n~', $cfgKeys) ?: [];
		}
		return array_values(array_unique(array_filter(array_map('trim', (array) $cfgKeys))));
	}
	
	/**
	 * Retrieve a checkout session using any configured key.
	 * Returns an array with ['session' => \Stripe\Checkout\Session, 'client' => \Stripe\StripeClient].
	 * We keep the client so we can fetch the subscription if it wasn't expanded.
	 */
	private function retrieveCheckoutSessionWithKeys(string $sessionId, array $keys): ?array {
		foreach ($keys as $k) {
			try {
				$client = new \Stripe\StripeClient(['api_key' => $k]);
				// Try with expansions first (line_items + customer + subscription)
				$session = $client->checkout->sessions->retrieve($sessionId, [
					'expand' => ['line_items.data.price.product', 'customer', 'subscription'],
				]);
				return ['session' => $session, 'client' => $client];
			} catch (\Stripe\Exception\ApiErrorException $e) {
				// Fallback without expand
				try {
					$client = new \Stripe\StripeClient(['api_key' => $k]);
					$session = $client->checkout->sessions->retrieve($sessionId);
					return ['session' => $session, 'client' => $client];
				} catch (\Throwable $e2) {
					// try next key
				}
			} catch (\Throwable $e) {
				// try next key
			}
		}
		return null;
	}
	

	/** Heuristics: detect if Bootstrap is already present (CSS or window.bootstrap marker later) */
	private function detectBootstrapPresent(string $html): bool
	{
		// quick CSS detection in head/body
		if (preg_match('~href=["\']?[^"\']*bootstrap(\.min)?\.css~i', $html)) return true;
		// if you also enqueue via $config->styles elsewhere, the above still catches it
		return false;
	}
	/** Inject $tags into <head> once, robust to casing/attributes and missing </head>. */
	private function injectIntoHead(string $html, string $tags): string {
		if ($tags === '') return $html;
	
		// Avoid duplicate injection if our marker is already present
		if (stripos($html, 'id="spl-bootstrap-css"') !== false || stripos($html, 'id="spl-bootstrap-js"') !== false) {
			return $html;
		}

		if (preg_match('/<head\b[^>]*>/i', $html)) {
			return preg_replace('/<head\b[^>]*>/i', '$0' . "\n" . $tags, $html, 1);
		}
	
		return $tags . "\n" . $html;
	}
	
	/** Inject Bootstrap (CSS/JS) only on frontend pages (never in PW admin). */
	private function renderBootstrapFallback(string $html): string
	{
		if (!(bool)($this->autoLoadBootstrap ?? false)) return $html;
	
		$page   = $this->wire('page');
		$input  = $this->wire('input');
		$config = $this->wire('config');
	
		if (
			($page && (string)$page->template === 'admin') ||
			(isset($config->urls->admin) && strpos((string)$input->url, (string)$config->urls->admin) === 0)
		) {
			return $html;
		}
	
		if (stripos($html, '<head>') === false) return $html;
		if ($this->detectBootstrapPresent($html)) return $html;
	
		$css = trim((string)($this->bootstrapCssCdn ?? ''));
		$js  = trim((string)($this->bootstrapJsCdn  ?? ''));
		if ($css === '') $css = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css';
		if ($js  === '') $js  = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js';
	
		$tags  = "\n<!-- StripePaymentLinks: Bootstrap CDN (frontend only) -->\n";
		$tags .= '<link id="spl-bootstrap-css" rel="stylesheet" href="' . htmlspecialchars($css, ENT_QUOTES, 'UTF-8') . "\" crossorigin=\"anonymous\">\n";
		$tags .= '<script id="spl-bootstrap-js" src="' . htmlspecialchars($js, ENT_QUOTES, 'UTF-8') . "\" defer crossorigin=\"anonymous\"></script>\n";
	
		return $this->injectIntoHead($html, $tags);
	}
	
	/**
	 * Determines if a product allows multiple purchases for the same user.
	 *
	 * @param Page $product Product page.
	 * @return bool True if multiple purchases are allowed.
	 */
	protected function productAllowsMultiple(Page $product): bool {
		return (bool)($product->get('allow_multiple_purchases') ?? false);
	}
	
	/**
	 * Determines if a product requires access restriction (gated delivery page).
	 *
	 * @param Page $product Product page.
	 * @return bool True if access is required.
	 */
	protected function productRequiresAccess(Page $product): bool {
		return (bool)($product->get('requires_access') ?? false);
	}

/**
	 * Determines if the user currently has *active* access to a given product.
	 * Logic:
	 * - Only the most recent purchase for this product ID is evaluated.
	 * - A pause flag (_paused) in the latest purchase always blocks access.
	 * - If the latest purchase has a numeric period_end, it must be >= now.
	 * - If no purchase has a period_end_map for this product but at least one
	 *   purchase exists without it → treat as lifetime (one-time) purchase.
	 */
	protected function hasActiveAccess(\ProcessWire\User $user, \ProcessWire\Page $product): bool {
		if (!$user->hasField('spl_purchases') || !$user->spl_purchases->count()) return false;
	
		$pid   = (int) $product->id;
		$now   = time();
		$flag  = $pid . '_paused';
	
		$latestTs         = 0;
		$latestPaused     = false;
		$latestPeriodEnd  = null;
		$sawAnyWithMap    = false;
		$sawAnyWithoutMap = false;
	
		foreach ($user->spl_purchases as $p) {
			$ids = (array) $p->meta('product_ids');
			if (!in_array($pid, array_map('intval', $ids), true)) continue;
	
			$pd  = (int) ($p->get('purchase_date') ?: $p->created ?: 0);
			$map = (array) $p->meta('period_end_map');
	
			if (!empty($map) && array_key_exists($pid, $map)) {
				$sawAnyWithMap = true;
				// take only the most recent purchase
				if ($pd >= $latestTs) {
					$latestTs        = $pd;
					$latestPaused    = array_key_exists($flag, $map);
					$latestPeriodEnd = is_numeric($map[$pid]) ? (int)$map[$pid] : null;
				}
			} else {
				// purchase without map (e.g. one-time or legacy purchase)
				$sawAnyWithoutMap = true;
				if ($pd >= $latestTs) {
					$latestTs     = $pd;
					$latestPaused = array_key_exists($flag, $map);
					// no period_end value → lifetime type
				}
			}
		}
	
		if ($latestTs === 0) return false;
	
		// paused → always block access
		if ($latestPaused) return false;
	
		// latest purchase has a valid period_end → check expiry
		if ($latestPeriodEnd !== null) {
			return ($latestPeriodEnd >= $now);
		}
	
		// no purchase with period_end_map but at least one without → treat as active (lifetime)
		if (!$sawAnyWithMap && $sawAnyWithoutMap) {
			return true;
		}
	
		return false;
	}
	
	/**
	 * Checks if the user has ever purchased the product (historical, not active).
	 */
	protected function hasPurchasedProduct(\ProcessWire\User $user, \ProcessWire\Page $product): bool {
		if (!$user->hasField('spl_purchases') || !$user->spl_purchases->count()) return false;
		$pid = (int) $product->id;
	
		foreach ($user->spl_purchases as $p) {
			$ids = (array) $p->meta('product_ids');
			if (in_array($pid, array_map('intval', $ids), true)) {
				return true;
			}
		}
		return false;
	}
	 
	/**
	 * Ensures the "customer" role exists in ProcessWire and has page-view permission.
	 *
	 * @return void
	 */
	protected function ensureCustomerRoleExists(): void {
		$roles       = $this->wire('roles');
		$permissions = $this->wire('permissions');

		$role = $roles->get('customer');
		if ($role && $role->id) return;

		try {
			$role = $roles->add('customer');
			if (!$role || !$role->id) throw new \Exception('roles->add("customer") failed');

			$view = $permissions->get('page-view');
			if ($view && !$role->hasPermission($view)) {
				$role->addPermission($view);
				$roles->save($role);
			}
			$this->wire('log')->save(self::LOG_PL, 'Created role "customer".');
		} catch (\Throwable $e) {
			$this->wire('log')->save(self::LOG_PL, 'ensureCustomerRoleExists error: '.$e->getMessage());
		}
	}

	/**
	 * Ensures a user has the "customer" role, adding it if missing.
	 *
	 * @param User $user The user to modify.
	 * @return void
	 */
	protected function ensureCustomerRole(User $user): void {
		$roles = $this->wire('roles');
		$users = $this->wire('users');

		try {
			$role = $roles->get('customer');
			if (!$role || !$role->id) {
				$this->wire('log')->save(self::LOG_PL, 'Role "customer" missing. Save module config as superuser to create it.');
				return;
			}
			if (!$user->hasRole($role)) {
				$user->of(false);
				$user->roles->add($role);
				$users->save($user, ['quiet' => true]);
				$this->wire('log')->save(self::LOG_PL, "Added role 'customer' to user ".$user->id);
			}
		} catch (\Throwable $e) {
			$this->wire('log')->save(self::LOG_PL, 'ensureCustomerRole error: '.$e->getMessage());
		}
	}
/**
	 * Create or update a ProcessWire user based on Stripe checkout session data.
	 *
	 * - If a user with the given email exists, update name if changed.
	 * - If not, create a new user with random password and must_set_password=1.
	 * - Always ensures the customer role.
	 *
	 * @param string $email
	 * @param string $fullName
	 * @return array{user:User,isNew:bool} User object and flag if it was newly created
	 */
	protected function createUserFromStripe(string $email, string $fullName = ''): array {
		$users     = $this->wire('users');
		$sanitizer = $this->wire('sanitizer');
	
		$isNewUser = false;
		/** @var User $buyer */
		$buyer = $users->get("email=" . $sanitizer->email($email));
	
		if (!$buyer || !$buyer->id) {
			$isNewUser = true;
			$buyer = new User();
			$buyer->name  = $sanitizer->pageName($email);
			$buyer->email = $email;
			$buyer->pass  = bin2hex(random_bytes(8));
			if ($buyer->hasField('must_set_password')) {
				$buyer->must_set_password = 1;
			}
			if ($fullName) {
				$buyer->title = $fullName;
			}
			$users->save($buyer);
		} elseif ($fullName && $buyer->title !== $fullName) {
			$buyer->of(false);
			$buyer->title = $fullName;
			$users->save($buyer, ['quiet' => true]);
		}
	
		$this->ensureCustomerRole($buyer);
	
		return ['user' => $buyer, 'isNew' => $isNewUser];
	}
	
	/**
	 * Creates a new access token for the user and sets its expiry.
	 *
	 * @param User $user The user to update.
	 * @param int $ttlSeconds Time-to-live in seconds for the token.
	 * @return string The generated access token.
	 */
	protected function createAccessToken(User $user, int $ttlSeconds): string {
		$token = bin2hex(random_bytes(32));
		$user->of(false);
		if ($user->hasField('access_token'))   $user->access_token   = $token;
		if ($user->hasField('access_expires')) $user->access_expires = time() + max(60, $ttlSeconds);
		$this->wire('users')->save($user, ['quiet' => true]);
		return $token;
	}

	 /**
	  * Trigger manual sync from config checkbox and reset the flag.
	  *
	  * @param array $data Saved config values from Modules::saveConfig hook.
	  * @return void
	  */
	  protected function triggerSyncStripeCustomers(array $data): void {
		if (empty($data['pl_sync_run'])) return;
	  
		try {
		  require_once __DIR__ . '/includes/PLSyncHelper.php';
		  $helper = new \ProcessWire\PLSyncHelper($this);
		  $helper->runSyncFromConfig($data); // schreibt Report in $_SESSION
		  $this->message('Sync finished – see report below.');
		  $this->wire('log')->save(self::LOG_PL, '[SYNC] finished');
		} catch (\Throwable $e) {
		  $this->error('Sync error: ' . $e->getMessage());
		  $this->wire('log')->save(self::LOG_PL, '[SYNC ERROR] ' . $e->getMessage());
		}
	  
		// Flag zurücksetzen, damit es nicht erneut läuft
		$data['pl_sync_run'] = false;
		$this->modules->saveConfig('StripePaymentLinks', $data);
	  }
	  
	  /**
	   * When a product gets switched to "requires_access", migrate past purchases
	   * that referenced the Stripe product id as unmapped ("0#<stripeId>") to the
	   * real Page ID scope, and notify affected users with an access mail.
	   *
	   * @param \ProcessWire\Page $product
	   * @return void
	   */
	   protected function updateUserAccessAndNotify(\ProcessWire\Page $product): void{
		   $pid      = (int) $product->id;
		   $stripeId = trim((string) $product->get('stripe_product_id'));
	   
		   $users = $this->wire('users');
	   
		   /** @var \ProcessWire\User[]|\ProcessWire\PageArray $candidates */
		   $candidates = $users->find("spl_purchases.count>0");
	   
		   foreach ($candidates as $u) {
			   $userAffected = false;
	   
			   if (!$u->hasField('spl_purchases') || !$u->spl_purchases->count()) {
				   continue;
			   }
	   
			   foreach ($u->spl_purchases as $item) {
				   if (!$item || !$item->id) continue;
	   
				   // Read stored session meta
				   $sessionMeta = (array) $item->meta('stripe_session');
				   if (!$sessionMeta) continue;
	   
				   // Does this purchase include THIS Stripe product?
				   $lineItems = $sessionMeta['line_items']['data'] ?? [];
				   if (!is_array($lineItems) || !$lineItems) continue;
	   
				   $containsTarget = false;
				   foreach ($lineItems as $li) {
					   if (!is_array($li)) continue;
					   $pp  = $li['price']['product'] ?? null;
					   $sid = is_array($pp) ? (string)($pp['id'] ?? '') : (is_string($pp) ? $pp : '');
					   if ($sid !== '' && $sid === $stripeId) { $containsTarget = true; break; }
				   }
				   if (!$containsTarget) continue;
	   
				   $userAffected = true;
	   
				   // 1) Ensure product_ids contains the real PID
				   $productIds = array_map('intval', (array) $item->meta('product_ids'));
				   if (!in_array($pid, $productIds, true)) {
					   $productIds[] = $pid;
					   $productIds   = array_values(array_unique($productIds));
					   $item->meta('product_ids', $productIds);
				   }
	   
				   // 2) Migrate period_end_map scope from "0#<stripeId>" → "<pid>" and flags
				   $map    = (array) $item->meta('period_end_map');
				   $oldKey = '0#' . $stripeId;
				   $newKey = (string) $pid;
	   
				   $oldPausedKey   = $oldKey . '_paused';
				   $oldCanceledKey = $oldKey . '_canceled';
				   $newPausedKey   = $newKey . '_paused';
				   $newCanceledKey = $newKey . '_canceled';
	   
				   // Move/merge end timestamp (never shorten)
				   $existingNewEnd = isset($map[$newKey]) && is_numeric($map[$newKey]) ? (int) $map[$newKey] : 0;
				   $existingOldEnd = isset($map[$oldKey]) && is_numeric($map[$oldKey]) ? (int) $map[$oldKey] : 0;
				   if ($existingOldEnd > 0) {
					   $map[$newKey] = max($existingNewEnd, $existingOldEnd);
					   unset($map[$oldKey]);
				   }
	   
				   // Move flags (canceled dominates paused)
				   $hadOldCanceled = array_key_exists($oldCanceledKey, $map);
				   $hadOldPaused   = array_key_exists($oldPausedKey, $map);
				   if ($hadOldCanceled || $hadOldPaused) {
					   if ($hadOldCanceled) unset($map[$oldCanceledKey]);
					   if ($hadOldPaused)   unset($map[$oldPausedKey]);
	   
					   if ($hadOldCanceled) {
						   unset($map[$newPausedKey]);
						   $map[$newCanceledKey] = 1;
					   } elseif ($hadOldPaused) {
						   if (!array_key_exists($newCanceledKey, $map)) {
							   $map[$newPausedKey] = 1;
						   }
					   }
				   }
	   
				   // Persist updated map (even if identical, harmless) and ALWAYS rebuild lines
				   $item->meta('period_end_map', $map);
				   try { $item->of(false); } catch (\Throwable $e) {}
				   $this->plRebuildLinesAndSave($item, [
					   $stripeId => (int)$pid,   
				   ]);
			   }
	   
			   // If user had at least one matching purchase, ALWAYS send access email
			   if ($userAffected) {
				   $links = [];
				   $url   = $product->httpUrl;
	   
				   // Optional: attach a short-lived magic-link for convenience
				   $ttlMinutes = (int)($this->accessTokenTtlMinutes ?? 30);
				   try {
					   $token = $this->createAccessToken($u, max(60, $ttlMinutes * 60));
					   $glue  = (strpos($url, '?') === false) ? '?' : '&';
					   $url  .= $glue . 'access=' . urlencode($token);
				   } catch (\Throwable $e) {
					   // best effort; ignore on failure
				   }
	   
				   $links[] = [
					   'title' => (string) $product->title,
					   'url'   => $url,
					   'id'    => $pid,
				   ];
	   
				   try {
					   $this->mail()->sendAccessSummaryMail($this, $u, $links);
				   } catch (\Throwable $e) {
					   // mail best effort; ignore on failure
				   }
			   }
		   }
	   }
 }
