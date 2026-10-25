<?php

declare(strict_types=1);

/**
 * /services. Port of `src/app/(site)/services/page.tsx`.
 *
 * The h1 and its line, every `content.services` line as a row of a plain list,
 * one paragraph of facts the page computes from the settings, then the rates
 * board in the same 720px column as the list (`.page-board` - at the full wrap
 * width a country sat ~900px from its prices) with the sample-prices notice
 * under it until the rates are live, then the contact line. No cards, no
 * icons, nothing that moves.
 *
 * @var \Speedat\Core\View         $this
 * @var \Speedat\View\ServicesView $view
 */

$this->layout('site/layout', ['page' => $view->page]);
?>
<section class="page">
<?= $this->partial('site/partials/page-head', ['title' => 'Services', 'lede' => $view->lede]) ?>
<?php if ($view->items !== []) { ?>
  <ul class="svc-list">
<?php foreach ($view->items as $item) { ?>
    <li><?php if ($item['title'] !== '') { ?><strong><?= e($item['title']) ?></strong><?php } ?><?php if ($item['text'] !== '') { ?><span><?= e($item['text']) ?></span><?php } ?></li>
<?php } ?>
  </ul>
<?php } ?>
<?php if ($view->hasFacts()) { ?>
  <p class="facts"><?= e($view->facts) ?><?php if ($view->factsLinkHome) { ?><a href="<?= e($this->url('/')) ?>">home page</a><?= e($view->factsTail) ?><?php } ?></p>
<?php } ?>
</section>
<div class="page-board">
<?= $this->partial('site/partials/rates-board', ['board' => $view->board]) ?>
<?php if ($view->board->sampleNotice) { ?>
  <p class="sample">Sample prices for now. We confirm the real price on WhatsApp.</p>
<?php } ?>
</div>
<?= $this->partial('site/partials/reach', ['reach' => $view->reach]) ?>
