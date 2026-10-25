<?php

declare(strict_types=1);

/**
 * The sticky publish bar. Port of the `.pubbar` block in AdminEditor.tsx.
 *
 * "Review and publish" submits `form#editor` with its own `formaction`, so
 * Settings and Website pages are SAVED and then reviewed. The countries are
 * NOT in that form any more - each posts its own, because the whole document
 * in one request body crossed PHP's `max_input_vars` (1009 variables against
 * a limit of 1000) and the editor stopped saving altogether. A country is
 * stored by its own Save, or by the autosave, which admin.js flushes before
 * this submit. With the script off the line under the buttons says plainly
 * what to press first: a review that quietly dropped a typed price would be
 * the very thing this change exists to stop.
 *
 * Discard has its own form; both buttons are direct children of
 * `.pub-actions` because admin.css gives each `flex: 1` below 480px, and a
 * <form> wrapper would have taken that place and left the buttons unsized.
 *
 * BOTH BUTTONS ARE REPAINTED BY admin.js. The `disabled` printed here is read
 * from the STORED draft, and the autosave changes that on a page that never
 * navigates - which is why the bar used to read "1 unpublished change" beside
 * a dead "Review and publish". See paintActions() in public/assets/js/admin.js.
 *
 * @var \Speedat\Core\View            $this
 * @var \Speedat\Core\Csrf            $csrf
 * @var \Speedat\View\Admin\RatesPage $page
 */

use Speedat\View\Admin\Routes;

$has = $page->changeCount > 0;
?>
<form id="discardform" method="post" action="<?= e($this->url(Routes::DISCARD)) ?>"><?= $csrf->field() ?></form>
<?php /* `data-changes` and `data-read-only` are not decoration. admin.js has to
         repaint these two buttons after every autosave - the count beside them
         moves on a page that never navigates - and it can learn neither the
         starting count nor the viewer's role from the markup otherwise. */ ?>
<div class="pubbar" data-pubbar data-changes="<?= e((string) $page->changeCount) ?>"<?= $page->readOnly ? ' data-read-only' : '' ?>>
  <div class="inner">
    <div class="pub-state">
      <strong data-change-count><?= e($page->changeSentence()) ?></strong>
      <span class="meta"><?= e($page->audienceSentence()) ?><span data-save-state><?= e($page->saveState()) ?></span></span>
    </div>
    <div class="pub-actions">
      <?php /* "DISCARD ALL CHANGES", and the confirm says how many. This posts
               to Routes::DISCARD, which drops the WHOLE draft - every country,
               Settings and every Website page - and it was labelled "Discard"
               eight pixels from "Review and publish", in a bar fixed over the
               foot of a page that has just said "each country saves on its
               own". The natural reading beside a per-country editor was
               "discard what I just typed here", and there is no undo for the
               real thing: history holds published versions, so a draft that was
               never published is gone. */ ?>
      <button class="btn outline" type="submit" form="discardform" data-discard
              data-confirm="<?= e($page->discardConfirm()) ?>"<?= $has && !$page->readOnly ? '' : ' disabled' ?>>Discard all changes</button>
      <button class="btn book" type="submit" form="editor" data-publish formaction="<?= e($this->url(Routes::REVIEW)) ?>"<?= $has && !$page->readOnly ? '' : ' disabled' ?>>Review and publish</button>
      <?php /* Autosave replaces this; without JavaScript it stores Settings and
               Website pages, and the countries are stored by their own Save.
               The bar stays ONE ROW HIGH whatever is in it: it is fixed over
               the foot of the page, and the review card's Publish button sits
               in the last screenful under it. A second line here put that
               button behind the bar for anybody with the script off. What to
               press first is said by the table, next to the buttons that do
               it (`.save-note` in admin/partials/rates-table). */ ?>
      <noscript>
        <button class="btn primary" type="submit" form="editor"<?= $page->readOnly ? ' disabled' : '' ?>>Save settings and pages</button>
      </noscript>
    </div>
  </div>
</div>
