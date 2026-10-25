<?php

declare(strict_types=1);

/**
 * /about. Port of `src/app/(site)/about/page.tsx`.
 *
 * The story as plain paragraphs, then the facts as a list of rows - office,
 * pickup cities, hours, cutoff - then mission, vision and values when the
 * owner wrote them, then the contact line. No cards, no numerals, no split.
 *
 * The two phone numbers are deliberately NOT rows here; see AboutView.
 *
 * @var \Speedat\Core\View      $this
 * @var \Speedat\View\AboutView $view
 */

$this->layout('site/layout', ['page' => $view->page]);
?>
<section class="page">
<?= $this->partial('site/partials/page-head', ['title' => $view->title]) ?>
<?php if ($view->story !== []) { ?>
  <div class="story">
<?php foreach ($view->story as $paragraph) { ?>
    <p><?= e($paragraph) ?></p>
<?php } ?>
  </div>
<?php } ?>
<?php if ($view->facts !== []) { ?>
<?= $this->partial('site/partials/facts', ['facts' => $view->facts]) ?>
<?php } ?>
<?php if ($view->mission !== '') { ?>
<?= $this->partial('site/partials/section-head', ['title' => 'Mission', 'id' => 'mission-title']) ?>
  <p class="prose"><?= e($view->mission) ?></p>
<?php } ?>
<?php if ($view->vision !== '') { ?>
<?= $this->partial('site/partials/section-head', ['title' => 'Vision', 'id' => 'vision-title']) ?>
  <p class="prose"><?= e($view->vision) ?></p>
<?php } ?>
<?php if ($view->values !== []) { ?>
<?= $this->partial('site/partials/section-head', ['title' => 'Values', 'id' => 'values-title']) ?>
<?php foreach ($view->values as $value) { ?>
  <p class="prose"><?php if ($value['lead'] !== '') { ?><strong><?= e($value['lead']) ?></strong><?php } ?><?php if ($value['text'] !== '') { ?> <?= e($value['text']) ?><?php } ?></p>
<?php } ?>
<?php } ?>
</section>
<?= $this->partial('site/partials/reach', ['reach' => $view->reach]) ?>
