<?php

declare(strict_types=1);

/**
 * The marketers' editor: one website page at a time. An ordinary form that
 * works with JavaScript off; js/marketing.js adds the live search preview, the
 * character counts and the unsaved-changes warning.
 *
 * @var \Speedat\Core\View                    $this
 * @var \Speedat\Core\Csrf                    $csrf
 * @var \Speedat\View\Marketing\PageEditor    $page
 * @var \Speedat\Http\Admin\AdminUser         $admin
 * @var string                                $theme
 */

use Speedat\View\Admin\Routes;
use Speedat\View\Marketing\Tabs;

$this->layout('marketing/layouts/shell', [
    'title'   => ($page->error !== '' ? 'Not published - ' : ($page->notice !== '' ? 'Published - ' : '')) . Tabs::label($page->tab) . ($page->hasSearchResult() ? ' page' : ''),
    'theme'   => $theme,
    'current' => 'editor',
    'admin'   => $admin,
]);

$path = Tabs::path($page->tab);
?>
<div class="mk-head">
  <div>
    <h1>Website text</h1>
    <p class="mk-lede">Change the words on the website and how it looks in Google. Publishing goes live straight away.</p>
  </div>
  <?php if ($path !== '') { ?>
  <a class="btn outline" href="<?= e($this->url($path)) ?>" target="_blank" rel="noopener">View this page <span aria-hidden="true">&#8599;</span></a>
  <?php } ?>
</div>

<nav class="mk-tabs" aria-label="Website pages">
  <?php foreach (Tabs::labels() as $key => $label) { ?>
  <a href="<?= e($this->url(Routes::MARKETING)) ?>?page=<?= e($key) ?>"<?= $key === $page->tab ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
  <?php } ?>
</nav>

<?php if ($page->error !== '') { ?>
<div class="notice err" role="alert" tabindex="-1" autofocus><?= e($page->error) ?> Nothing was published. <a href="<?= e($this->url(Routes::MARKETING)) ?>?page=<?= e($page->tab) ?>" target="_blank" rel="noopener">Open the latest version</a></div>
<?php } elseif ($page->notice !== '') { ?>
<div class="notice ok" role="status" tabindex="-1" autofocus><?= e($page->notice) ?></div>
<?php } ?>
<?php if (!$page->ready()) { ?>
<div class="notice warn">The website has nothing published yet. Ask an admin to publish it first.</div>
<?php } ?>

<form class="mk-form" id="marketing-form" method="post" action="<?= e($this->url(Routes::MARKETING_PUBLISH)) ?>" aria-label="<?= e(Tabs::label($page->tab)) ?>" data-session="<?= e($this->url(Routes::MARKETING_SESSION)) ?>">
  <?= $csrf->field() ?>
  <?php /* Enter in a one-line box presses a form's first submit button. This
           is it, and it is disabled: publishing is a press on Publish. */ ?>
  <button type="submit" disabled hidden></button>
  <input type="hidden" name="base" value="<?= e($page->base) ?>">
  <input type="hidden" name="page" value="<?= e($page->tab) ?>">

  <?php if ($page->hasSearchResult()) { ?>
  <fieldset class="mk-card">
    <legend>In Google</legend>
    <div class="mk-serp" aria-label="How this page looks in Google" role="group" data-serp data-auto-title="<?= e($page->autoTitle) ?>" data-auto-desc="<?= e($page->autoDescription) ?>">
      <span class="mk-serp-url"><?= e($page->address) ?></span>
      <span class="mk-serp-title" data-serp-title><?= e($page->shownTitle()) ?></span>
      <span class="mk-serp-desc" data-serp-desc><?= e($page->shownDescription()) ?></span>
    </div>
    <?php foreach ($page->seo() as $i => $field) { ?>
    <?= $this->partial('marketing/partials/field', [
        'field' => $field,
        'role'  => $i === 0 ? 'title' : 'desc',
        'best'  => $i === 0 ? 60 : 160,
    ]) ?>
    <?php } ?>
  </fieldset>
  <?php } ?>

  <fieldset class="mk-card">
    <legend>On the page</legend>
    <?php foreach ($page->words() as $field) { ?>
    <?= $this->partial('marketing/partials/field', ['field' => $field, 'role' => '', 'best' => 0, 'source' => $field->name === 'content[' . Tabs::source($page->tab) . ']']) ?>
    <?php } ?>
  </fieldset>

  <div class="mk-bar">
    <p class="mk-state" data-state aria-live="polite">Prices and contact details are not changed from here.</p>
    <button class="btn primary big" type="submit" data-publish<?= $page->ready() ? '' : ' disabled' ?>>Publish <?= $page->hasSearchResult() ? 'this page' : 'contact line' ?></button>
  </div>
</form>

<?php if ($page->recentRows() !== []) { ?>
<section class="mk-recent" aria-labelledby="mk-recent-h">
  <h2 id="mk-recent-h">Recent text changes</h2>
  <ul>
    <?php foreach ($page->recentRows() as $row) { ?>
    <li><span><?= e($row['what']) ?> by <?= e($row['who']) ?></span> <time><?= e($row['when']) ?></time></li>
    <?php } ?>
  </ul>
  <p class="hint">Made a mistake? Change it back and publish again, or ask an admin.</p>
</section>
<?php } ?>
