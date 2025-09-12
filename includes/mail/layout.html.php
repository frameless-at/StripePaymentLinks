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
  : ('Hello' . ($has('firstname') ? ' ' . $esc($val('firstname')) : '') . ',');

// Decide if the info section should be shown (no explicit flag needed)
$showInfo = $has('productUrl') || $has('infoLabel') || $has('closingText') || $has('signatureName');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $esc($val('productTitle')) ?></title>
  <style>
	@media (max-width: 600px){
	  .wrapper{ padding:12px !important; }
	  .h1     { font-size:20px !important; }
	  .lead   { font-size:16px !important; }
	  .btn    { width:100% !important; display:block !important; }
	  .px     { padding-left:18px !important; padding-right:18px !important; }
	}
  </style>
</head>
<body style="margin:0;padding:0;background:#eee;font-family:Lato, Arial, sans-serif;color:#333;line-height:1.55;">
  <?php if ($has('preheader')): ?>
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">
	<?= $esc($val('preheader')) ?>
  </div>
  <?php endif; ?>

  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eee;border-collapse:collapse;">
	<tr>
	  <td align="center" class="wrapper" style="padding:20px;">
		<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border-radius:8px;overflow:hidden;">

		  <!-- Header -->
		  <tr>
			<td class="px" style="background:<?= $esc($brandColor) ?>;padding:18px 22px;color:#fff;">
			  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">
				<tr>
				  <td align="left" style="vertical-align:middle;">
					<?php if ($has('logoUrl')): ?>
					  <img src="<?= $esc($val('logoUrl')) ?>"
						   alt="<?= $esc($brandHeader ?: $val('fromName')) ?>"
						   style="max-height:44px;max-width:100%;display:block;border:0;">
					<?php elseif ($brandHeader !== ''): ?>
					  <div style="font-weight:700;font-size:18px;letter-spacing:.2px;">
						<?= $esc($brandHeader) ?>
					  </div>
					<?php endif; ?>
				  </td>
				  <td align="right" style="vertical-align:middle;">
					<?php if ($has('headerTagline')): ?>
					  <div style="font-size:12px;opacity:.9;color:#fff;">
						<?= $esc($val('headerTagline')) ?>
					  </div>
					<?php endif; ?>
				  </td>
				</tr>
			  </table>
			</td>
		  </tr>

		  <!-- Headline + Lead -->
		  <tr>
			<td class="px" style="padding:26px 22px 10px 22px;">
			  <div class="h1" style="font-size:22px;font-weight:700;margin:0 0 4px 0;color:#111;">
				<?= $headlineOut ?>
			  </div>
			  <?php if ($has('leadText')): ?>
			  <div class="lead" style="font-size:17px;margin:10px 0 18px 0;color:#111;">
				<?= $esc($val('leadText')) ?>
			  </div>
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
					<a href="<?= $esc($val('productUrl')) ?>"
					   style="display:inline-block; padding:14px 32px; font-weight:700; font-size:16px;
							  color:#ffffff; text-decoration:none; font-family:Lato, Arial, sans-serif;
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
			<td class="px" style="padding:0 22px 24px 22px;font-size:15px;color:#374151;">
			  <?php if ($has('infoLabel') && $has('productTitle')): ?>
				<p style="margin:12px 0 6px 0;">
				  <?= $esc($val('infoLabel')) ?>:
				  <strong><?= $esc($val('productTitle')) ?></strong>
				</p>
			  <?php endif; ?>

			  <?php if ($has('productUrl')): ?>
			  <p style="margin:0 0 0 0;word-break:break-all;">
				<?= $esc($directLabel) ?>:
				<a href="<?= $esc($val('productUrl')) ?>"
				   style="color:<?= $esc($brandColor) ?>;text-decoration:underline;"><?= $esc($val('productUrl')) ?></a>
			  </p>
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
				<div style="font-size:16px;font-weight:700;margin:0 0 6px 0;color:#111;"><?= $esc($val('extraHeading')) ?></div>
			  <?php endif; ?>
			  <?php foreach ((array)$val('extraCtas') as $c): ?>
				<?php
				  $ct  = isset($c['title']) ? trim((string)$c['title']) : '';
				  $curl= isset($c['url'])   ? (string)$c['url']         : '#';
				  if ($curl === '') continue;
				?>
				<div style="margin:6px 0;font-size:15px;">
				  <?php if ($ct !== ''): ?><b><?= $esc($ct) ?>:</b> <?php endif; ?>
				  <a href="<?= $esc($curl) ?>"
					 style="color:<?= $esc($brandColor) ?>;text-decoration:underline;word-break:break-all;">
					<?= $esc($curl) ?>
				  </a>
				</div>
			  <?php endforeach; ?>
			</td>
		  </tr>
		  <?php endif; ?>

		  <!-- Footer note -->
		  <?php if ($has('footerNote')): ?>
		  <tr>
			<td style="background:#f8fafc;padding:14px 22px;border-top:1px solid #eee;color:#666;font-size:12px;">
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