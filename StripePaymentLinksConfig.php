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
			'stripeApiKey'           => '',
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

		// Stripe Secret API Key
		$f = $this->modules->get('InputfieldText');
		$f->name  = 'stripeApiKey';
		$f->label = 'Stripe Secret API Key';
		$f->notes = 'For retrieving the Checkout Session (success redirect via ?session_id=...).';
		$f->attr('value', (string)$this->get('stripeApiKey'));
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
		$fsMail->collapsed = Inputfield::collapsedNo;

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
		$fsAssets->collapsed = Inputfield::collapsedNo;
		
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
		
		return $inputfields;
	}
}