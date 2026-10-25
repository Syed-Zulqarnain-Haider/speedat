<?php

declare(strict_types=1);

/**
 * The rates table: one row per country, the per-kilogram boxes folded away
 * under each one. Port of RatesTable.tsx.
 *
 * ONE FORM PER COUNTRY. The editor used to be a single form carrying the
 * whole document, and that form died of its own size: PHP stops reading a
 * request body at `max_input_vars` - 1000 on this host, and not raisable from
 * PHP - without raising anything, so `$_POST` simply ends early. Eleven
 * countries priced per kilogram for THREE services posted 1009 variables, and
 * the editor could not save at all. Each row now posts ITSELF: its name,
 * whether it is shown, and its own ladders. That is about ninety variables
 * whatever the document grows to, because a country is the unit the owner
 * actually edits - he keys one carrier sheet for one country at a time.
 *
 * The inputs have not moved. A <form> may not be a child of <tbody>, so the
 * row forms are printed above the table and every input in the row carries
 * `form="row-<id>"` - plain HTML, no script involved, and `new FormData(form)`
 * collects them exactly as it collected the old one. With JavaScript off each
 * row has its own "Save" button; with it, admin.js autosaves the row that was
 * typed in.
 *
 * Add country, Sort A-Z, Remove, Undo and Quick fill are still real submits.
 * The two that belong to a row (Remove, Undo, Quick fill) submit THAT ROW's
 * form, so a half-typed price in the row survives the action that follows it;
 * the two that belong to the table submit `#tableform`, which carries the
 * keys and nothing else.
 *
 * At 1366px the table is wider than the page. `.tablewrap` scrolls it, the
 * first and last columns are pinned by admin.css, and the actions column is
 * the pinned one on the right, so Undo and Remove never leave the screen -
 * which is what the Next.js version got wrong.
 *
 * @var \Speedat\Core\View            $this
 * @var \Speedat\Core\Csrf            $csrf
 * @var \Speedat\View\Admin\RatesPage $page
 * @var string                        $revision
 * @var int                           $baseVersion
 */

use Speedat\View\Admin\Routes;
use Speedat\View\Admin\Sections;

$grid     = $page->draft->isGrid();
$services = $page->draft->services();
$columns  = $page->columnCount();

// Hoisted: the copy control offers this list once per service per country, and
// rebuilding it inside the loop would walk every row 2 x N times.
$countries = $page->countries();
$weights   = $page->weights();

$keys = ['revision' => $revision, 'baseVersion' => $baseVersion];
?>
<section class="block" id="sec-rates">
  <p class="eyebrow"><?= e(Sections::no('rates')) ?> - Rates</p>
  <h2>Prices by destination</h2>
  <p class="desc"><?= e($page->tableLede()) ?></p>

  <?php /* THE FORMS THEMSELVES, one per country plus one for the table's own
           buttons. They are here rather than around the rows because HTML
           forbids a <form> inside <tbody>; each carries only the CSRF token,
           the draft fingerprint and the version the editor was opened at, and
           collects its country's boxes through the `form` attribute. */ ?>
  <form id="tableform" method="post" action="<?= e($this->url(Routes::SAVE)) ?>"><?= $this->partial('admin/partials/form-keys', $keys) ?></form>
  <?php foreach ($page->rows as $row) { ?>
  <?php /* `data-undo-action` is on the form because admin.js has to be able to
           ADD the Undo button after an autosave - a row grows one the moment
           it differs from the live version, which now happens while the page
           is open - and the server's URL is the one part of that button a
           browser cannot invent. */ ?>
  <form id="<?= e($row->formId) ?>" class="rowform" method="post" action="<?= e($this->url(Routes::SAVE)) ?>" data-autosave data-row-form="<?= e($row->id) ?>" data-undo-action="<?= e($this->url(Routes::UNDO_ROW)) ?>"><?= $this->partial('admin/partials/form-keys', $keys) ?></form>
  <?php } ?>

  <div class="toolbar">
    <input type="text" name="q" form="findform" value="<?= e($page->filter) ?>" placeholder="Find a destination"
           aria-label="Find a destination" data-filter-table="rates-table">
    <button class="btn small outline" type="submit" form="tableform" formaction="<?= e($this->url(Routes::SORT_DESTINATIONS)) ?>" name="sort" value="az"<?= $page->readOnly ? ' disabled' : '' ?>>Sort A&#8211;Z</button>
    <button class="btn small primary" type="submit" form="tableform" formaction="<?= e($this->url(Routes::ADD_DESTINATION)) ?>" name="add" value="1"<?= $page->readOnly ? ' disabled' : '' ?>>Add country</button>
    <a class="btn small" href="<?= e($this->url(Routes::EXPORT_CSV)) ?>" download>Download as CSV</a>
    <?php /* The copy button needs the text in the browser; the server puts it below.
             Without JavaScript it stays hidden and the download link is the way out. */ ?>
    <button class="btn small" type="button" data-copy-from="rates-csv" data-copy-ok="CSV copied to the clipboard" hidden>Copy as CSV</button>
    <?php /* One weight down every country - how a rate change usually arrives
             ("10 kg is going up"). It edits the same boxes the folds do, so it
             needs the boxes in the page: JavaScript only, hidden until admin.js
             shows it, and the CSV download is the way to see the same thing
             without it. */ ?>
    <?php if ($grid) { ?>
    <button class="btn small outline" type="button" data-colview-open aria-expanded="false" aria-controls="colview" hidden>Compare one weight</button>
    <?php } ?>
  </div>

  <?php /* The CSV as text for the copy button. A <pre hidden> and not a <script>
           block: a country name is the owner's free text, and the one string an
           HTML script element cannot carry is "</script". Escaped here and read
           back with textContent, which decodes it, so what lands on the
           clipboard is byte for byte what the download gives. */ ?>
  <pre id="rates-csv" hidden><?= e($page->csv) ?></pre>

  <p class="hint save-note" data-save-note>Each country saves on its own - press <strong>Save</strong> on the row you changed. Settings and Website pages have their own Save.</p>

  <div class="tablewrap">
    <table class="<?= $grid ? 'rates rates-grid' : 'rates' ?>" id="rates-table">
      <thead>
        <tr>
          <th></th>
          <th></th>
          <?php foreach ($services as $service) { ?>
          <th class="grp <?= e($service->id) ?>" colspan="<?= $grid ? 1 : 3 ?>"><?= e($service->name) ?></th>
          <?php } ?>
          <?php if ($grid) { ?>
          <th></th>
          <?php } ?>
          <th></th>
        </tr>
        <tr>
          <th>Destination</th>
          <th>On site</th>
          <?php /* No Docs column: the flat document rate left the product on
                   2026-09-27 (owner), so a service has weight prices and days. */ ?>
          <?php foreach ($services as $service) { ?>
          <?php if (!$grid) { ?>
          <th><?= e($page->firstHeading()) ?></th>
          <th><?= e($page->stepHeading()) ?></th>
          <?php } ?>
          <th>Days</th>
          <?php } ?>
          <?php if ($grid) { ?>
          <?php /* Two lines: on one it runs under the pinned actions column at 1366px. */ ?>
          <th class="grid-cell"><?= e($page->gridHeadingTop()) ?> <br><?= e($page->gridHeadingBottom()) ?></th>
          <?php } ?>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($page->rows as $row) { ?>
        <?php $f = ' form="' . e($row->formId) . '"'; ?>
        <?php /* `data-name` carries the SEARCH KEY, not the display name: the
                 name is in the box beside it, and what the filter compares is
                 the accent-free, lower-case form. Both sides now fold the same
                 way - the server with Format::nameKey, admin.js with its own
                 nameKey over an already-folded string - so "turkiye" finds
                 "Türkiye" whichever of the two is doing the filtering. */ ?>
        <tr class="<?= $row->active ? '' : 'inactive' ?><?= $row->changed ? ' changed' : '' ?>" data-name="<?= e($row->searchKey) ?>" data-row="<?= e($row->id) ?>" data-form="<?= e($row->formId) ?>"<?php if ($row->liveJson !== '') { ?> data-live="<?= e($row->liveJson) ?>"<?php } ?><?= $row->hidden ? ' hidden' : '' ?>>
          <td>
            <input class="name" type="text" name="<?= e($row->nameField) ?>" value="<?= e($row->name) ?>" maxlength="120"
                   aria-label="Destination name"<?= $f ?><?= $page->readOnly ? ' disabled' : '' ?>>
            <input type="hidden" name="<?= e($row->positionField) ?>" value="<?= e($row->position) ?>"<?= $f ?>>
          </td>
          <td class="ctr">
            <input type="hidden" name="<?= e($row->activeField) ?>" value="0"<?= $f ?>>
            <?php /* THE LABEL IS THE TAP TARGET, not the 18px box inside it.
                     This is the admin's one bare checkbox - the row already
                     names the country, so there is deliberately no visible
                     label - and a min-height on a checkbox stretches the BOX
                     into a rectangle rather than widening the hit area. The
                     wrapper carries no text, no name and no value, so it adds
                     nothing to the posted form; it is purely something a thumb
                     can land on. */ ?>
            <label class="chk-bare">
              <input type="checkbox" name="<?= e($row->activeField) ?>" value="1"<?= $row->active ? ' checked' : '' ?>
                     aria-label="<?= e('Show ' . $row->name . ' on website') ?>"<?= $f ?><?= $page->readOnly ? ' disabled' : '' ?>>
            </label>
          </td>
          <?php foreach ($row->cells as $cell) { ?>
          <?php if (!$grid) { ?>
          <td>
            <input class="num" type="number" inputmode="decimal" min="0" step="any" name="<?= e($cell->firstField) ?>"
                   value="<?= e($cell->firstValue) ?>" placeholder="-"
                   aria-label="<?= e($row->name . ' ' . $cell->serviceName . ' first slab') ?>"<?= $f ?><?= $page->readOnly ? ' disabled' : '' ?>>
          </td>
          <td>
            <input class="num" type="number" inputmode="decimal" min="0" step="any" name="<?= e($cell->addlField) ?>"
                   value="<?= e($cell->addlValue) ?>" placeholder="-"
                   aria-label="<?= e($row->name . ' ' . $cell->serviceName . ' per step') ?>"<?= $f ?><?= $page->readOnly ? ' disabled' : '' ?>>
          </td>
          <?php } ?>
          <td>
            <input class="days" type="text" name="<?= e($cell->daysField) ?>" value="<?= e($cell->daysValue) ?>"
                   placeholder="3&#8211;5" maxlength="20"
                   aria-label="<?= e($row->name . ' ' . $cell->serviceName . ' days') ?>"<?= $f ?><?= $page->readOnly ? ' disabled' : '' ?>>
          </td>
          <?php } ?>
          <?php if ($grid) { ?>
          <td class="grid-cell">
            <a class="btn small outline" href="<?= e($this->url(Routes::openFold($row->id))) ?>"
               data-fold-toggle="<?= e($row->foldId) ?>" aria-expanded="<?= $row->open ? 'true' : 'false' ?>"><?= $row->open ? 'Hide prices &#9652;' : 'Prices &#9662;' ?></a>
            <?php /* One service per line: side by side they made this the widest column on the page. */ ?>
            <div class="hint grid-summary">
              <?php foreach ($row->cells as $cell) { ?>
              <span><?= e($cell->serviceName) ?>: <?= e($cell->gridSummary) ?></span>
              <?php } ?>
            </div>
          </td>
          <?php } ?>
          <td class="ctr row-actions">
            <?php /* THE ROW'S OWN SAVE. It is the whole no-JavaScript path for
                     a price change now that a country posts on its own, so it
                     is a plain submit with no name. admin.js used to HIDE it,
                     which left this pinned column holding nothing but the
                     orange Remove button on a clean row - the one control
                     permanently on screen as the table is scrolled sideways
                     was delete. It now stays, and admin.js writes this row's
                     save state into it ("Saving...", "Retry save", "Saved
                     14:05") and saves the form on a press instead of
                     navigating. */ ?>
            <button class="btn small primary" type="submit"<?= $f ?> data-save-submit
                    aria-label="<?= e('Save ' . $row->name) ?>"<?= $page->readOnly ? ' disabled' : '' ?>>Save</button>
            <?php if ($row->changed && !$page->readOnly) { ?>
            <button class="btn small outline" type="submit"<?= $f ?> formaction="<?= e($this->url(Routes::UNDO_ROW)) ?>"
                    name="undo" value="<?= e($row->id) ?>" data-undo-row
                    aria-label="<?= e('Undo changes to ' . $row->name) ?>">Undo</button>
            <?php } ?>
            <button class="btn small danger" type="submit"<?= $f ?> formaction="<?= e($this->url(Routes::REMOVE_DESTINATION)) ?>"
                    name="remove" value="<?= e($row->id) ?>" data-confirm="Confirm remove"
                    aria-label="<?= e('Remove ' . $row->name) ?>"<?= $page->readOnly ? ' disabled' : '' ?>>Remove</button>
          </td>
        </tr>
        <?php if ($grid) { ?>
        <tr class="grid-row" id="<?= e($row->foldId) ?>" data-open="<?= $row->open ? '1' : '0' ?>"<?= $row->open && !$row->hidden ? '' : ' hidden' ?>>
          <td colspan="<?= e($columns) ?>">
            <div class="grid-editor">
              <?php foreach ($row->cells as $cell) { ?>
              <?php /* One LADDER: one service's price for every whole kilogram.
                       `data-fill-scope` is what Quick fill has always scoped to;
                       the dest/service attributes are what the copy control and
                       the column view address a ladder by. */ ?>
              <div class="grid-service ladder" data-fill-scope data-ladder
                   data-dest="<?= e($row->id) ?>" data-service="<?= e($cell->serviceId) ?>"
                   data-dest-name="<?= e($row->name) ?>" data-service-name="<?= e($cell->serviceName) ?>">
                <div class="grid-head">
                  <strong class="tag grp <?= e($cell->serviceId) ?>"><?= e($cell->serviceName) ?></strong>
                  <span class="hint" data-ladder-count><?= e($cell->ladderCount()) ?></span>
                </div>

                <div class="ladder-tools">
                  <?php /* Fill from a base price and a per-kilogram step. The two
                           buttons are ordinary submits of THIS COUNTRY's form, so
                           this whole tool works with JavaScript off; what admin.js
                           adds is the sum PRINTED BEFORE it is applied, which is
                           the part that stops the owner filling 25 boxes to find
                           out. */ ?>
                  <div class="tool" data-fill-tool>
                    <span class="tool-name">Fill from a base price</span>
                    <label class="tool-field">
                      <span>1 kg price</span>
                      <input type="number" inputmode="decimal" min="0" step="any" class="num" name="<?= e($cell->fillStartField) ?>" data-fill-start
                             aria-label="<?= e($cell->serviceName . ' fill: the 1 kg price') ?>"<?= $f ?><?= $page->readOnly ? ' disabled' : '' ?>>
                    </label>
                    <label class="tool-field">
                      <span>+ each extra kg</span>
                      <input type="number" inputmode="decimal" min="0" step="any" class="num" name="<?= e($cell->fillStepField) ?>" data-fill-step
                             aria-label="<?= e($cell->serviceName . ' fill: the price per extra kilogram') ?>"<?= $f ?><?= $page->readOnly ? ' disabled' : '' ?>>
                    </label>
                    <button class="btn small outline" type="submit"<?= $f ?> formaction="<?= e($this->url(Routes::FILL)) ?>"
                            name="fill" value="<?= e('blanks:' . $row->id . ':' . $cell->serviceId) ?>" data-fill="blanks"<?= $page->readOnly ? ' disabled' : '' ?>>Fill blanks</button>
                    <button class="btn small outline" type="submit"<?= $f ?> formaction="<?= e($this->url(Routes::FILL)) ?>"
                            name="fill" value="<?= e('all:' . $row->id . ':' . $cell->serviceId) ?>" data-fill="all"<?= $page->readOnly ? ' disabled' : '' ?>>Fill all</button>
                    <p class="tool-preview" data-fill-preview aria-live="polite"></p>
                  </div>

                  <?php /* Copy this ladder onto another country, or onto all of
                           them. It writes into boxes that belong to those
                           countries' own forms, and admin.js saves each of them,
                           so the server validates every copied price like any
                           other edit - but it needs the boxes in the page, so it
                           is hidden until admin.js turns it on. The select
                           carries NO name: it must never become a posted
                           field. */ ?>
                  <?php if (!$page->readOnly) { ?>
                  <div class="tool" data-copy-tool hidden>
                    <span class="tool-name">Copy these prices to</span>
                    <label class="tool-field">
                      <span class="sr"><?= e($cell->serviceName . ' copy target') ?></span>
                      <select data-copy-target aria-label="<?= e('Copy ' . $row->name . ' ' . $cell->serviceName . ' prices to') ?>">
                        <option value="">Choose a country&#8230;</option>
                        <option value="*">Every other country</option>
                        <?php foreach ($countries as $country) { ?>
                        <?php if ($country['id'] !== $row->id) { ?>
                        <option value="<?= e($country['id']) ?>"><?= e($country['name']) ?></option>
                        <?php } ?>
                        <?php } ?>
                      </select>
                    </label>
                    <?php /* `hidden` on the button itself, not only on the tool
                             around it: the admin templates are held to "every
                             button that only works with JavaScript starts
                             hidden", one button at a time. */ ?>
                    <button class="btn small outline" type="button" data-copy-go hidden>Copy</button>
                    <p class="tool-preview" data-copy-preview aria-live="polite"></p>
                  </div>
                  <?php } ?>
                </div>

                <?php /* The running check: what the customer pays for the box
                         being typed, tax and standing charges included. admin.js
                         fills it from the Settings fields on this same page, so
                         it can never describe settings the owner has since
                         changed. Empty, and invisible, without JavaScript. */ ?>
                <p class="ladder-running" data-ladder-running aria-live="polite"></p>

                <div class="grid-boxes">
                  <?php foreach ($cell->boxes as $box) { ?>
                  <?php
                    $flags = ($box['low'] ? ' is-low' : '') . ($box['gap'] ? ' is-gap' : '') . ($box['edited'] ? ' is-edited' : '');
                    $bad   = $box['low'] || $box['gap'];
                  ?>
                  <label class="grid-box<?= $flags ?>" data-kg="<?= e($box['kg']) ?>">
                    <span class="kg"><?= e($box['kg']) ?> kg</span>
                    <input type="number" inputmode="decimal" min="0" step="any" data-kg-box data-kg="<?= e($box['kg']) ?>"
                           name="<?= e($box['name']) ?>" value="<?= e($box['value']) ?>" placeholder="-"
                           aria-label="<?= e($box['label']) ?>"<?= $bad ? ' aria-invalid="true" aria-describedby="' . e($cell->ladderId) . '"' : '' ?><?= $f ?><?= $page->readOnly ? ' disabled' : '' ?>>
                  </label>
                  <?php } ?>
                </div>

                <?php /* What is wrong with this ladder, in place and in words.
                         Never an alert: the owner is typing, and a dialog in the
                         middle of keying twenty-five numbers loses his place. */ ?>
                <p class="ladder-note <?= e($cell->ladderTone()) ?>" id="<?= e($cell->ladderId) ?>" data-ladder-note<?= $cell->ladderNote === '' ? ' hidden' : '' ?>><?= e($cell->ladderNote) ?></p>
              </div>
              <?php } ?>
            </div>

            <?php /* The save for the twenty-five numbers that were just keyed,
                     within reach of the last box rather than up in the table.
                     Same form, same fields, same URL as the row's own Save -
                     and, with the script running, the only thing on screen
                     inside a fold that says the work is being stored, so it
                     carries the whole sentence rather than the short one the
                     pinned column has room for. */ ?>
            <div class="btn-row fold-save">
              <button class="btn primary" type="submit"<?= $f ?> data-save-submit
                      aria-label="<?= e('Save ' . $row->name) ?>"<?= $page->readOnly ? ' disabled' : '' ?>>Save this country</button>
              <span class="hint"><?= e($row->name === '' ? 'This country' : $row->name) ?> is saved on its own; the other countries are not touched.</span>
            </div>
          </td>
        </tr>
        <?php } ?>
        <?php } ?>
      </tbody>
    </table>
  </div>

  <?php /* ONE WEIGHT, EVERY COUNTRY - the shape a rate change actually arrives
           in ("10 kg is going up on the Gulf routes"). Reading it out of the
           folds means opening one country at a time and remembering.

           The boxes in here are PROXIES: they carry no `name` and belong to no
           form, so nothing extra is ever posted. Each one is tied to the real
           box in the fold and writes through to it, which keeps a single
           source of truth - and, because the real box belongs to its own
           country's form, editing several countries here saves several small
           forms rather than one big one.

           JavaScript only. It edits boxes that live elsewhere on the page, so
           there is nothing honest for it to do without a script; it stays
           hidden, and "Download as CSV" gives the same comparison on paper. */ ?>
  <?php if ($grid && $weights !== []) { ?>
  <section class="colview" id="colview" data-colview hidden aria-label="One weight across every country">
    <div class="colview-head">
      <label class="tool-field">
        <span>Weight</span>
        <select data-colview-kg aria-label="Which kilogram to compare">
          <?php foreach ($weights as $kg) { ?>
          <option value="<?= e($kg) ?>"<?= $kg === $page->defaultWeight() ? ' selected' : '' ?>><?= e($kg) ?> kg</option>
          <?php } ?>
        </select>
      </label>
      <label class="tool-field">
        <span>Service</span>
        <select data-colview-service aria-label="Which service to compare">
          <?php foreach ($services as $service) { ?>
          <option value="<?= e($service->id) ?>"><?= e($service->name) ?></option>
          <?php } ?>
        </select>
      </label>
      <button class="btn small" type="button" data-colview-close hidden>Close</button>
    </div>
    <div class="colview-body" data-colview-body></div>
  </section>
  <?php } ?>

  <p class="hint table-note"><?= e($page->tableNote()) ?></p>

  <?php if ($grid) { ?>
  <?php /* The keyboard the owner's hands will learn. Printed rather than
           hidden in a tooltip: it is the difference between twenty-five pointer
           trips and twenty-five keystrokes, and nobody discovers it by
           hovering. Every one of these is an admin.js enhancement; with
           JavaScript off Tab still walks the boxes in kilogram order. */ ?>
  <p class="hint keys-note" data-keys-note hidden>
    In the price boxes: <kbd>Enter</kbd> or <kbd>&#8595;</kbd> moves to the next kilogram,
    <kbd>Shift</kbd>+<kbd>Enter</kbd> or <kbd>&#8593;</kbd> back one, <kbd>Tab</kbd> across,
    <kbd>Esc</kbd> puts the box back the way you found it.
    Copy a column of numbers off the carrier's rates and paste it into any box to fill from there down.
  </p>
  <?php } ?>
</section>
