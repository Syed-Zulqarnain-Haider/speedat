<?php

declare(strict_types=1);

/**
 * Version history, and the two things that can be done with an old version.
 *
 * RESTORE puts it in the EDITOR. Its rates, settings and words are copied
 * into the draft and nothing else happens - the owner still reviews and
 * publishes, which is why the button does not say "Go live".
 *
 * ROLL BACK puts it on the WEBSITE. It is the answer to "that publish was
 * wrong, take it off, now", which until this button existed was four steps -
 * restore, find the review screen, read the diff, publish - performed by
 * somebody who has just realised the site is quoting the wrong prices, which
 * is the worst possible moment to ask anybody for four steps. It publishes,
 * so it is the owner's alone (enforced by `Guard::owner()` in app/routes.php,
 * not by the absence of a button), and it is TWO posts: the first writes
 * nothing and brings back the question card below, the second carries
 * `confirm=1`.
 *
 * One form for the whole list, with each row's button carrying the version as
 * its own value. A <form> wrapped around each button would have become the
 * grid item admin.css places in column 2 on a phone, and the button inside it
 * would have been placed by nothing - the list would break at 719px. This way
 * the button is the direct child of the <li>, exactly as in the React version.
 *
 * @var \Speedat\Core\View            $this
 * @var \Speedat\Core\Csrf            $csrf
 * @var \Speedat\View\Admin\RatesPage $page
 * @var string                        $revision    the draft fingerprint, for Restore
 * @var int                           $baseVersion the version the editor was opened at
 */

use Speedat\View\Admin\Routes;
use Speedat\View\Admin\Sections;

$ask = $page->rollback;

$revision    = isset($revision) && is_string($revision) ? $revision : '';
$baseVersion = isset($baseVersion) && is_int($baseVersion) ? $baseVersion : 0;

// RESTORE REPLACES THE WHOLE DRAFT. With unpublished edits in it, the button
// asks first - the same two-step, counted confirm Discard has - because one
// press used to throw away thirty edits with no question and a toast that only
// said the old version was back. Empty when there is nothing to lose.
$restoreAsk = static fn (int $version): string => $page->changeCount > 0
    ? 'Replace your ' . $page->changeCount . ' unpublished change' . ($page->changeCount === 1 ? '' : 's') . ' with version ' . $version . '?'
    : '';
?>
<section class="block" id="sec-history">
  <p class="eyebrow"><?= e(Sections::no('history')) ?> - History</p>
  <h2>Version history</h2>
  <p class="desc">Every publish is kept. Restore an older version into the editor, review the differences, and publish it again - or roll one straight back onto the website.</p>
  <?php /* Restore writes the draft, so it carries the same keys every other
           draft form does - above all the fingerprint, so a colleague's newer
           draft is refused as `draftmoved` instead of being overwritten.
           admin.js keeps it current after this page's own autosaves. */ ?>
  <form id="restoreform" method="post" action="<?= e($this->url(Routes::RESTORE)) ?>"><?= $this->partial('admin/partials/form-keys', ['revision' => $revision, 'baseVersion' => $baseVersion]) ?></form>
  <?php if ($page->canPublish) { ?>
  <?php /* The ASKING form. It carries no `confirm`, so the post it makes
           writes nothing at all: the server answers with the question card
           below, and the confirming form is inside that card. */ ?>
  <form id="rollbackaskform" method="post" action="<?= e($this->url(Routes::ROLLBACK)) ?>"><?= $csrf->field() ?></form>
  <?php } ?>

  <?php if ($ask !== null) { ?>
  <?php /* THE QUESTION. Server-rendered, on the page, with its own Cancel -
           never a window.confirm(), which is gone the moment JavaScript is
           off and could not say any of this anyway. */ ?>
  <div class="card rollback<?= $ask->allowed() ? '' : ' blocked' ?>" id="rollback-ask">
    <p class="eyebrow">Roll back</p>
    <h3><?= e($ask->heading()) ?></h3>
    <?php if ($ask->allowed()) { ?>
    <p class="desc"><?= e($ask->question) ?></p>
    <?php if ($ask->movedNote() !== '') { ?>
    <div class="notice warn"><?= e($ask->movedNote()) ?></div>
    <?php } ?>
    <?php if ($ask->holdNote() !== '') { ?>
    <div class="notice warn"><?= e($ask->holdNote()) ?></div>
    <?php } ?>
    <form method="post" action="<?= e($this->url(Routes::ROLLBACK)) ?>">
      <?= $csrf->field() ?>
      <input type="hidden" name="version" value="<?= e($ask->version) ?>">
      <input type="hidden" name="confirm" value="1">
      <?php /* The live version this question was drawn against. A colleague's
               publish while the owner reads the card makes it disagree, and
               the confirm is refused instead of undoing that publish too. */ ?>
      <?php if ($ask->liveVersion > 0) { ?>
      <input type="hidden" name="expectedLive" value="<?= e($ask->liveVersion) ?>">
      <?php } ?>
      <div class="review-actions">
        <button class="btn book" type="submit" data-once data-busy-text="Rolling back&#8230;">Roll back to version <?= e($ask->version) ?></button>
        <a class="btn outline" href="<?= e($this->url(Routes::RATES)) ?>#sec-history">Cancel</a>
      </div>
    </form>
    <?php } else { ?>
    <p class="desc"><?= e($ask->refusal()) ?></p>
    <?php if ($ask->problems !== []) { ?>
    <div class="notice err">
      <strong>What it is missing</strong>
      <ul>
        <?php foreach ($ask->problems as $problem) { ?>
        <li><?= e($problem) ?></li>
        <?php } ?>
      </ul>
    </div>
    <?php } ?>
    <div class="review-actions">
      <?php if (!$ask->unknown) { ?>
      <button class="btn primary" type="submit" form="restoreform" name="version" value="<?= e($ask->version) ?>"<?php if ($restoreAsk($ask->version) !== '') { ?> data-confirm="<?= e($restoreAsk($ask->version)) ?>"<?php } ?>>Restore into editor</button>
      <?php } ?>
      <a class="btn outline" href="<?= e($this->url(Routes::RATES)) ?>#sec-history">Cancel</a>
    </div>
    <?php } ?>
  </div>
  <?php } ?>

  <?php if ($page->versions !== []) { ?>
  <ul class="hist versions">
    <?php foreach ($page->versions as $version) { ?>
    <li<?= $version->isLive ? ' class="is-live"' : '' ?>>
      <strong class="ver">v<?= e($version->version) ?></strong>
      <span class="meta"><?= e($version->when()) ?></span>
      <span class="meta"><?= e($version->summary) ?> &#183; <?= e($version->publishedBy) ?></span>
      <?php if ($version->isLive) { ?>
      <span class="tag">live</span>
      <?php } else { ?>
      <button class="btn small outline" type="submit" form="restoreform" name="version" value="<?= e($version->version) ?>"
              data-once data-busy-text="Restoring&#8230;"<?php if ($restoreAsk($version->version) !== '') { ?> data-confirm="<?= e($restoreAsk($version->version)) ?>"<?php } ?>>Restore into editor</button>
      <?php if ($page->canPublish) { ?>
      <?php /* Deliberately NOT `data-once`: this press only asks, and a
               button that disabled itself on the way to a question could not
               be pressed again by an owner who came back from it. */ ?>
      <button class="btn small danger" type="submit" form="rollbackaskform" name="version" value="<?= e($version->version) ?>"
              aria-label="<?= e('Roll back to version ' . $version->version) ?>">Roll back</button>
      <?php } ?>
      <?php } ?>
    </li>
    <?php } ?>
  </ul>
  <?php } else { ?>
  <p class="hint">No earlier versions yet.</p>
  <?php } ?>
</section>
