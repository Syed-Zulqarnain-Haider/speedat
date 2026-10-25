<?php

declare(strict_types=1);

/**
 * One shipment's desk. Port of ShipmentDesk.tsx.
 *
 * TWO DELIBERATE DIFFERENCES from the React desk, both in the same direction.
 *
 * 1. The details save on a button, not on blur. The React version posted a
 *    server action every time a box lost focus; here the whole panel is one
 *    form with one Save, so the office can correct three fields and send one
 *    write - and, more to the point, the page works with JavaScript off,
 *    which the blur handler never could. The hint under the panel says what
 *    actually happens rather than repeating the old promise.
 * 2. The customer's WhatsApp text is built on the SERVER (ShipmentPage), and
 *    the copy button reads the same string the wa.me link carries. A message
 *    the browser assembled could differ from the one the timeline records.
 *
 * @var \Speedat\Core\View               $this
 * @var \Speedat\Core\Csrf               $csrf
 * @var \Speedat\View\Admin\ShipmentPage $page
 * @var \Speedat\View\Admin\Shell        $shell
 */

use Speedat\View\Admin\Routes;

$this->layout('admin/layouts/shell', ['title' => 'Shipment ' . $page->id(), 'shell' => $shell]);

$waHref   = $page->waHref();
$current  = $page->currentStatus();
$message  = $page->customerMessage();
$timeline = $page->timeline();
?>
<section class="admin">
  <div class="topbar">
    <div>
      <p class="eyebrow"><?= e($page->eyebrow()) ?></p>
      <h1 class="mono-h1"><?= e($page->id()) ?></h1>
    </div>
    <div class="topbar-right">
      <?php /* The short line up here and the full one in the panel, as in the
               React desk: at 375px the topbar has room for "Express to Canada"
               and not for the created date and the quote id as well. */ ?>
      <span class="meta"><?= e($page->route()) ?></span>
      <a class="btn outline small" href="<?= e($this->url(Routes::SHIPMENTS)) ?>">&#8592; All shipments</a>
    </div>
  </div>

  <?php if ($page->notice !== '' && $page->noticeIsError()) { ?>
  <?php /* A refusal is an alert, not a status: it is the thing the office has
           to act on before pressing anything else (as rates.php's notices). */ ?>
  <div class="notice err" role="alert"><?= e($page->notice) ?></div>
  <?php } elseif ($page->notice !== '') { ?>
  <div class="notice ok" role="status"><?= e($page->notice) ?></div>
  <?php } ?>

  <div class="ship">
    <form class="panel ship-details" method="post" action="<?= e($this->url(Routes::SHIPMENT_UPDATE)) ?>">
      <?= $csrf->field() ?>
      <input type="hidden" name="id" value="<?= e($page->id()) ?>">
      <h2 class="step">Details</h2>
      <p class="meta ship-meta"><?= e($page->summary()) ?></p>
      <?php foreach ($page->detailFields() as $field) { ?>
      <?= $this->partial('admin/partials/field', ['field' => $field]) ?>
      <?php } ?>
      <div class="btn-row">
        <button class="btn primary" type="submit">Save details</button>
      </div>
      <p class="hint">Saved when you press Save details. Nothing here is sent to the customer.</p>
    </form>

    <div class="ship-side">
      <div class="panel">
        <h2 class="step">Update status</h2>
        <p class="ship-now">
          <span class="eyebrow">Now</span>
          <span class="pill s-<?= e($current) ?>"><?= e($page->currentLabel()) ?></span>
        </p>
        <?php /* A status change and its note are one event, so they are one
                 form and one POST. The note is the only free text the customer
                 will read, which is why it sits beside the status rather than
                 somewhere it could be forgotten. */ ?>
        <form method="post" action="<?= e($this->url(Routes::SHIPMENT_EVENT)) ?>">
          <?= $csrf->field() ?>
          <input type="hidden" name="id" value="<?= e($page->id()) ?>">
          <div class="inline">
            <label class="field">
              <span>Status</span>
              <select name="status">
                <?php /* Only the moves the server will accept from here. */ ?>
                <?php foreach ($page->statusOptions() as $value => $label) { ?>
                <option value="<?= e($value) ?>"<?= $value === $current ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php } ?>
              </select>
            </label>
            <?php /* Twice the width of the status select, as in the React desk:
                     `.inline .field` gives every field `flex: 1` and this one
                     holds a sentence. admin.css has no class for it and
                     public/ is not this track's to change, so the one-property
                     style stays here - the CSP allows inline style and forbids
                     inline script, which is the risk that matters. */ ?>
            <label class="field" style="flex: 2">
              <span>Note (included in the WhatsApp update)</span>
              <input type="text" name="note" maxlength="1000" value="<?= e($page->note) ?>" placeholder="e.g. Handed to Emirates flight EK623">
            </label>
            <button class="btn primary" type="submit" data-once data-busy-text="Adding&#8230;">Add update</button>
          </div>
        </form>
        <div class="ship-actions">
          <?php if ($waHref !== '') { ?>
          <a class="btn wa small" href="<?= e($waHref) ?>" target="_blank" rel="noopener noreferrer">Send WhatsApp update</a>
          <?php } else { ?>
          <span class="hint">Add the customer&#8217;s phone to send WhatsApp updates.</span>
          <?php } ?>
          <?php /* Copy needs the text in the browser. A <pre hidden> rather
                   than a <script>: the note is the office's free text and the
                   one string a script element cannot carry is "</script". */ ?>
          <button class="btn small outline" type="button" data-copy-from="ship-msg" data-copy-ok="Message copied" hidden>Copy message</button>
        </div>
        <?php /* The copy buffer, and the no-JS way to read the same words: the
                 <pre> is the byte-exact source the button reads back with
                 textContent, and the paragraph beside it is the wrapped,
                 selectable version for a browser with no clipboard API.
                 `.lead-msg` is admin.css's own "a message, as typed" style -
                 pre-wrapped and capped at 80ch - which is exactly this. */ ?>
        <pre id="ship-msg" hidden><?= e($message) ?></pre>
        <details class="fold">
          <summary>The update the customer reads</summary>
          <p class="lead-msg"><?= e($message) ?></p>
        </details>
      </div>

      <div class="panel">
        <h2 class="step">Timeline</h2>
        <?php if ($timeline === []) { ?>
        <p class="hint">Nothing yet. The first update you add appears here.</p>
        <?php } else { ?>
        <ul class="hist timeline">
          <?php foreach ($timeline as $event) { ?>
          <li class="s-<?= e($event['status']) ?>">
            <strong><?= e($event['label']) ?></strong>
            <span class="meta"><?= e($event['at']) ?></span>
            <?php if ($event['note'] !== '') { ?>
            <span class="tl-note"><?= e($event['note']) ?></span>
            <?php } ?>
            <span class="hint"><?= e($event['by']) ?></span>
          </li>
          <?php } ?>
        </ul>
        <?php } ?>
      </div>
    </div>
  </div>
</section>
