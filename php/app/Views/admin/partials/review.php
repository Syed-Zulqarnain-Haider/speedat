<?php

declare(strict_types=1);

/**
 * "Review and publish". Port of the review block in AdminEditor.tsx.
 *
 * Errors replace the card: a document that cannot price a parcel must not have
 * a Publish button under it. Warnings sit inside it, above the button, because
 * only the owner can tell a typo from a deliberate discount.
 *
 * THE PUBLISH FORM CARRIES THE VERSION THE EDITOR WAS OPENED AT, which is
 * `$baseVersion` and NOT `$page->live->version`. It printed the live version
 * as it stands until 2026-09-28, and that number is re-read on every render -
 * so the form always handed back a value that agreed with the row it was
 * about to be compared with. `Repo::publishVersion()`'s stale-publish refusal
 * therefore could not fire from a browser at all, and an owner could publish
 * on top of a colleague's version without being told. `$baseVersion` is
 * minted when the editor is opened (`Http\Admin\EditorPage::openedAtBase()`)
 * and carried unchanged through every save and every redirect, so when it
 * disagrees the publish IS refused - which is the only reason the field
 * exists.
 *
 * `admin.js` does one thing here: scrolls the card clear of the fixed publish
 * bar. Without it the card is still on the page, still complete.
 *
 * @var \Speedat\Core\View            $this
 * @var \Speedat\Core\Csrf            $csrf
 * @var \Speedat\View\Admin\RatesPage $page
 * @var \Speedat\View\Admin\Review    $review
 * @var int                           $baseVersion
 */

use Speedat\View\Admin\Routes;

$baseVersion = isset($baseVersion) && is_int($baseVersion) ? $baseVersion : 0;
?>
<?php if ($review->blocked()) { ?>
<div class="notice err">
  <strong>Fix these before publishing</strong>
  <ul>
    <?php foreach ($review->errors as $error) { ?>
    <li><?= e($error) ?></li>
    <?php } ?>
  </ul>
</div>
<?php } else { ?>
<div class="card review">
  <p class="eyebrow">Review</p>
  <h2>Publishing version <?= e($review->nextVersion) ?></h2>
  <p class="desc"><?= e($review->countSentence()) ?> <?= e($review->effectSentence()) ?></p>
  <ul class="diff">
    <?php foreach ($review->lines as $line) { ?>
    <li>
      <?= e($line->label) ?><?= $line->hasValues() ? ': ' : '' ?>
      <?php if ($line->old !== null) { ?><span class="old"><?= e($line->old) ?></span><?php } ?>
      <?php if ($line->new !== null) { ?><span class="new"><?= e($line->new) ?></span><?php } ?>
      <?php if ($line->flag !== '') { ?><span class="flag"> <?= e($line->flag) ?></span><?php } ?>
    </li>
    <?php } ?>
  </ul>
  <?php if ($review->warnings !== []) { ?>
  <div class="notice warn">
    <strong>Worth a second look (publishing is still allowed)</strong>
    <ul>
      <?php foreach ($review->warningsShown() as $warning) { ?>
      <li><?= e($warning) ?></li>
      <?php } ?>
      <?php if ($review->moreWarnings() > 0) { ?>
      <li>&#8230;and <?= e($review->moreWarnings()) ?> more</li>
      <?php } ?>
    </ul>
  </div>
  <?php } ?>
  <form method="post" action="<?= e($this->url(Routes::PUBLISH)) ?>">
    <?= $csrf->field() ?>
    <input type="hidden" name="expectedBase" value="<?= e($baseVersion) ?>">
    <?php if ($review->offerGoLive) { ?>
    <label class="chk">
      <input type="hidden" name="goLive" value="0">
      <input type="checkbox" name="goLive" value="1"<?= $review->goLive ? ' checked' : '' ?>>
      Rates are live - remove the sample notice from the website
    </label>
    <?php } ?>
    <?php if ($review->holdOn) { ?>
    <label class="chk">
      <input type="hidden" name="resume" value="0">
      <input type="checkbox" name="resume" value="1"<?= $review->resume ? ' checked' : '' ?>>
      Show prices again after publishing (lifts the hold)
    </label>
    <?php } ?>
    <div class="review-actions">
      <?php if ($page->canPublish) { ?>
      <button class="btn book" type="submit" data-once data-busy-text="Publishing&#8230;">Publish version <?= e($review->nextVersion) ?></button>
      <?php } else { ?>
      <span class="meta">An owner publishes this.</span>
      <?php } ?>
      <a class="btn outline" href="<?= e($this->url(Routes::RATES)) ?>">Cancel</a>
    </div>
  </form>
  <?php if ($review->message !== '') { ?>
  <div class="meta review-msg" aria-live="polite"><?= e($review->message) ?></div>
  <?php } ?>
</div>
<?php } ?>
