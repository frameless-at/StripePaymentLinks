<?php namespace ProcessWire;
/**
 * Universal HTML mail layout (module template)
 * Reads variables passed via extract($vars) and renders sections only if values exist.
 * Optional variables (rendered if present and non-empty):
 *   - preheader, firstname, headline
 *   - productTitle, productUrl, ctaText, leadText
 *   - logoUrl, brandColor, fromName, brandHeader, headerTagline
 *   - footerNote, infoLabel, extraHeading, closingText, signatureName
 *   - extraCtas[] = ['title' => ?, 'url' => ?]
 *   - directLabel (defaults to "Direct link")
 *   - extraNotice (small note below the main button)
 */

$__all  = get_defined_vars();
$__vars = $__all; // simple bag

$has = function(string $k) use ($__vars): bool {
  if (!array_key_exists($k, $__vars)) return false;
  $v = $__vars[$k];
  if ($v === null) return false;
  if (is_string($v)) return trim($v) !== '';
  if (is_array($v))  return count($v) > 0;
  return (bool)$v;
};
$val = function(string $k, $default = '') use ($__vars, $has) {
  return $has($k) ? $__vars[$k] : $default;
};
$esc = function($s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
};

// Header bits & defaults
$brandColor   = $val('brandColor', '#0d6efd');
$brandHeader  = $val('brandHeader', $val('fromName', ''));
$directLabel  = $val('directLabel', 'Direct link');

// Auto-greeting if no headline provided
$headlineOut = $has('headline')
  ? $esc($val('headline'))
  : ($esc($val('greeting', 'Hello')) . ($has('firstname') ? ' ' . $esc($val('firstname')) : '') . ',');

// Decide if the info section should be shown — only when multiple
// products are present (extraCtas), or when the closing / signature
// is set. For single-product mails the CTA button is the entry point;
// the "Online product: …" and "Direct link: …" lines would just repeat
// what the button already says.
$showInfo = $has('extraCtas') || $has('closingText') || $has('signatureName');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $esc($val('productTitle')) ?></title>
  <style>
	body, table, td { font-family: Lato, Arial, sans-serif; }
	body  { color:#333; line-height:1.55; }
	h2    { font-size:22px; font-weight:700; color:#111; margin:0; }
	h3    { font-size:18px; font-weight:700; color:#111; margin:14px 0 6px 0; }
	p     { font-size:17px; color:#111; margin:0 0 12px 0; }
	.header-bar, .brand-name, .brand-tagline { color:#fff; }
	.brand-name    { font-size:18px; font-weight:700; }
	.brand-tagline { font-size:12px; }
	.btn-link      { font-size:16px; font-weight:700; color:#fff; text-decoration:none; }
	.info-cell, .info-cell p   { font-size:15px; color:#374151; }
	.extra-cta-h   { font-size:16px; font-weight:700; color:#111; }
	.extra-cta     { font-size:15px; }
	.extra-note, .extra-note p { font-size:13px; color:#6b7280; line-height:1.5; }
	.footer-note   { font-size:12px; color:#666; }
	.brand-link, .brand-link:link, .brand-link:visited { color:<?= $esc($brandColor) ?>; }
	@media (max-width: 600px){
	  .wrapper{ padding:12px !important; }
	  .btn    { width:100% !important; display:block !important; }
	  .px     { padding-left:18px !important; padding-right:18px !important; }
	}
  </style>
</head>
<body style="margin:0;padding:0;background:#eee;">
  <?php if ($has('preheader')): ?>
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;">
	<?= $esc($val('preheader')) ?>
  </div>
  <?php endif; ?>

  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eee;border-collapse:collapse;">
	<tr>
	  <td align="center" class="wrapper" style="padding:20px;">
		<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border-radius:8px;overflow:hidden;">

		  <!-- Header -->
		  <tr>
			<td class="px header-bar" style="background:<?= $esc($brandColor) ?>;padding:18px 22px;">
			  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">
				<tr>
				  <td align="left" style="vertical-align:middle;">
					<?php if ($has('logoUrl')): ?>
					  <img src="<?= $esc($val('logoUrl')) ?>"
						   alt="<?= $esc($brandHeader ?: $val('fromName')) ?>"
						   style="max-height:44px;max-width:100%;display:block;border:0;">
					<?php elseif ($brandHeader !== ''): ?>
					  <div class="brand-name" style="letter-spacing:.2px;">
						<?= $esc($brandHeader) ?>
					  </div>
					<?php endif; ?>
				  </td>
				  <td align="right" style="vertical-align:middle;">
					<?php if ($has('headerTagline')): ?>
					  <div class="brand-tagline" style="opacity:.9;">
						<?= $esc($val('headerTagline')) ?>
					  </div>
					<?php endif; ?>
				  </td>
				</tr>
			  </table>
			</td>
		  </tr>

		  <!-- Headline + (optional) sub-headline + Lead -->
		  <tr>
			<td class="px" style="padding:26px 22px 10px 22px;">
			  <h2><?= $headlineOut ?></h2>
			  <?php if ($has('subHeadline')): ?>
			  <h3><?= $esc($val('subHeadline')) ?></h3>
			  <?php endif; ?>
			  <?php if ($has('leadText')): ?>
			  <?php foreach (preg_split('/\n{2,}/', trim((string)$val('leadText'))) as $__para):
					$__para = trim($__para);
					if ($__para === '') continue; ?>
				<p><?= nl2br($esc($__para)) ?></p>
			  <?php endforeach; ?>
			  <?php endif; ?>
			  <?php if ($has('leadHtml')): // raw HTML body (e.g. rich-text CMS field) ?>
			  <div><?= $val('leadHtml') ?></div>
			  <?php endif; ?>
			</td>
		  </tr>

		  <!-- Primary CTA -->
		  <?php if ($has('productUrl') && $has('ctaText')): ?>
		  <tr>
			<td class="px" align="center" style="padding:0 22px 24px 22px;">
			  <table role="presentation" cellspacing="0" cellpadding="0" style="border-collapse:separate;">
				<tr>
				  <td bgcolor="<?= $esc($brandColor) ?>"
					  style="border-radius:9999px; mso-padding-alt:14px 32px;">
					<a class="btn-link" href="<?= $esc($val('productUrl')) ?>"
					   style="display:inline-block; padding:14px 32px;
							  border-radius:9999px; background:<?= $esc($brandColor) ?>;">
					  <?= $esc($val('ctaText')) ?>
					</a>
				  </td>
				</tr>
			  </table>
			</td>
		  </tr>
		  <?php endif; ?>

		  <!-- Info section (auto if any relevant piece exists) -->
		  <?php if ($showInfo): ?>
		  <tr>
			<td class="px info-cell" style="padding:0 22px 24px 22px;">
			  <?php if ($has('infoLabel') && $has('productTitle')): ?>
				<p style="margin:12px 0 6px 0;">
				  <?= $esc($val('infoLabel')) ?>:
				  <strong><?= $esc($val('productTitle')) ?></strong>
				</p>
			  <?php endif; ?>

			  <?php if ($has('productUrl') && $has('extraCtas')): ?>
			  <div style="margin:0;">
				<?= $esc($directLabel) ?>:
				<a class="brand-link" href="<?= $esc($val('productUrl')) ?>"
				   style="text-decoration:underline;"><?= $esc($has('ctaText') ? $val('ctaText') : $val('productTitle')) ?></a>
			  </div>
			  <?php endif; ?>

			  <?php if ($has('closingText') || $has('signatureName')): ?>
			  <p style="margin:22px 0 0 0;">
				<?= $has('closingText') ? $esc($val('closingText')) : '' ?>
				<?php if ($has('signatureName')): ?>
				  <br><?= $esc($val('signatureName')) ?>
				<?php endif; ?>
			  </p>
			  <?php endif; ?>
			</td>
		  </tr>
		  <?php endif; ?>

		  <!-- Extra CTAs (other access links) -->
		  <?php if ($has('extraCtas')): ?>
		  <tr>
			<td class="px" style="padding:0 22px 20px 22px;">
			  <hr style="border:none;border-top:1px solid #eee;margin:0 0 16px 0;">
			  <?php if ($has('extraHeading')): ?>
				<div class="extra-cta-h" style="margin:0 0 6px 0;"><?= $esc($val('extraHeading')) ?></div>
			  <?php endif; ?>
			  <?php foreach ((array)$val('extraCtas') as $c): ?>
				<?php
				  $ct  = isset($c['title']) ? trim((string)$c['title']) : '';
				  $curl= isset($c['url'])   ? (string)$c['url']         : '#';
				  if ($curl === '') continue;
				?>
				<div class="extra-cta" style="margin:6px 0;">
				  <?php if ($ct !== ''): ?><b><?= $esc($ct) ?>:</b> <?php endif; ?>
				  <a class="brand-link" href="<?= $esc($curl) ?>"
					 style="text-decoration:underline;word-break:break-all;">
					<?= $esc($curl) ?>
				  </a>
				</div>
			  <?php endforeach; ?>
			</td>
		  </tr>
		  <?php endif; ?>

		  <!-- Consumer-rights / right-of-withdrawal block (raw HTML, generated by PLMailService::buildFaggBlock) -->
		  <?php if ($has('faggBlock')): ?>
		  <tr>
			<td class="px" style="padding:0 22px 4px 22px;">
			  <?= $val('faggBlock') /* trusted module-generated HTML */ ?>
			</td>
		  </tr>
		  <?php endif; ?>

		  <!-- Extra note (e.g. legal disclaimer / waiver of right of withdrawal) -->
		  <?php if ($has('extraNote')): ?>
		  <tr>
			<td class="px extra-note" style="padding:0 22px 18px 22px;">
			  <?php
				$__note = (string)$val('extraNote');
				// If TinyMCE/HTML content, render raw; if plain text, escape and preserve line breaks.
				if (strpos($__note, '<') !== false) {
				  echo $__note;
				} else {
				  echo nl2br($esc($__note));
				}
			  ?>
			</td>
		  </tr>
		  <?php endif; ?>

		  <!-- Footer note -->
		  <?php if ($has('footerNote')): ?>
		  <tr>
			<td class="footer-note" style="background:#f8fafc;padding:14px 22px;border-top:1px solid #eee;">
			  <?= $esc($val('footerNote')) ?>
			</td>
		  </tr>
		  <?php endif; ?>

		</table>
	  </td>
	</tr>
  </table>
</body>
</html>
