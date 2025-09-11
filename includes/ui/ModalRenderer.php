<?php namespace ProcessWire;

final class PLView {
  public static function render(string $path, array $vars = []): string {
	if (!is_file($path)) return '';
	extract($vars, EXTR_SKIP);
	ob_start();
	include $path;
	return ob_get_clean();
  }
}

final class ModalRenderer {
  private string $template;
  /** @var string|callable */
  private $csrf;

  /**
   * @param string $template Path to view
   * @param string|callable $csrf  HTML <input ...> string OR callable returning it at render time
   */
  public function __construct(string $template, $csrf) {
	$this->template = $template;
	$this->csrf     = $csrf;
  }

  private function csrfInputHtml(): string {
	return is_callable($this->csrf) ? (string)call_user_func($this->csrf) : (string)$this->csrf;
  }

  public function render(array $m): string {
	$defaults = [
	  'id'            => 'modal-' . uniqid(),
	  'title'         => '',
	  'headerClass'   => 'modal-header bg-primary',
	  'titleClass'    => 'modal-title text-white',
	  'closeWhite'    => true,
	  'body'          => '',
	  'footer'        => '',
	  'size'          => '',
	  'centered'      => true,
	  'scrollable'    => false,
	  'dialogClass'   => '',
	  'contentClass'  => '',
	  'form' => [
		'action'         => '',
		'method'         => 'post',
		'op'             => '',
		'attrs'          => [],
		'hidden'         => [],
		'fields'         => [],
		'submitText' => 'Submit',
		'cancelText' => 'Cancel',
		'footerClass'    => 'modal-footer',
		'bodyIntro'      => '',
		'afterFieldsHtml'=> '',
		'successId'      => '',
		'errorId'        => '',
	  ],
	];
	$m = array_replace_recursive($defaults, $m);

	$normalizeId = static function(string $v): string {
	  $v = preg_replace('/[^a-zA-Z0-9_-]/', '', $v);
	  return $v !== '' ? $v : ('id-' . uniqid());
	};

	$modalId   = $normalizeId($m['id']);
	$titleId   = $modalId . '-title';
	$errorId   = $m['form']['errorId']   ?: ($modalId . '-error');
	$successId = $m['form']['successId'] ?: ($modalId . '-success');

	$sizeClass = in_array($m['size'], ['sm','lg','xl'], true) ? ' modal-' . $m['size'] : '';
	$dialogCls = trim('modal-dialog'
	  . ($m['centered']  ? ' modal-dialog-centered'   : '')
	  . ($m['scrollable']? ' modal-dialog-scrollable' : '')
	  . $sizeClass
	  . ($m['dialogClass'] ? ' ' . $m['dialogClass'] : '')
	);
	$contentCls = trim('modal-content' . ($m['contentClass'] ? ' ' . $m['contentClass'] : ''));

	$vars = [
	  'm'            => $m,
	  'modalId'      => $modalId,
	  'titleId'      => $titleId,
	  'errorId'      => $errorId,
	  'successId'    => $successId,
	  'dialogClass'  => $dialogCls,
	  'contentClass' => $contentCls,
	  'csrfInput'    => $this->csrfInputHtml(),
	];
	return PLView::render($this->template, $vars);
  }
}