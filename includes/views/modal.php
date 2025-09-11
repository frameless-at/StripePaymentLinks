<?php namespace ProcessWire;
/**
 * Bootstrap modal view (rendered by ModalRenderer)
 *
 * Expected variables (injected by ModalRenderer):
 *  - array  $m                 Structured modal data (see ModalRenderer::$defaults)
 *  - string $modalId           Unique modal element ID
 *  - string $titleId           Heading ID for aria-labelledby
 *  - string $errorId           ID for error message container
 *  - string $successId         ID for success message container
 *  - string $dialogClass       Computed dialog class list
 *  - string $contentClass      Computed content class list
 *  - string $csrfInput         CSRF <input> HTML (already safe)
 */

$e = static function($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

$attr = static function(array $kv) use ($e): string {
  $out = '';
  foreach ($kv as $k => $v) {
	if ($v === null || $v === false) continue;
	$out .= ($v === true) ? ' ' . $e($k) : ' ' . $e($k) . '="' . $e($v) . '"';
  }
  return $out;
};

$renderField = static function(array $f) use ($e, $modalId): string {
  $f = array_merge(['type'=>'text','name'=>'','label'=>'','value'=>'','attrs'=>[]], $f);
  $base = $f['name'] !== '' ? preg_replace('/[^a-z0-9_-]+/i','',(string)$f['name']) : ('fld-'.uniqid());
  $id   = $f['attrs']['id'] ?? ($modalId . '-' . $base);

  // Rebuild attributes (id handled separately)
  $attrs = '';
  foreach ($f['attrs'] as $k => $v) {
	if ($k === 'id') continue;
	if ($v === true)       $attrs .= ' ' . $e($k);
	elseif ($v !== false)  $attrs .= ' ' . $e($k) . '="' . $e($v) . '"';
  }

  if ($f['type'] === 'hidden') {
	return '<input type="hidden" id="'.$e($id).'" name="'.$e($f['name']).'" value="'.$e($f['value']).'">';
  }

  $label   = $f['label'] !== '' ? '<label class="form-label" for="'.$e($id).'">'.$e($f['label']).'</label>' : '';
  $valAttr = ($f['type'] === 'password') ? '' : ' value="'.$e($f['value']).'"';

  return '<div class="mb-3">'.$label
	   . '<input id="'.$e($id).'" type="'.$e($f['type']).'" class="form-control" name="'.$e($f['name']).'"'
	   . $valAttr . $attrs . '>'
	   . '</div>';
};
?>
<div class="modal fade" id="<?= $e($modalId) ?>" tabindex="-1" aria-hidden="true" role="dialog" aria-labelledby="<?= $e($titleId) ?>">
  <div class="<?= $e($dialogClass) ?>">
	<div class="<?= $e($contentClass) ?>">

	  <?php if ($m['title'] !== ''): ?>
	  <div class="<?= $e($m['headerClass']) ?>">
		<h5 id="<?= $e($titleId) ?>" class="<?= $e($m['titleClass']) ?>"><?= $m['title'] /* trusted HTML */ ?></h5>
		<button type="button" class="btn-close<?= $m['closeWhite'] ? ' btn-close-white' : '' ?>" data-bs-dismiss="modal" aria-label="Close"></button>
	  </div>
	  <?php endif; ?>

	  <?php if (!empty($m['form']['action'])): ?>
	  <!-- Form as modal-body, but without padding (p-0) -->
	  <form
		class="modal-body p-0 d-flex flex-column"
		method="<?= $e($m['form']['method']) ?>"
		action="<?= $e($m['form']['action']) ?>"
		data-ajax="pw-json"
		data-error-id="<?= $e($errorId) ?>"
		data-success-id="<?= $e($successId) ?>"
		<?= $attr($m['form']['attrs']) ?>
		aria-describedby="<?= $e($errorId) ?> <?= $e($successId) ?>"
	  >
		<!-- Padded content (like your example: .p-3) -->
		<div class="p-3">
		  <?= $csrfInput /* CSRF input */ ?>
		  <?php if ($m['form']['op'] !== ''): ?>
			<input type="hidden" name="op" value="<?= $e($m['form']['op']) ?>">
		  <?php endif; ?>

		  <?php foreach (($m['form']['hidden'] ?? []) as $hn => $hv): ?>
			<input type="hidden" name="<?= $e($hn) ?>" value="<?= $e((string)$hv) ?>">
		  <?php endforeach; ?>

		  <?php if (!empty($m['form']['bodyIntro'])) echo $m['form']['bodyIntro']; // trusted HTML ?>

		  <?php foreach (($m['form']['fields'] ?? []) as $field) echo $renderField($field); ?>

		  <?php if (!empty($m['form']['afterFieldsHtml'])): ?>
			<div class="my-2"><?= $m['form']['afterFieldsHtml'] /* trusted HTML */ ?></div>
		  <?php endif; ?>

		  <div class="text-danger small" id="<?= $e($errorId) ?>" style="display:none;"></div>
		  <div class="text-success small" id="<?= $e($successId) ?>" style="display:none;"></div>
		</div>

		<!-- Footer separate from body padding (submit stays inside <form>) -->
		<div class="<?= $e($m['form']['footerClass']) ?>">
		  <?php if ($m['form']['cancelText'] !== ''): ?>
			<button class="btn btn-secondary" data-bs-dismiss="modal" type="button"><?= $e($m['form']['cancelText']) ?></button>
		  <?php endif; ?>
		  <button class="btn btn-primary" type="submit"><?= $e($m['form']['submitText']) ?></button>
		</div>
	  </form>

	  <?php else: ?>
	  <!-- Static modal body with normal padding -->
	  <div class="modal-body p-3">
		<?= $m['body'] /* trusted HTML */ ?>
	  </div>
	  <?php if ($m['footer'] !== ''): ?>
	  <div class="modal-footer">
		<?= $m['footer'] /* trusted HTML */ ?>
	  </div>
	  <?php endif; ?>
	  <?php endif; ?>

	</div>
  </div>
</div>