<?php namespace ProcessWire;

use ProcessWire\User;
use ProcessWire\Wire;

/**
 * PLMailService
 * Renders + sends access and reset emails using the HTML layout template.
 */
class PLMailService extends Wire {
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
			'ctaText'       => strtr($mod->t($isMulti ? 'mail.multi.cta' : 'mail.single.cta'), $repl),
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
			'extraCtas'     => $isMulti ? array_map(
				fn($l) => ['title' => (string)($l['title'] ?? ''), 'url' => (string)($l['url'] ?? '#')],
				array_slice($links, 1)
			) : [],
			'extraNote'     => trim((string)($mod->mailExtraNote ?? '')),
		];
		$p = $mod->wire('pages')->get((int)$links[0]['id']);
		if ($p && $p->hasField('access_mail_addon_txt')) {
			$vars['leadText'] = trim((string)$p->access_mail_addon_txt) . "\n\n" . ($vars['leadText'] ?? '');
		}
		// for hooks
		$vars = $this->alterAccessMailVars($vars, $mod, $user, $links);

		$html = $this->renderLayout($mod->mailLayoutPath(), $vars);
	
		$m = $mail->new();
		$m->to($user->email);
		$m->from(
			(string)($mod->mailFromEmail ?? ($config->adminEmail ?? 'no-reply@' . ($config->httpHost ?? 'localhost'))),
			$vars['fromName']
		);
		$m->subject(($mod->subjectPrefix ?? '') . strtr($mod->t($isMulti ? 'mail.multi.subject' : 'mail.single.subject'), $repl));
		$m->bodyHTML($html);
		$plain = "{$vars['leadText']}\n\n{$vars['productUrl']}\n\n{$vars['closingText']}\n{$vars['signatureName']}\n";
		if ($vars['extraNote'] !== '') {
			$plain .= "\n" . $this->plainTextFromMaybeHtml($vars['extraNote']) . "\n";
		}
		$m->body(strtr($plain, $repl));
		$this->applyDeliverabilityHeaders($m, $mod);

		try {
			$sent = (bool)$m->send();
			if ($sent) {
				$mod->wire('log')->save('mail', '[OK] Access email successfully sent to ' . $user->email);
			}
			return $sent;
		} catch (\Throwable $e) {
			$mod->wire('log')->save('mail', '[ERROR] Access email send error: ' . $e->getMessage());
			return false;
		}
	}

	/* =====================================================================
	 * PASSWORD-RESET
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
		$this->applyDeliverabilityHeaders($m, $mod);

		try {
			$sent = (bool)$m->send();
			if ($sent) {
				$mod->wire('log')->save('mail', '[OK] Reset password email successfully sent to ' . $user->email);
			}
			return $sent;
		} catch (\Throwable $e) {
			$mod->wire('log')->save('mail', '[ERROR] Reset password email send error: ' . $e->getMessage());
			return false;
		}
	}

	/** Hookable: last-chance override of mail variables */
	protected function ___alterAccessMailVars(array $vars, StripePaymentLinks $mod, User $user, array $links): array{
		return $vars;
	}

	/* =====================================================================
	 * WITHDRAWAL: receipt confirmation mail (to consumer)
	 * ===================================================================*/
	public function sendWithdrawalReceiptMail(StripePaymentLinks $mod, array $data): bool
	{
		$to = trim((string) ($data['email'] ?? ''));
		if ($to === '') return false;

		$repl = [
			'{name}'        => (string) ($data['name']       ?? ''),
			'{email}'       => (string) ($data['email']      ?? ''),
			'{product}'     => (string) ($data['product']    ?? ''),
			'{order_id}'    => trim((string) ($data['order_id']   ?? '')) !== '' ? (string) $data['order_id']   : '—',
			'{order_date}'  => trim((string) ($data['order_date'] ?? '')) !== '' ? (string) $data['order_date'] : '—',
			'{received_at}' => date('Y-m-d H:i'),
		];
		$lead = strtr((string) $mod->t('withdrawal.mail.receipt.body'), $repl);

		$vars = $this->brandedMailVars($mod, [
			'preheader'     => $mod->t('withdrawal.mail.receipt.preheader'),
			'firstname'     => (string) ($data['name'] ?? ''),
			'productTitle'  => (string) ($data['product'] ?? ''),
			'leadText'      => $lead,
			'headerTagline' => $mod->t('withdrawal.mail.receipt.tagline'),
			'headline'      => $mod->t('withdrawal.mail.receipt.headline'),
			'closingText'   => $mod->t('withdrawal.mail.receipt.closing'),
			'signatureName' => (string) ($mod->mailSignatureName ?? $mod->mailFromName ?? ''),
		]);

		$plain = $lead . "\n\n" . $vars['closingText'] . "\n" . $vars['signatureName'] . "\n";
		return $this->sendBrandedMail($mod, $to, $mod->t('withdrawal.mail.receipt.subject'), $vars, $plain, 'Withdrawal receipt');
	}

	/* =====================================================================
	 * WITHDRAWAL: internal admin notification mail
	 * ===================================================================*/
	public function sendWithdrawalAdminMail(StripePaymentLinks $mod, array $data, ?\ProcessWire\User $user = null): bool
	{
		$config = wire('config');

		$to = trim((string) ($mod->withdrawalNotificationEmail ?? ''));
		if ($to === '') $to = (string) ($config->adminEmail ?? '');
		if ($to === '') return false;

		$userEditUrl = '';
		if ($user && $user->id) {
			$userEditUrl = rtrim((string) $config->urls->httpAdmin, '/') . '/page/edit/?id=' . (int) $user->id;
			$userStatus  = '#' . (int) $user->id;
		} else {
			$userStatus = (string) $mod->t('withdrawal.mail.admin.user_unknown');
		}

		$repl = [
			'{name}'        => (string) ($data['name']     ?? ''),
			'{email}'       => (string) ($data['email']    ?? ''),
			'{product}'     => (string) ($data['product']  ?? ''),
			'{order_id}'    => trim((string) ($data['order_id']   ?? '')) !== '' ? (string) $data['order_id']   : '—',
			'{order_date}'  => trim((string) ($data['order_date'] ?? '')) !== '' ? (string) $data['order_date'] : '—',
			'{reason}'      => trim((string) ($data['reason'] ?? '')) !== '' ? (string) $data['reason'] : '—',
			'{user_status}' => $userStatus,
		];
		$subject = strtr((string) $mod->t('withdrawal.mail.admin.subject'), $repl);
		$lead    = strtr((string) $mod->t('withdrawal.mail.admin.body'),    $repl);

		$vars = $this->brandedMailVars($mod, [
			'preheader'     => $subject,
			'productTitle'  => (string) ($data['product'] ?? ''),
			'productUrl'    => $userEditUrl,
			'linkText'      => $userEditUrl !== '' ? (string) $mod->t('withdrawal.mail.admin.link_text') : '',
			'leadText'      => $lead,
			'headerTagline' => $mod->t('withdrawal.mail.receipt.tagline'),
			'headline'      => $mod->t('withdrawal.mail.admin.headline'),
		]);

		$plain = $lead . ($userEditUrl !== '' ? "\n\n" . $userEditUrl : '');
		return $this->sendBrandedMail($mod, $to, $subject, $vars, $plain, 'Withdrawal admin notification');
	}

	/**
	 * Merge per-mail variables with the module's brand defaults.
	 * Caller supplies overrides; this fills in logoUrl, brandColor, fromName, etc.
	 */
	private function brandedMailVars(StripePaymentLinks $mod, array $overrides): array
	{
		$config = wire('config');
		$defaults = [
			'preheader'     => '',
			'firstname'     => '',
			'productTitle'  => '',
			'productUrl'    => '',
			'ctaText'       => '',
			'linkText'      => '',
			'leadText'      => '',
			'logoUrl'       => (string) ($mod->logoUrl ?? ''),
			'brandColor'    => (string) ($mod->brandColor ?? '#7d0a3d'),
			'fromName'      => (string) ($mod->mailFromName ?? ($config->siteName ?? $config->httpHost ?? 'Website')),
			'brandHeader'   => (string) ($mod->mailHeaderName ?? ''),
			'headerTagline' => '',
			'headline'      => '',
			'footerNote'    => $mod->t('mail.common.footer_note'),
			'closingText'   => '',
			'signatureName' => '',
		];
		return array_merge($defaults, $overrides);
	}

	/**
	 * Send a branded mail using the shared layout. Returns true on success.
	 */
	private function sendBrandedMail(StripePaymentLinks $mod, string $to, string $subject, array $vars, string $plainBody, string $logLabel): bool
	{
		$mail   = wire('mail');
		$config = wire('config');

		$html = $this->renderLayout($mod->mailLayoutPath(), $vars);

		$m = $mail->new();
		$m->to($to);
		$m->from(
			(string) ($mod->mailFromEmail ?? ($config->adminEmail ?? 'no-reply@' . ($config->httpHost ?? 'localhost'))),
			$vars['fromName']
		);
		$m->subject(((string) ($mod->subjectPrefix ?? '')) . $subject);
		$m->bodyHTML($html);
		$m->body($plainBody);
		$this->applyDeliverabilityHeaders($m, $mod);

		try {
			$sent = (bool) $m->send();
			$mod->wire('log')->save('mail', ($sent ? '[OK] ' : '[ERROR] ') . $logLabel . ' to ' . $to);
			return $sent;
		} catch (\Throwable $e) {
			$mod->wire('log')->save('mail', '[ERROR] ' . $logLabel . ' send error: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Convert a possibly-HTML string (TinyMCE input) to readable plain text
	 * for the text/plain part of the mail. Plain input is returned unchanged.
	 */
	private function plainTextFromMaybeHtml(string $s): string {
		if (strpos($s, '<') === false) return $s;
		$s = preg_replace('/<\s*br\s*\/?>/i', "\n", $s);
		$s = preg_replace('/<\/(p|div|li|tr|h[1-6])\s*>/i', "\n\n", $s);
		$s = strip_tags($s);
		$s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$s = preg_replace("/[ \t]+/", ' ', $s);
		$s = preg_replace("/\n{3,}/", "\n\n", $s);
		return trim($s);
	}

	/**
	 * Apply transactional/deliverability headers shared by all module mails.
	 * - Reply-To: avoids the "noreply@ without Reply-To" red flag for filters
	 * - Auto-Submitted (RFC 3834): correctly identifies automated mail
	 * - X-Auto-Response-Suppress: prevents OOO loops
	 * - X-Priority: explicitly Normal (some PHPMailer setups emit "null")
	 */
	private function applyDeliverabilityHeaders($m, StripePaymentLinks $mod): void {
		if (!method_exists($m, 'header')) return;

		$replyTo = trim((string)($mod->mailReplyTo ?? ''));
		if ($replyTo === '') {
			$replyTo = trim((string)($mod->wire('config')->adminEmail ?? ''));
		}
		try {
			if ($replyTo !== '') {
				// Set via header() rather than replyTo() — survives all WireMail
				// backends; replyTo() is silently dropped by some implementations.
				$m->header('Reply-To', $replyTo);
			}
			$m->header('Auto-Submitted', 'auto-generated');
			$m->header('X-Auto-Response-Suppress', 'All');
			$m->header('X-Priority', '3');
		} catch (\Throwable $e) { /* ignore */ }
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
			return $this->absolutizeUrls("<p>{$lead}</p><p><a href=\"{$u}\">{$c}</a></p>");
		}
		extract($vars, EXTR_SKIP);
		ob_start();
		include $file;
		return $this->absolutizeUrls((string) ob_get_clean());
	}

	/**
	 * Rewrite root-relative href URLs (e.g. "/agb/") to absolute URLs based on
	 * $config->urls->httpRoot. Leaves http(s)://, //, mailto:, tel:, # and
	 * non-root-relative paths untouched. Only acts on href attributes.
	 *
	 * Public for unit-testability in isolation.
	 */
	public function absolutizeUrls(string $html): string
	{
		$config = wire('config');
		$base = '';
		if ($config) {
			if (isset($config->urls) && (string)($config->urls->httpRoot ?? '') !== '') {
				$base = (string)$config->urls->httpRoot;
			} else {
				$scheme = !empty($config->https) ? 'https' : 'http';
				$host   = (string)($config->httpHost ?? '');
				if ($host !== '') $base = $scheme . '://' . $host . '/';
			}
		}
		if ($base === '') return $html;
		$base = rtrim($base, '/');

		return preg_replace_callback(
			'/\bhref=(["\'])(\/[^\/"\'][^"\']*)\1/i',
			fn($m) => 'href=' . $m[1] . $base . $m[2] . $m[1],
			$html
		);
	}
}