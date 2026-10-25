<?php

declare(strict_types=1);

/**
 * /faq - "Questions". Port of `src/app/(site)/faq/page.tsx`.
 *
 * `content.faq` as one list with every answer OPEN: nothing to tap to read,
 * nothing that folds, a hairline after each pair, then the contact line. There
 * is no accordion here and none in the Next app; ui.js has no code for one.
 *
 * @var \Speedat\Core\View    $this
 * @var \Speedat\View\FaqView $view
 */

$this->layout('site/layout', ['page' => $view->page]);
?>
<section class="page">
<?= $this->partial('site/partials/page-head', ['title' => 'Questions', 'lede' => $view->lede]) ?>
<?php if ($view->items !== []) { ?>
  <dl class="faq-list">
<?php foreach ($view->items as $item) { ?>
    <div>
      <dt><?= e($item['q']) ?></dt>
      <dd><?= e($item['a']) ?></dd>
    </div>
<?php } ?>
  </dl>
<?php } ?>
</section>
<?= $this->partial('site/partials/reach', ['reach' => $view->reach]) ?>
