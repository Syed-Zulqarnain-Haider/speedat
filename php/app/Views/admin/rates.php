<?php

declare(strict_types=1);

/**
 * The rates editor. Port of AdminEditor.tsx with the browser's work moved to
 * the server: the draft lives in MySQL, the server owns the diff and the
 * validation, and every control here is a real submit to a named action.
 *
 * The sections are computed from Sections::ALL - the numbered eyebrows and the
 * sidebar's sub-list both read it, so adding or removing one changes every
 * place at once. "Import from Excel" was removed from the product on 2026-09-27
 * (no section, no "Sheets waiting" tile, no placeholder, no "coming soon"), and
 * "Reviews" was added on 2026-10-05; the list is the single source either way.
 *
 * THE FORM LAYOUT, because it is the one thing here that is not obvious.
 *
 * There is no longer one form carrying the whole document. There cannot be:
 * PHP stops reading a request body at `max_input_vars` - 1000 on this host,
 * not raisable from PHP, and not to be trusted higher anywhere - without
 * raising anything at all, so the eleven-country document with three services
 * posted 1009 variables and the editor simply could not save. What the owner
 * edits is one country at a time, so that is what a form is now:
 *
 *   form#editor      Settings and Website pages. Their fields sit in two
 *                    sections further down the page and are associated with
 *                    it by the `form` attribute - standard HTML, no script -
 *                    because HTML forms cannot nest and Bulk adjust and Test
 *                    a price sit between them.
 *   form#row-<id>    one per country: its name, whether it is shown, and its
 *                    own ladders. Printed by the rates table partial above
 *                    the table itself, because a <form> may not be a child of
 *                    <tbody>. ~90 variables, whatever the document grows to.
 *   form#tableform   Add country and Sort A-Z: the keys and a button name.
 *
 * Each of them posts to the same URL, is read by the same parser and is a
 * patch on the stored draft (`Http\Admin\DraftForm`), so a form that carries
 * one country changes that country and nothing else. With JavaScript off each
 * has its own Save button; with it, admin.js autosaves whichever one was
 * typed into.
 *
 * @var \Speedat\Core\View            $this
 * @var \Speedat\Core\Csrf            $csrf
 * @var \Speedat\View\Admin\RatesPage $page
 * @var \Speedat\View\Admin\Shell     $shell
 * @var int                           $newLeads
 * @var int                           $baseVersion   the published version this editor was OPENED at
 * @var string                        $draftRevision the fingerprint of the draft row it was drawn from
 */

use Speedat\View\Admin\Routes;
use Speedat\View\Admin\Sections;

$this->layout('admin/layouts/shell', [
    'title' => 'Rates admin',
    'shell' => $shell,
    'toast' => $page->toast,
]);

$newLeads = isset($newLeads) && is_int($newLeads) ? $newLeads : 0;

// The two values every form on this page hands back unchanged; see
// admin/partials/form-keys.
$keys = [
    'revision'    => isset($draftRevision) && is_string($draftRevision) ? $draftRevision : '',
    'baseVersion' => isset($baseVersion) && is_int($baseVersion) ? $baseVersion : 0,
];
?>
<section class="admin">
  <div class="topbar">
    <div>
      <p class="eyebrow"><?= e($page->eyebrow()) ?></p>
      <h1>Rates</h1>
    </div>
    <div class="topbar-right">
      <?php if ($page->pricesPill() === 'hold') { ?>
      <span class="pill hold">on hold</span>
      <?php } elseif ($page->pricesPill() === 'live') { ?>
      <span class="pill live">live</span>
      <?php } else { ?>
      <span class="pill sample">sample rates</span>
      <?php } ?>
      <?php if ($page->changeCount > 0) { ?>
      <span class="pill draft">draft edited</span>
      <?php } ?>
      <span class="meta<?= $page->stale() ? ' warn' : '' ?>"><?= e($page->liveMeta()) ?></span>
    </div>
    <?= $this->partial('admin/partials/hold', ['hold' => $page->hold]) ?>
  </div>

  <?php /* THE LIVE/DRAFT STATE IN ONE PLAIN SENTENCE — the loudest thing after
           the heading for a non-technical owner. Green = what customers see
           right now; orange = your unpublished edits, with the exact action
           that puts them live. Presentation only: composed from the same count
           the pills and the publish bar already read. */ ?>
  <p class="status-banner<?= $page->changeCount > 0 ? ' has-draft' : '' ?>">
    <span class="sb-live">
      <?php if ($page->hold->on) { ?>
      Prices are on hold - customers see your hold message, not prices.
      <?php } else { ?>
      Customers see <strong>version <?= e((string) $page->live->version) ?></strong>.
      <?php } ?>
    </span>
    <?php if ($page->changeCount > 0) { ?>
    <span class="sb-draft">You have <strong><?= e($page->changeSentence()) ?></strong> - press <strong>Review and publish</strong> to put <?= $page->changeCount === 1 ? 'it' : 'them' ?> live.</span>
    <?php } else { ?>
    <span class="sb-ok">The editor matches what is live - nothing to publish.</span>
    <?php } ?>
  </p>

  <div class="dash">
    <?php foreach ($page->tiles($newLeads) as $tile) { ?>
    <?php if ($tile['href'] !== '') { ?>
    <a class="tile<?= $tile['tone'] !== '' ? ' ' . e($tile['tone']) : '' ?>" href="<?= e($this->url($tile['href'])) ?>">
      <span class="eyebrow"><?= e($tile['eyebrow']) ?></span>
      <span class="val"><?= e($tile['value']) ?></span>
      <span class="note"><?= e($tile['note']) ?></span>
    </a>
    <?php } else { ?>
    <div class="tile<?= $tile['tone'] !== '' ? ' ' . e($tile['tone']) : '' ?>">
      <span class="eyebrow"><?= e($tile['eyebrow']) ?></span>
      <span class="val"><?= e($tile['value']) ?></span>
      <span class="note"><?= e($tile['note']) ?></span>
    </div>
    <?php } ?>
    <?php } ?>
  </div>

  <nav class="subnav sections" aria-label="Sections">
    <?php foreach (Sections::all() as $section) { ?>
    <a href="#<?= e($section['anchor']) ?>"><?= e($section['label']) ?></a>
    <?php } ?>
  </nav>

  <?php foreach ($page->notices() as $notice) { ?>
  <div class="notice <?= e($notice['kind']) ?>"><?= e($notice['text']) ?></div>
  <?php } ?>

  <?php /* WHOSE WORK IS ON THIS SCREEN. There is one draft row for the whole
           office, so the editor says who wrote it last and when, above the
           boxes rather than in a tooltip - the sentence is the domain's own
           (`Domain\SiteDoc\Draft::editedBy()`), so the page and the refusal
           cannot describe the same row differently. */ ?>
  <?php if ($page->draftEditedBy !== '') { ?>
  <p class="meta draft-who" data-draft-who>This draft was <?= e($page->draftEditedBy) ?>.</p>
  <?php } ?>

  <?php /* THE DRAFT MOVED UNDER THIS PAGE. Filled by the server after a
           refused submit and by admin.js after a refused autosave - one box,
           so both paths say the same thing in the same place: who has it now,
           and a way to go and look. */ ?>
  <div class="notice err conflict-box" data-conflict-box<?= $page->draftMoved ? '' : ' hidden' ?>>
    <?php if ($page->draftMoved) { ?>
    <strong>Nothing was saved.</strong>
    <span data-conflict-text>Somebody else changed this draft while the page was open<?= $page->draftEditedBy === '' ? '' : ' - it was ' . e($page->draftEditedBy) ?>.
    Reload to see their version, then make your change again.</span>
    <a class="btn small" href="<?= e($this->url(Routes::RATES)) ?>" data-conflict-reload>Reload</a>
    <?php } ?>
  </div>

  <?php /* WHAT THE LAST AUTOSAVE DID NOT STORE. A price box that arrives
           holding something that is not a number keeps the value already in
           the document rather than writing NaN over it, and the plain submit
           says so through `?kept=N`. The autosave answers JSON and had
           nowhere to say it, so with JavaScript on - the ordinary case - the
           line read "Draft saved" over prices that did not save. admin.js
           fills this from the same sentence (`Notice::keptText`) and hides it
           again on the next clean save of the same country. Empty and hidden
           without JavaScript, where the redirect above carries the words. */ ?>
  <div class="notice warn kept-box" data-kept-box hidden></div>

  <?php /* The find box posts as a GET on its own, so with JavaScript off the
           server filters the table. admin.js stops that submit and filters
           as the owner types. It is deliberately NOT part of any editing
           form: a search word is not part of the rate document. */ ?>
  <form id="findform" method="get" action="<?= e($this->url(Routes::RATES)) ?>"></form>

  <?= $this->partial('admin/partials/rates-table', ['page' => $page] + $keys) ?>

  <?php /* Bulk adjust needs the pricing model as well as the form state: "Per
           step" means nothing under per-kilogram pricing and is not offered
           there. */ ?>
  <?= $this->partial('admin/partials/bulk', ['bulk' => $page->bulk, 'grid' => $page->draft->isGrid()]) ?>
  <?= $this->partial('admin/partials/test-price', ['test' => $page->test]) ?>

  <?php /* Settings and Website pages post through this one; the countries
           each post through their own. It WRAPS its two sections rather than
           only claiming their fields with `form="editor"`, so that the Save
           button under them is inside the form it saves - Bulk adjust and
           Test a price have their own forms and are deliberately left
           outside it, because HTML forms cannot nest. `data-saved-at` is what
           admin.js reprints in the publish bar after an autosave. */ ?>
  <form id="editor" data-autosave method="post" action="<?= e($this->url(Routes::SAVE)) ?>" data-saved-at="<?= e($page->savedAt()) ?>">
    <?= $this->partial('admin/partials/form-keys', $keys) ?>
    <?= $this->partial('admin/partials/settings', ['page' => $page]) ?>
    <?= $this->partial('admin/partials/content', ['page' => $page]) ?>
    <?= $this->partial('admin/partials/promo', ['page' => $page]) ?>
    <?= $this->partial('admin/partials/reviews', ['page' => $page]) ?>
  </form>

  <?= $this->partial('admin/partials/history', ['page' => $page] + $keys) ?>

  <div id="reviewbox">
    <?php /* WHAT THE LAST POST DID, in its own tone (`Http\Admin\EditorPage::outcome`).
             It is printed WHATEVER ELSE is on the screen. It used to be the
             else-branch of the review card, so "Review and publish" - which
             redirects here carrying the count of price boxes that did not
             hold a number - showed the diff, showed the Publish button, and
             said nothing at all about the prices that had just not saved.
             The card describes the stored draft; this says what happened to
             the last thing typed at it, and those are two different
             questions. */ ?>
    <?php if ($page->publishedNotice !== '') { ?>
    <div class="notice <?= e($page->publishedNoticeKind) ?>"><?= e($page->publishedNotice) ?></div>
    <?php } ?>
    <?php if ($page->review !== null) { ?>
    <?= $this->partial('admin/partials/review', ['page' => $page, 'review' => $page->review] + $keys) ?>
    <?php } ?>
  </div>

  <?= $this->partial('admin/partials/pubbar', ['page' => $page]) ?>
</section>
