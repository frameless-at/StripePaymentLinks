<?php namespace ProcessWire;

use ProcessWire\User;

/**
 * PLMailService
 * Renders + sends access and reset emails using the HTML layout template.
 */
class PLMailService
{
	/* =====================================================================
	 * ACCESS SUMMARY (1..n Produkte)
	 * ===================================================================*/
	public function sendAccessSummaryMail(StripePaymentLinks $mod, User $user, array $links): bool
	{
		$mail   = wire('mail');
		$config = wire('config');
		$isMulti  = count($links) > 1;
		$listText = implode(', ', array_filter(array_map(fn($l) => (string)($l['title'] ?? ''), $links)));
		$repl     = ['{title}' => (string)($links[0]['title'] ?? $mod->t('mail.common.product_fallback')), '{list}' => $listText];
	
		$vars = [
			'preheader'     => strtr($mod->t($isMulti ? 'mail.multi.preheader' : 'mail.single.preheader'), $repl),
			'firstname'     => $this->displayName($user),
			'productTitle'  => $repl['{title}'],
			'productUrl'    => (string)($links[0]['url'] ?? '#'),
			'ctaText'       => $mod->t($isMulti ? 'mail.multi.cta' : 'mail.single.cta'),
			'leadText'      => strtr($mod->t($isMulti ? 'mail.multi.body' : 'mail.single.body'), $repl),
			'logoUrl'       => (string)($mod->logoUrl ?? ''),
			'brandColor'    => (string)($mod->brandColor ?? '#7d0a3d'),
			'fromName'      => (string)($mod->mailFromName ?? ($config->siteName ?? $config->httpHost ?? 'Website')),
			'brandHeader'   => (string)($mod->mailHeaderName ?? ''),
			'headerTagline' => $mod->t('mail.common.header_tagline'),
			'headline'      => $mod->t($isMulti ? 'mail.multi.title' : 'mail.single.title'),
			'footerNote'    => $mod->t('mail.common.footer_note'),
			'infoLabel'     => $mod->t('mail.common.info_label'),
			'extraHeading'  => $mod->t('mail.common.extra_heading'),
			'closingText'   => $mod->t('mail.common.closing_text'),
			'signatureName' => (string)($mod->mailSignatureName ?? $mod->mailFromName ?? ''),
			'directLabel'   => $mod->t('mail.common.direct_link'),
			'greeting'      => $mod->t('mail.common.greeting'),
			'extraCtas'     => $isMulti ? array_map(
				fn($l) => ['title' => (string)($l['title'] ?? ''), 'url' => (string)($l['url'] ?? '#')],
				array_slice($links, 1)
			) : [],
		];
	
		$html = $this->renderLayout($mod->mailLayoutPath(), $vars);
	
		$m = $mail->new();
		$m->to($user->email);
		$m->from(
			(string)($mod->mailFromEmail ?? ($config->adminEmail ?? 'no-reply@' . ($config->httpHost ?? 'localhost'))),
			$vars['fromName']
		);
		$m->subject(($mod->subjectPrefix ?? '') . strtr($mod->t($isMulti ? 'mail.multi.subject' : 'mail.single.subject'), $repl));
		$m->bodyHTML($html);
		$m->body(strtr("{$vars['leadText']}\n\n{$vars['productUrl']}\n\n{$vars['closingText']}\n{$vars['signatureName']}\n", $repl));
	
		try {
			return (bool)$m->send();
		} catch (\Throwable $e) {
			$mod->wire('log')->save('mail', '[ERROR] Access email send error: '.$e->getMessage());
			return false;
		}
	}

	/* =====================================================================
	 * PASSWORD-RESET
	 *  – gleiche Branding-Variablen & Layout, nur andere Texte/CTA
	 * ===================================================================*/
	public function sendPasswordResetMail(StripePaymentLinks $mod, User $user, string $resetUrl): bool
	{
		$mail   = wire('mail');
		$config = wire('config');
	
		$vars = [
			// Inhalt
			'preheader'     => $mod->t('mail.resetpwd.preheader'),
			'firstname'     => $this->displayName($user),
			'productTitle'  => $mod->t('mail.resetpwd.title'),
			'productUrl'    => $resetUrl,
			'ctaText'       => $mod->t('mail.resetpwd.cta'),
			'leadText'      => $mod->t('mail.resetpwd.body'),
			'footerNote'    => $mod->t('mail.common.footer_note'),
			'brandColor'    => (string)($mod->brandColor ?? '#0d6efd'),
			'logoUrl'       => (string)($mod->logoUrl ?? ''),
			'fromName'      => (string)($mod->mailFromName ?? ($config->siteName ?? $config->httpHost ?? 'Website')),
			'brandHeader'   => (string)($mod->mailHeaderName ?? ''),
			'headerTagline' => $mod->t('mail.common.header_tagline'),
			'greeting'      => $mod->t('mail.common.greeting'),
			'closingText'   => $mod->t('mail.resetpwd.notice'),
		];
	
		$html = $this->renderLayout($mod->mailLayoutPath(), $vars);
	
		$m = $mail->new();
		$m->to($user->email);
		$m->from(
			(string)($mod->mailFromEmail ?? ($config->adminEmail ?? 'no-reply@' . ($config->httpHost ?? 'localhost'))),
			$vars['fromName']
		);
	
		$subjectPrefix = (string)($mod->subjectPrefix ?? '');
		$subjectCore   = $mod->t('mail.resetpwd.subject');
		$m->subject($subjectPrefix !== '' ? ($subjectPrefix . ' ' . $subjectCore) : $subjectCore);
	
		$m->bodyHTML($html);
		$m->body($vars['leadText'] . "\n\n" . $resetUrl);
	
		try {
			return (bool)$m->send();
		} catch (\Throwable $e) {
			$mod->wire('log')->save('mail', '[ERROR] Password reset email send error: ' . $e->getMessage());
			return false;
		}
	}

	/* =====================================================================
	 * Helpers
	 * ===================================================================*/
	private function displayName(User $u): string
	{
		$t = trim((string) $u->title);
		if ($t !== '') return $t; // kompletter Anzeigename („Mike Kozak“)
		$email = (string) $u->email;
		if ($email !== '') {
			$at = strpos($email, '@');
			return $at !== false ? substr($email, 0, $at) : $email;
		}
		return '';
	}

	/** Render the provided PHP/HTML template with variables. */
	private function renderLayout(string $file, array $vars): string
	{
		if (!is_file($file)) {
			// Minimal Fallback
			$t = htmlspecialchars((string)($vars['productTitle'] ?? ''),  ENT_QUOTES, 'UTF-8');
			$u = htmlspecialchars((string)($vars['productUrl'] ?? '#'),   ENT_QUOTES, 'UTF-8');
			$c = htmlspecialchars((string)($vars['ctaText'] ?? 'Open'),   ENT_QUOTES, 'UTF-8');
			$lead = htmlspecialchars((string)($vars['leadText'] ?? ''),   ENT_QUOTES, 'UTF-8');
			return "<p>{$lead}</p><p><a href=\"{$u}\">{$c}</a></p>";
		}
		extract($vars, EXTR_SKIP);
		ob_start();
		include $file;
		return (string) ob_get_clean();
	}
}