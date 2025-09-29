<?php namespace ProcessWire;

use ProcessWire\InputfieldWrapper;
use ProcessWire\ModuleConfig;
use ProcessWire\Inputfield;

/**
 * StripePaymentLinksConfig
 * 
 * Configuration class for StripePaymentLinks module.
 * Provides default configuration values and builds the configuration UI.
 */
class StripePaymentLinksConfig extends ModuleConfig {

	/**
 	* Get the default configuration values.
 	* 
 	* @return array Associative array of default config values.
 	*/
 	public function getDefaults()
	{
		$cfg = $this->config; // available in ModuleConfig
		return [
			// Essentials
			'stripeApiKeys'          => '',
			'productTemplateNames'   => [],
			'accessMailPolicy'       => 'newUsersOnly', // never | newUsersOnly | always
			'accessTokenTtlMinutes'  => 30,

			// Mail & Branding
			'mailTemplatePath'       => '',
			'logoUrl'                => '',
			'mailFromEmail'          => (string)($cfg->adminEmail ?? ('no-reply@' . ($cfg->httpHost ?? 'localhost'))),
			'mailFromName'           => (string)($cfg->siteName ?? 'Website'),
			'subjectPrefix'          => '',
			'brandColor'             => '#0d6efd',
			'mailHeaderName'         => (string)($cfg->siteName ?? ''),
			'mailSignatureName'      => (string)($cfg->siteName ?? ''),
			
			// Sync Helper
			'pl_sync_dry_run' => true,
			'pl_sync_update_existing' => false,
			'pl_sync_create_missing' => false,
			'pl_sync_keys' => [],
			'pl_sync_email' => '',
			'pl_sync_from' => '',
			'pl_sync_to' => '',
			'pl_sync_run' => false,
		];
	}

	/**
	 * Build the configuration UI input fields.
	 * 
	 * @return InputfieldWrapper Returns an InputfieldWrapper containing all config input fields.
	 */
	public function getInputfields() {
		/** @var InputfieldWrapper $inputfields */
		$inputfields = $this->modules->get('InputfieldWrapper');

		/* ---------- Essentials ---------- */
		$fs = $this->modules->get('InputfieldFieldset');
		$fs->label = 'Essentials';
		$fs->name  = 'pl_essentials';
		$fs->collapsed = Inputfield::collapsedNo;
		
		// Stripe Secret API Keys (multiple)
		$f = $this->modules->get('InputfieldTextarea');
		$f->name  = 'stripeApiKeys';
		$f->label = 'Stripe Secret API Keys';
		$f->notes = 'Enter one key per line. The module will try each key until a matching account/session is found.';
		$f->attr('rows', 4);
		$f->attr('value', implode("\n", (array)$this->get('stripeApiKeys')));
		$f->icon = 'key';
		$fs->add($f);

		// Product templates (AsmSelect)
		$tplSelect = $this->modules->get('InputfieldAsmSelect');
		$tplSelect->name  = 'productTemplateNames';
		$tplSelect->label = 'Product templates';
		$tplSelect->notes = 'These templates will get the fields "allow_multiple_purchases" & "requires_access".';
		$opts = [];
		foreach ($this->templates->find("flags!=system") as $t) {
			$opts[$t->name] = $t->name;
		}
		$tplSelect->addOptions($opts);
		$tplSelect->columnWidth = 33;
		$tplSelect->attr('value', (array)$this->get('productTemplateNames'));
		$fs->add($tplSelect);

		// Access mail policy
		$pol = $this->modules->get('InputfieldSelect');
		$pol->name  = 'accessMailPolicy';
		$pol->label = 'Access mail after purchase';
		$pol->columnWidth = 33;
		$pol->notes = 'When should an access mail be sent (if products requiring access are present)?';
		$pol->addOptions([
			'never'        => 'Never',
			'newUsersOnly' => 'Only for new users (default)',
			'always'       => 'Always',
		]);
		$pol->attr('value', (string)$this->get('accessMailPolicy'));
		$fs->add($pol);

		// Magic link TTL (minutes)
		$ttl = $this->modules->get('InputfieldInteger');
		$ttl->name  = 'accessTokenTtlMinutes';
		$ttl->label = 'Magic link TTL (minutes)';
		$ttl->columnWidth = 33;
		$ttl->attr('value', (int)$this->get('accessTokenTtlMinutes'));
		$ttl->notes = 'Validity of the magic link (only for new users).';
		$ttl->min   = 1;
		$ttl->max   = 2880;
		$fs->add($ttl);

		$inputfields->add($fs);

		/* ---------- Mail & Branding ---------- */
		$fsMail = $this->modules->get('InputfieldFieldset');
		$fsMail->label = 'Mail defaults & branding';
		$fsMail->name  = 'pl_mail_branding';
		$fsMail->collapsed = Inputfield::collapsedYes;

		// Mail layout path
		$tplPath = $this->modules->get('InputfieldText');
		$tplPath->name  = 'mailTemplatePath';
		$tplPath->label = 'Mail layout (optional)';
		$tplPath->notes = 'Absolute path. If empty or file missing, module fallback layout is used.';
		$tplPath->attr('value', (string)$this->get('mailTemplatePath'));
		$fsMail->add($tplPath);

		// Logo URL
		$logo = $this->modules->get('InputfieldText');
		$logo->name  = 'logoUrl';
		$logo->label = 'Logo URL (optional)';
		$logo->notes = 'Absolute URL. If empty, header brand name is used.';
		$logo->attr('value', (string)$this->get('logoUrl'));
		$fsMail->add($logo);

		// Sender email
		$from = $this->modules->get('InputfieldText');
		$from->name  = 'mailFromEmail';
		$from->label = 'Sender email';
		$from->columnWidth = 33;
		$from->attr('value', (string)$this->get('mailFromEmail'));
		$fsMail->add($from);

		// Sender name
		$fromName = $this->modules->get('InputfieldText');
		$fromName->name  = 'mailFromName';
		$fromName->label = 'Sender name';
		$fromName->columnWidth = 33;
		$fromName->attr('value', (string)$this->get('mailFromName'));
		$fsMail->add($fromName);

		// Subject prefix
		$subj = $this->modules->get('InputfieldText');
		$subj->name  = 'subjectPrefix';
		$subj->label = 'Subject prefix (optional)';
		$subj->columnWidth = 33;
		$subj->attr('value', (string)$this->get('subjectPrefix'));
		$fsMail->add($subj);

		// Brand color
		$brand = $this->modules->get('InputfieldText');
		$brand->name  = 'brandColor';
		$brand->columnWidth = 33;
		$brand->label = 'Brand color (#hex)';
		$brand->attr('value', (string)$this->get('brandColor'));
		$fsMail->add($brand);

		// Header brand
		$hdr = $this->modules->get('InputfieldText');
		$hdr->name  = 'mailHeaderName';
		$hdr->label = 'Header brand (optional)';
		$hdr->columnWidth = 33;
		$hdr->attr('value', (string)$this->get('mailHeaderName'));
		$fsMail->add($hdr);

		// Signature
		$sig = $this->modules->get('InputfieldText');
		$sig->name  = 'mailSignatureName';
		$sig->label = 'Signature (optional)';
		$sig->columnWidth = 33;
		$sig->attr('value', (string)$this->get('mailSignatureName'));
		$fsMail->add($sig);

		$inputfields->add($fsMail);
		
		// Frontend Assets
		$fsAssets = $this->modules->get('InputfieldFieldset');
		$fsAssets->label = 'Frontend assets';
		$fsAssets->name  = 'pl_frontend_assets';
		$fsAssets->collapsed = Inputfield::collapsedYes;
		
		$auto = $this->modules->get('InputfieldCheckbox');
		$auto->name  = 'autoLoadBootstrap';
		$auto->label = 'Auto-load Bootstrap via CDN if not present';
		$auto->attr('checked', (bool)$this->get('autoLoadBootstrap'));
		$auto->notes = 'If your theme does not include Bootstrap 5, the module can inject CDN links.';
		$fsAssets->add($auto);
		
		$css = $this->modules->get('InputfieldText');
		$css->name  = 'bootstrapCssCdn';
		$css->label = 'Bootstrap CSS CDN URL';
		$css->attr('value', (string)$this->get('bootstrapCssCdn'));
		$css->notes = 'If empty css version 5.3.3 from jsdelivr.net is added.';
		$css->showIf = 'autoLoadBootstrap=1';
		$fsAssets->add($css);
		
		$js = $this->modules->get('InputfieldText');
		$js->name  = 'bootstrapJsCdn';
		$js->label = 'Bootstrap JS (bundle) CDN URL';
		$js->attr('value', (string)$this->get('bootstrapJsCdn'));
		$js->notes = 'If empty js version 5.3.3 from jsdelivr.net is added.';
		$js->showIf = 'autoLoadBootstrap=1';
		$fsAssets->add($js);
		
		$inputfields->add($fsAssets);
		
		/** -----------------------
		 *  Sync (advanced) UI
		 *  ----------------------*/
		 $fsSync = $this->modules->get('InputfieldFieldset');
		 $fsSync->label = 'Sync existing customers';
		 $fsSync->name  = 'pl_sync';
		 $fsSync->collapsed = Inputfield::collapsedYes;
		
			// Keys multi-select (derived from configured keys; show masked labels)
			$configuredKeys = [];
			$raw = $this->get('stripeApiKeys') ?? [];
			if (is_string($raw)) {
			  foreach (preg_split('~\R+~', trim($raw)) ?: [] as $k) {
				$k = trim($k);
				if ($k !== '') $configuredKeys[] = $k;
			  }
			} elseif (is_array($raw)) {
			  foreach ($raw as $k) {
				$k = trim((string)$k);
				if ($k !== '') $configuredKeys[] = $k;
			  }
			}
		
			$keyOptions = [];
			foreach ($configuredKeys as $i => $k) {
			  // mask: sk_live_****last4
			  $last4 = substr($k, -4);
			  $prefix = (strpos($k, '_test_') !== false) ? 'TEST' : 'LIVE';
			  $keyOptions[$i] = $prefix . ' • …' . $last4;
			}
		
			/** @var InputfieldAsmSelect $keys */
			$keys = $this->modules->get('InputfieldAsmSelect');
			$keys->attr('name', 'pl_sync_keys');
			$keys->label = 'Stripe keys to include';
			$keys->description = 'Leave empty to use ALL configured keys.';
			$keys->options = $keyOptions;
			$keys->collapsed = Inputfield::collapsedYes;
			$fsSync->add($keys);
			
			/** Email filter for targeted sync (optional) */
			/** @var \ProcessWire\InputfieldEmail $f */
			$f = $this->modules->get('InputfieldText');
			$f->name  = 'pl_sync_email';
			$f->label = $this->_('Limit to this buyer email (optional)');
			$f->description = $this->_('Leave empty to sync all.');
			$f->notes = $this->_('If set, the sync will only consider Stripe sessions whose customer email matches this address.');
			$f->columnWidth = 33;
			$f->placeholder = 'user@example.com';
			$f->required = false;
			$fsSync->add($f);
			
			// From / To
			/** @var InputfieldDatetime $from */
			$from = $this->modules->get('InputfieldDatetime');
			$from->name  = 'pl_sync_from';
			$from->label = 'From (optional)';
			$from->description = 'Pick a starting date (server time).';
			$from->columnWidth = 33;
			$from->datepicker = 1;   // falls Property existiert: Kalender an
			$from->timepicker = 0;   // falls Property existiert: Zeit aus
			$from->attr('placeholder', 'YYYY-MM-DD');
			$fsSync->add($from);
			
			/** @var InputfieldDatetime $to */
			$to = $this->modules->get('InputfieldDatetime');
			$to->name  = 'pl_sync_to';
			$to->label = 'To (optional)';
			$to->description = 'Pick an end date (server time).';
			$to->columnWidth = 33;
			$to->datepicker = 1;
			$to->timepicker = 0;
			$to->attr('placeholder', 'YYYY-MM-DD');
			$fsSync->add($to);
			
			// Dry run (default ON)
			/** @var InputfieldCheckbox $dry */
			$dry = $this->modules->get('InputfieldCheckbox');
			$dry->attr('name', 'pl_sync_dry_run');
			$dry->label = 'Test mode (no writes)';
			$dry->description = 'If checked, the sync only logs what it would do (no DB writes).';
			$dry->checked = (bool)$this->get('pl_sync_dry_run');
			$dry->columnWidth = 33;
			$fsSync->add($dry);
		
			// Update existing
			/** @var InputfieldCheckbox $upd */
			$upd = $this->modules->get('InputfieldCheckbox');
			$upd->attr('name', 'pl_sync_update_existing');
			$upd->label = 'Update existing purchase items';
			$upd->description = 'If a purchase for the same session_id exists, refresh its content/meta.';
			$upd->checked = (bool)$this->get('pl_sync_update_existing');
			$upd->columnWidth = 33;
			$fsSync->add($upd);
		
			// Create missing users
			/** @var InputfieldCheckbox $mkUser */
			$mkUser = $this->modules->get('InputfieldCheckbox');
			$mkUser->attr('name', 'pl_sync_create_missing');
			$mkUser->label = 'Create missing users';
			$mkUser->description = 'If no user matches the checkout email, create one and set role customer.';
			$mkUser->checked = $this->get('pl_sync_create_missing');
			$mkUser->columnWidth = 33;
			$fsSync->add($mkUser);
		
			/** @var InputfieldCheckbox $run */
			$run = $this->modules->get('InputfieldCheckbox');
			$run->attr('name', 'pl_sync_run');
			$run->label = 'Sync now';
			$run->description = 'Use this tool for special cases only: migrating historical purchases, adding an additional Stripe account with existing sales, or recovery after outages. Before writing make a DB-Backup and always start with a TEST RUN.';
			$run->checked = $this->get('pl_sync_run');
			$fsSync->add($run);
		
			
			$report = $this->wire('session')->get('pl_sync_report');
			if ($report) {
				/** @var InputfieldMarkup $out */
				$out = $this->modules->get('InputfieldMarkup');
				$out->attr('name', 'pl_sync_report');
				$out->label = 'Sync report';
				$out->value = '<pre style="white-space:pre-wrap;max-height:400px;overflow:auto;margin:0">'
							. htmlspecialchars((string)$report, ENT_QUOTES, 'UTF-8')
							. '</pre>';
				$fsSync->add($out);
			
				$this->wire('session')->remove('pl_sync_report');
			}
			$inputfields->add($fsSync);

		return $inputfields;
	}
}