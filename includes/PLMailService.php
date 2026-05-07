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
	public function sendAccessSummaryMail(StripePaymentLinks $mod, User $user, array $links, array $allMappedItems = [], array $unmappedLabels = []): bool
	{
		$mail   = wire('mail');
		$config = wire('config');
		$hasAccess = !empty($links);
		$isMulti   = count($links) > 1;
		$listText  = implode(', ', array_filter(array_map(fn($l) => (string)($l['title'] ?? ''), $links)));
		$repl      = ['{title}' => (string)($links[0]['title'] ?? $mod->t('mail.common.product_fallback')), '{list}' => $listText];

		// Subject + lead vary depending on whether the order has gated products.
		if ($hasAccess) {
			$subjectKey   = $isMulti ? 'mail.multi.subject'   : 'mail.single.subject';
			$preheaderKey = $isMulti ? 'mail.multi.preheader' : 'mail.single.preheader';
			$headlineKey  = $isMulti ? 'mail.multi.title'     : 'mail.single.title';
			$bodyKey      = $isMulti ? 'mail.multi.body'      : 'mail.single.body';
			$ctaKey       = $isMulti ? 'mail.multi.cta'       : 'mail.single.cta';
			$preheader = strtr($mod->t($preheaderKey), $repl);
			$headline  = $mod->t($headlineKey);
			$leadText  = strtr($mod->t($bodyKey), $repl);
			$ctaText   = strtr($mod->t($ctaKey), $repl);
			$productUrl= (string)($links[0]['url'] ?? '#');
			$subject   = ($mod->subjectPrefix ?? '') . strtr($mod->t($subjectKey), $repl);
		} else {
			// Order without gated access (service_redeemable / unmapped) — generic
			// confirmation; CTA suppressed (no per-product URL to point at).
			$preheader = $mod->t('mail.order.preheader');
			$headline  = $mod->t('mail.order.title');
			$leadText  = $mod->t('mail.order.body');
			$ctaText   = '';
			$productUrl= '';
			$subject   = ($mod->subjectPrefix ?? '') . $mod->t('mail.order.subject');
		}

		$vars = [
			'preheader'     => $preheader,
			'firstname'     => $this->displayName($user),
			'productTitle'  => $repl['{title}'],
			'productUrl'    => $productUrl,
			'ctaText'       => $ctaText,
			'leadText'      => $leadText,
			'logoUrl'       => (string)($mod->logoUrl ?? ''),
			'brandColor'    => (string)($mod->brandColor ?? '#7d0a3d'),
			'fromName'      => (string)($mod->mailFromName ?? ($config->siteName ?? $config->httpHost ?? 'Website')),
			'brandHeader'   => (string)($mod->mailHeaderName ?? ''),
			'headerTagline' => $mod->t('mail.common.header_tagline'),
			'headline'      => $headline,
			'footerNote'    => $mod->t('mail.common.footer_note'),
			'infoLabel'     => $hasAccess ? $mod->t('mail.common.info_label') : '',
			'extraHeading'  => $mod->t('mail.common.extra_heading'),
			'closingText'   => $hasAccess ? $mod->t('mail.common.closing_text') : '',
			'signatureName' => (string)($mod->mailSignatureName ?? $mod->mailFromName ?? ''),
			'directLabel'   => $mod->t('mail.common.direct_link'),
			'extraCtas'     => $isMulti ? array_map(
				fn($l) => ['title' => (string)($l['title'] ?? ''), 'url' => (string)($l['url'] ?? '#')],
				array_slice($links, 1)
			) : [],
			'extraNote'     => trim((string)($mod->mailExtraNote ?? '')),
			'faggBlock'     => $this->buildFaggBlock(
				$mod,
				array_merge(
					$allMappedItems ?: $this->itemsFromLinks($mod, $links),
					array_values($unmappedLabels)
				)
			),
		];
		$p = $hasAccess ? $mod->wire('pages')->get((int)$links[0]['id']) : null;
		if ($p && $p->id && $p->hasField('access_mail_addon_txt')) {
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
		$m->subject($subject);
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
	 * FAGG order-confirmation block (right of withdrawal / waiver)
	 * ===================================================================*/

	/**
	 * Build the FAGG-mandated block for an order-confirmation mail.
	 * Splits the mapped items into withdrawal-type groups and renders one
	 * sub-block per group:
	 *   - 'service_redeemable' → instructions + model form + online-link
	 *   - 'digital_immediate'  → waiver acknowledgment
	 * Includes the universal "durable medium" notice + AGB link.
	 *
	 * @param StripePaymentLinks $mod
	 * @param \ProcessWire\Page[] $items  Mapped product pages from the order.
	 * @return string HTML (raw, intended for unescaped layout output).
	 */
	public function buildFaggBlock(StripePaymentLinks $mod, array $items): string
	{
		$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
		$brand = (string) ($mod->brandColor ?? '#7d0a3d');

		// Group items by classification.
		// Item can be:
		//   - \ProcessWire\Page  → classify via classifyWithdrawalType()
		//   - string             → unmapped Stripe label, treated as service_redeemable
		$groups = ['service_redeemable' => [], 'digital_immediate' => []];
		foreach ($items as $p) {
			if ($p instanceof \ProcessWire\Page && $p->id) {
				$type = $mod->classifyWithdrawalType($p);
				if (!isset($groups[$type])) $groups[$type] = [];
				$groups[$type][] = $p;
			} elseif (is_string($p) && trim($p) !== '') {
				$groups['service_redeemable'][] = $p;
			}
		}
		if (empty($groups['service_redeemable']) && empty($groups['digital_immediate'])) {
			return '';
		}

		$out = '<div style="margin:24px 0 0 0;padding:18px 22px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:6px;font-size:14px;color:#374151;line-height:1.55;">';

		// Universal: durable-medium notice + AGB link
		$out .= '<p style="margin:0 0 10px 0;">' . $h($mod->t('mail.fagg.durable_medium_notice')) . '</p>';
		$termsPage = (int) ($mod->termsPage ?? 0);
		if ($termsPage > 0) {
			$tp = $mod->wire('pages')->get($termsPage);
			if ($tp && $tp->id) {
				$out .= '<p style="margin:0 0 14px 0;"><a href="' . $h($tp->httpUrl) . '" style="color:' . $h($brand) . ';">'
					  . $h($mod->t('mail.fagg.terms_link_label')) . '</a></p>';
			}
		}

		// Group-specific blocks
		if (!empty($groups['service_redeemable'])) {
			$out .= $this->faggServiceBlock($mod, $groups['service_redeemable']);
		}
		if (!empty($groups['digital_immediate'])) {
			$out .= $this->faggWaiverBlock($mod, $groups['digital_immediate']);
		}

		$out .= '</div>';
		return $out;
	}

	private function faggServiceBlock(StripePaymentLinks $mod, array $pages): string
	{
		$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
		$config = wire('config');
		$brand = (string) ($mod->brandColor ?? '#7d0a3d');

		$contactEmail = trim((string) ($mod->withdrawalContactEmail ?? ''));
		if ($contactEmail === '') {
			$contactEmail = (string) ($mod->mailFromEmail ?? $config->adminEmail ?? '');
		}
		$provider = (string) ($mod->mailFromName ?? ($config->siteName ?? $config->httpHost ?? 'Provider'));

		$repl = ['{contact_email}' => $contactEmail, '{provider}' => $provider];
		$instructions = strtr((string) $mod->t('mail.fagg.withdrawal_instructions'), $repl);
		$formBody     = strtr((string) $mod->t('mail.fagg.withdrawal_form_body'),    $repl);

		$out  = '<hr style="border:none;border-top:1px solid #e5e7eb;margin:14px 0;">';
		$out .= '<p style="margin:0 0 6px 0;font-weight:700;color:#111;">' . $h($mod->t('mail.fagg.withdrawal_section_title')) . '</p>';
		$out .= '<p style="margin:0 0 10px 0;">' . $this->renderProductsLine($mod, $pages) . '</p>';
		$out .= '<div style="white-space:pre-line;margin:0 0 12px 0;">' . $h($instructions) . '</div>';

		// Online-withdrawal link (only for service_redeemable). The link
		// points at the site's root with ?withdraw=1; the module's render()
		// injects autoOpenScript() which detects the param and opens the
		// withdrawal modal on whatever frontend page the recipient lands on.
		$out .= '<p style="margin:0 0 6px 0;">' . $h($mod->t('mail.fagg.online_withdrawal_intro')) . '</p>';
		$root  = rtrim((string) $config->urls->httpRoot, '/');
		$wdUrl = $root . '/?withdraw=1';
		$out .= '<p style="margin:0 0 12px 0;"><a href="' . $h($wdUrl) . '" style="color:' . $h($brand) . ';">'
			  . $h($mod->t('mail.fagg.online_withdrawal_label')) . '</a></p>';

		// Contact for withdrawal
		if ($contactEmail !== '') {
			$out .= '<p style="margin:0 0 12px 0;">' . $h($mod->t('mail.fagg.contact_for_withdrawal_label')) . ': '
				  . '<a href="mailto:' . $h($contactEmail) . '" style="color:' . $h($brand) . ';">' . $h($contactEmail) . '</a></p>';
		}

		// Model withdrawal form
		$out .= '<p style="margin:14px 0 6px 0;font-weight:700;color:#111;">' . $h($mod->t('mail.fagg.withdrawal_form_title')) . '</p>';
		$out .= '<div style="white-space:pre-line;font-family:monospace;font-size:13px;color:#374151;">' . $h($formBody) . '</div>';

		return $out;
	}

	private function faggWaiverBlock(StripePaymentLinks $mod, array $pages): string
	{
		$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

		$out  = '<hr style="border:none;border-top:1px solid #e5e7eb;margin:14px 0;">';
		$out .= '<p style="margin:0 0 6px 0;font-weight:700;color:#111;">' . $h($mod->t('mail.fagg.digital_waiver_title')) . '</p>';
		$out .= '<p style="margin:0 0 6px 0;">' . $this->renderProductsLine($mod, $pages) . '</p>';
		$out .= '<p style="margin:0;">' . $h($mod->t('mail.fagg.digital_waiver_body')) . '</p>';

		return $out;
	}

	private function renderProductsLine(StripePaymentLinks $mod, array $items): string
	{
		$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
		$titles = [];
		foreach ($items as $p) {
			if ($p instanceof \ProcessWire\Page && $p->id) {
				$titles[] = (string) $p->title;
			} elseif (is_string($p) && trim($p) !== '') {
				$titles[] = trim($p);
			}
		}
		if (!$titles) return '';
		return '<em>' . $h($mod->t('mail.fagg.affected_products_label')) . '</em> ' . $h(implode(', ', $titles));
	}

	/**
	 * Fallback: derive Page[] from the legacy $links payload (each item has 'id').
	 * Used when sendAccessSummaryMail() is called without an explicit
	 * $allMappedItems list (e.g. magic-link send).
	 */
	private function itemsFromLinks(StripePaymentLinks $mod, array $links): array
	{
		$items = [];
		foreach ($links as $l) {
			$id = (int) ($l['id'] ?? 0);
			if ($id <= 0) continue;
			$p = $mod->wire('pages')->get($id);
			if ($p && $p->id) $items[] = $p;
		}
		return $items;
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