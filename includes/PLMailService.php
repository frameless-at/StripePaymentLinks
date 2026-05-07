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
	public function sendAccessSummaryMail(StripePaymentLinks $mod, User $user, array $links, array $allMappedItems = [], array $unmappedLabels = [], array $orderMeta = []): bool
	{
		$mail   = wire('mail');
		$config = wire('config');
		$hasAccess = !empty($links);
		$isMulti   = count($links) > 1;
		$listText  = implode(', ', array_filter(array_map(fn($l) => (string)($l['title'] ?? ''), $links)));
		$repl      = ['{title}' => (string)($links[0]['title'] ?? $mod->t('mail.common.product_fallback')), '{list}' => $listText];

		// Subject + lead vary depending on whether the order has gated products.
		// Both branches now share the same opener: greeting (via empty headline
		// → fallback "Hello {firstname},") + sub-headline ("thank you for your
		// order!") + lead. Access-mail additionally appends the access body.
		$subHeadline = $mod->t('mail.order.title');
		$headline    = '';
		if ($hasAccess) {
			$subjectKey   = $isMulti ? 'mail.multi.subject'   : 'mail.single.subject';
			$preheaderKey = $isMulti ? 'mail.multi.preheader' : 'mail.single.preheader';
			$bodyKey      = $isMulti ? 'mail.multi.body'      : 'mail.single.body';
			$ctaKey       = $isMulti ? 'mail.multi.cta'       : 'mail.single.cta';
			$preheader = strtr($mod->t($preheaderKey), $repl);
			$leadText  = $mod->t('mail.order.body') . "\n\n" . strtr($mod->t($bodyKey), $repl);
			$ctaText   = strtr($mod->t($ctaKey), $repl);
			$productUrl= (string)($links[0]['url'] ?? '#');
			$subject   = ($mod->subjectPrefix ?? '') . strtr($mod->t($subjectKey), $repl);
			$tagline   = $mod->t('mail.common.header_tagline');
		} else {
			$preheader = $mod->t('mail.order.preheader');
			$leadText  = $mod->t('mail.order.body');
			$ctaText   = '';
			$productUrl= '';
			$subject   = ($mod->subjectPrefix ?? '') . $mod->t('mail.order.subject');
			$tagline   = $mod->t('mail.order.tagline');
		}

		$vars = [
			'preheader'     => $preheader,
			'firstname'     => $this->displayName($user),
			'productTitle'  => $repl['{title}'],
			'productUrl'    => $productUrl,
			'ctaText'       => $ctaText,
			'subHeadline'   => $subHeadline,
			'leadText'      => $leadText,
			'logoUrl'       => (string)($mod->logoUrl ?? ''),
			'brandColor'    => (string)($mod->brandColor ?? '#7d0a3d'),
			'fromName'      => (string)($mod->mailFromName ?? ($config->siteName ?? $config->httpHost ?? 'Website')),
			'brandHeader'   => (string)($mod->mailHeaderName ?? ''),
			'headerTagline' => $tagline,
			'headline'      => $headline,
			'footerNote'    => $mod->t('mail.common.footer_note'),
			'infoLabel'     => '',
			'extraHeading'  => $mod->t('mail.common.extra_heading'),
			'closingText'   => '',
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
				),
				$orderMeta
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
	 * Order-confirmation consumer-rights block (withdrawal / waiver)
	 * ===================================================================*/

	/**
	 * Build the consumer-rights block of the order-confirmation mail.
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
	/**
	 * Build the consumer-rights block of the order-confirmation mail.
	 *
	 * Renders one or both site-configured TinyMCE texts depending on the
	 * order content:
	 *   - mailWithdrawalText for service_redeemable / unmapped items
	 *   - mailWaiverText     for digital_immediate (gated) items
	 *
	 * Each text is rendered with placeholder substitution (see expandPlaceholders).
	 * Site operators are responsible for the legal wording in their jurisdiction.
	 *
	 * @param StripePaymentLinks $mod
	 * @param array $items   Mix of \ProcessWire\Page and unmapped Stripe label strings.
	 * @param array $orderMeta  ['session_id', 'order_date', 'name', 'email']
	 * @return string HTML (raw, intended for unescaped layout output).
	 */
	public function buildFaggBlock(StripePaymentLinks $mod, array $items, array $orderMeta = []): string
	{
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

		$out = '';
		$hr  = '<hr style="border:none;border-top:1px solid #e5e7eb;margin:24px 0 18px 0;">';

		$wdText = trim((string) ($mod->mailWithdrawalText ?? ''));
		if ($wdText !== '' && !empty($groups['service_redeemable'])) {
			$out .= $hr . $this->expandPlaceholders($mod, $wdText, $groups['service_redeemable'], $orderMeta);
		}
		$wvText = trim((string) ($mod->mailWaiverText ?? ''));
		if ($wvText !== '' && !empty($groups['digital_immediate'])) {
			$out .= $hr . $this->expandPlaceholders($mod, $wvText, $groups['digital_immediate'], $orderMeta);
		}
		return $out;
	}

	/**
	 * Replace placeholders in a TinyMCE-edited text with values derived from
	 * the order context. Returns raw HTML (caller writes it unescaped).
	 *
	 * Simple value placeholders:
	 *   {products}, {provider}, {contact_email},
	 *   {order_id}, {order_date}, {name}, {email}, {today}
	 *
	 * Anchor-pair placeholders (rendered as <a href="…">linktext</a> — TinyMCE
	 * strips/normalizes raw {…} inside href attributes, so a wrapping pair is
	 * the only way to let editors keep linktext separate from the URL). Used
	 * only for URLs the editor cannot type literally:
	 *   {withdrawal_mail}LINKTEXT{withdrawal_mail_end}    — pre-filled mailto:
	 *                                                       (subject + body)
	 *   {withdrawal_online}LINKTEXT{withdrawal_online_end}— site root + ?withdraw=1
	 *
	 * For a plain mailto: to the contact address, editors use TinyMCE's link
	 * tool directly — no placeholder needed.
	 */
	private function expandPlaceholders(StripePaymentLinks $mod, string $html, array $items, array $orderMeta): string
	{
		$config = wire('config');
		$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

		$titles = [];
		foreach ($items as $p) {
			if ($p instanceof \ProcessWire\Page && $p->id) $titles[] = (string) $p->title;
			elseif (is_string($p) && trim($p) !== '')      $titles[] = trim($p);
		}
		$products = implode(', ', $titles);

		$contactEmail = trim((string) ($mod->withdrawalContactEmail ?? ''));
		if ($contactEmail === '') {
			$contactEmail = (string) ($mod->mailFromEmail ?? $config->adminEmail ?? '');
		}
		$provider = (string) ($mod->mailFromName ?? ($config->siteName ?? $config->httpHost ?? ''));
		$root     = rtrim((string) $config->urls->httpRoot, '/');

		// Anchor-pair placeholders first — replaced with <a> tags. The
		// TinyMCE editor saves the inner LINKTEXT as is; we just wrap.
		$urls = [
			'withdrawal_mail'   => $this->buildWithdrawalMailto($mod, $items, $contactEmail, $orderMeta),
			'withdrawal_online' => $root . '/?withdraw=1',
		];
		foreach ($urls as $key => $url) {
			$pattern = '/\{' . preg_quote($key, '/') . '\}(.*?)\{' . preg_quote($key . '_end', '/') . '\}/s';
			$html = preg_replace_callback($pattern, function($m) use ($url, $h) {
				if ($url === '') return $m[1]; // no URL → fall back to plain linktext
				return '<a href="' . $h($url) . '">' . $m[1] . '</a>';
			}, $html);
		}

		// Simple value placeholders
		$repl = [
			'{products}'      => $products !== '' ? $products : '—',
			'{provider}'      => $provider,
			'{contact_email}' => $contactEmail,
			'{order_id}'      => (string) ($orderMeta['session_id'] ?? '—'),
			'{order_date}'    => (string) ($orderMeta['order_date'] ?? '—'),
			'{name}'          => (string) ($orderMeta['name']  ?? '—'),
			'{email}'         => (string) ($orderMeta['email'] ?? '—'),
			'{today}'         => date('Y-m-d'),
		];
		return strtr($html, $repl);
	}

	/**
	 * Build a pre-filled mailto: link for the consumer's withdrawal declaration.
	 * Subject and body are filled from the order metadata so the recipient
	 * just needs to send the message.
	 */
	private function buildWithdrawalMailto(StripePaymentLinks $mod, array $pages, string $contactEmail, array $orderMeta): string
	{
		if ($contactEmail === '') return '';

		$config = wire('config');
		$provider = (string) ($mod->mailFromName ?? ($config->siteName ?? $config->httpHost ?? 'Provider'));

		$titles = [];
		foreach ($pages as $p) {
			if ($p instanceof \ProcessWire\Page && $p->id) $titles[] = (string) $p->title;
			elseif (is_string($p) && trim($p) !== '') $titles[] = trim($p);
		}
		$products = implode(', ', $titles);

		$repl = [
			'{provider}'   => $provider,
			'{order_id}'   => (string) ($orderMeta['session_id'] ?? '—'),
			'{order_date}' => (string) ($orderMeta['order_date'] ?? '—'),
			'{products}'   => $products !== '' ? $products : '—',
			'{name}'       => (string) ($orderMeta['name']  ?? '—'),
			'{email}'      => (string) ($orderMeta['email'] ?? '—'),
			'{today}'      => date('Y-m-d'),
		];

		$subject = strtr((string) $mod->t('mail.fagg.withdrawal_mailto_subject'), $repl);
		$body    = strtr((string) $mod->t('mail.fagg.withdrawal_mailto_body'),    $repl);

		return 'mailto:' . rawurlencode($contactEmail)
			 . '?subject=' . rawurlencode($subject)
			 . '&body='   . rawurlencode($body);
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