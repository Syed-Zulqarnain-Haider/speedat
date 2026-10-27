<?php

declare(strict_types=1);

/**
 * /track - the shipment number box, and the parcel's timeline under it.
 *
 * A plain GET form: works with no JavaScript, can be bookmarked, and the
 * WhatsApp link the office sends opens straight onto the result. Phone first:
 * one column, the status in large type, the newest scan at the top.
 *
 * Everything printed here comes from Http\TrackController through
 * Tracking\PublicTimeline - date, place, one of our status words - and goes
 * through e(). There is no name, phone or address on this page to leak.
 *
 * House style: no em dashes anywhere a reader can see.
 *
 * @var \Speedat\Core\View      $this
 * @var \Speedat\View\TrackView $view
 */

$this->layout('site/layout', ['page' => $view->page]);
?>
<section class="page track">
<?= $this->partial('site/partials/page-head', [
    'title' => 'Track your shipment',
    'lede'  => 'Enter the shipment number we sent you on WhatsApp. It starts with SH.',
]) ?>

  <form class="track-form" method="get" action="<?= e($this->url('/track')) ?>" role="search">
    <label class="field">
      <span>Shipment number</span>
      <input type="text" name="id" value="<?= e($view->typed) ?>" placeholder="SH-XXXXXXXX"
             maxlength="40" autocomplete="off" autocapitalize="characters" spellcheck="false"
             inputmode="text" required<?= $view->notFound() ? ' aria-invalid="true" aria-describedby="track-missing"' : '' ?>>
    </label>
    <button class="btn primary big" type="submit">Track</button>
  </form>

<?php if ($view->notFound()) { ?>
  <div class="notice err" id="track-missing" role="alert">
    We could not find that shipment number. Please check it against our
    WhatsApp message: it looks like SH-7KQ3MZ4P.
<?php if ($view->officeWaHref !== '') { ?>
    Still stuck? <a href="<?= e($view->officeWaHref) ?>" target="_blank" rel="noopener">Message us on WhatsApp</a>.
<?php } ?>
  </div>
<?php } ?>

<?php if ($view->found()) { ?>
  <article class="track-result" aria-labelledby="track-status">
    <p class="track-id">Shipment <strong><?= e($view->id) ?></strong><?= $view->destination !== '' ? ' to ' . e($view->destination) : '' ?></p>
    <h2 class="track-status is-<?= e($view->status) ?>" id="track-status"><?= e($view->statusLabel) ?></h2>
    <p class="track-text"><?= e($view->statusText) ?></p>
<?php if ($view->carrier !== '') { ?>
    <p class="track-meta">Carried by <?= e($view->carrier) ?></p>
<?php } ?>

<?php if ($view->rows !== []) { ?>
    <ol class="track-line">
<?php foreach ($view->rows as $row) { ?>
      <li class="is-<?= e($row['stage']) ?>">
        <span class="track-when"><time><?= e($row['date']) ?></time> <span><?= e($row['time']) ?></span></span>
        <span class="track-what"><?= e($row['label']) ?></span>
<?php if ($row['place'] !== '') { ?>
        <span class="track-where"><?= e($row['place']) ?></span>
<?php } ?>
      </li>
<?php } ?>
    </ol>
<?php } ?>

    <p class="track-note">
      Times are Pakistan time.
<?php if ($view->checkedAt !== '') { ?>
      Carrier last checked <?= e($view->checkedAt) ?>.
<?php } ?>
<?php if ($view->refreshSeconds > 0) { ?>
      This page updates by itself.
<?php } ?>
    </p>

    <div class="track-actions">
<?php if ($view->shareHref !== '') { ?>
      <a class="btn wa" href="<?= e($view->shareHref) ?>" target="_blank" rel="noopener"><?= $this->partial('site/partials/icon', ['id' => 'i-wa']) ?>Share on WhatsApp</a>
<?php } ?>
<?php if ($view->officeWaHref !== '') { ?>
      <a class="btn" href="<?= e($view->officeWaHref) ?>" target="_blank" rel="noopener">Ask us about it</a>
<?php } ?>
    </div>
  </article>
<?php } ?>
</section>
