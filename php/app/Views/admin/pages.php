<?php

declare(strict_types=1);

/**
 * Website text: the words and search titles on the public pages, published
 * straight to the site. One ordinary form: no autosave, no draft, and it works
 * with JavaScript off.
 *
 * @var \Speedat\Core\View            $this
 * @var \Speedat\Core\Csrf            $csrf
 * @var \Speedat\View\Admin\PagesPage $page
 * @var \Speedat\View\Admin\Shell     $shell
 */

use Speedat\View\Admin\Routes;

$this->layout('admin/layouts/shell', ['title' => 'Website text', 'shell' => $shell, 'toast' => $page->toast]);
?>
<section class="admin">
  <div class="topbar">
    <div>
      <p class="eyebrow">Pages</p>
      <h1>Website text</h1>
    </div>
    <div class="topbar-right">
      <span class="meta"><?= e($page->liveMeta()) ?></span>
    </div>
  </div>

  <?php if ($page->error !== '') { ?>
  <div class="notice err" role="alert"><?= e($page->error) ?> Nothing was published. <a href="<?= e($this->url(Routes::PAGES)) ?>" target="_blank" rel="noopener">Open the latest version</a></div>
  <?php } ?>

  <form class="panel" id="pagesform" method="post" action="<?= e($this->url(Routes::PAGES_PUBLISH)) ?>">
    <?= $csrf->field() ?>
  <?php /* Enter in a one-line box presses a form's first submit button. This
           is it, and it is disabled: publishing is a press on Publish. */ ?>
  <button type="submit" disabled hidden></button>
  <input type="hidden" name="base" value="<?= e($page->base) ?>">
    <p class="desc">Publishing puts these words on the website straight away. Prices are not changed. One line per item where it says so; separate the parts of a line with a vertical bar |.</p>
    <?= $this->partial('admin/partials/field-groups', ['groups' => $page->groups()]) ?>
    <div class="btn-row">
      <button class="btn primary" type="submit" data-once data-busy-text="Publishing&#8230;"<?= $page->ready() ? '' : ' disabled' ?>>Publish text</button>
    </div>
    <p class="hint">Every publish is a version in the rates History, with who made it. Rolling one back returns the whole website to how it was then, prices included, if prices were published since.</p>
  </form>
</section>
