<?php

declare(strict_types=1);

/**
 * "New shipment". Port of NewShipmentForm.tsx and app/admin/shipments/new/page.tsx.
 *
 * Raised from a lead in the inbox, or from nothing. When it comes from a lead
 * the customer's name, number, destination and message are already in the
 * boxes: the office should not retype what the customer already told us, and
 * a retyped phone number is a wrong phone number.
 *
 * Every box is re-filled from the posted values after a refusal, so a
 * rejected form never costs the office its typing - the whole reason the
 * server hands `values` back rather than redirecting to an empty form.
 *
 * The maxlengths are the database's, not the React form's, where the two
 * disagree: `tracking_no` is VARCHAR(64) and `carrier` VARCHAR(80).
 *
 * @var \Speedat\Core\View                  $this
 * @var \Speedat\Core\Csrf                  $csrf
 * @var \Speedat\View\Admin\NewShipmentPage $page
 * @var \Speedat\View\Admin\Shell           $shell
 */

use Speedat\View\Admin\Routes;

$this->layout('admin/layouts/shell', ['title' => 'New shipment', 'shell' => $shell]);
?>
<section class="admin">
  <div class="topbar">
    <div>
      <p class="eyebrow"><?= e($page->eyebrow()) ?></p>
      <h1>New shipment</h1>
    </div>
    <div class="topbar-right">
      <?php if ($page->leadId > 0) { ?>
      <span class="meta">From lead #<?= e($page->leadId) ?></span>
      <?php } ?>
      <a class="btn outline small" href="<?= e($this->url(Routes::SHIPMENTS)) ?>">&#8592; All shipments</a>
    </div>
  </div>

  <form class="panel ship-new" method="post" action="<?= e($this->url(Routes::SHIPMENT_CREATE)) ?>">
    <?= $csrf->field() ?>
    <h2 class="step">Shipment details</h2>
    <?php if ($page->error !== '') { ?>
    <div class="notice err" role="alert"><?= e($page->error) ?></div>
    <?php } ?>
    <input type="hidden" name="leadId" value="<?= e($page->leadId > 0 ? (string) $page->leadId : '') ?>">
    <input type="hidden" name="quoteId" value="<?= e($page->quoteId) ?>">
    <?php if ($page->quoteId !== '') { ?>
    <p class="meta ship-meta">From quote <span class="qid"><?= e($page->quoteId) ?></span></p>
    <?php } ?>
    <div class="row">
      <label class="field">
        <span>Customer name</span>
        <input type="text" name="customerName" value="<?= e($page->value('customerName')) ?>" maxlength="120">
      </label>
      <label class="field">
        <span>Customer phone / WhatsApp</span>
        <input type="tel" name="customerPhone" value="<?= e($page->value('customerPhone')) ?>" maxlength="40">
      </label>
    </div>
    <div class="row">
      <label class="field">
        <span>Destination</span>
        <select name="destId" required>
          <option value="" disabled<?= $page->value('destId') === '' ? ' selected' : '' ?>>Choose</option>
          <?php foreach ($page->destinationOptions() as $value => $label) { ?>
          <option value="<?= e($value) ?>"<?= $value === $page->value('destId') ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php } ?>
        </select>
      </label>
      <label class="field">
        <span>Service</span>
        <select name="serviceId" required>
          <?php foreach ($page->serviceOptions() as $value => $label) { ?>
          <option value="<?= e($value) ?>"<?= $value === $page->value('serviceId') ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php } ?>
        </select>
      </label>
    </div>
    <label class="field">
      <span>Receiver name</span>
      <input type="text" name="receiverName" value="<?= e($page->value('receiverName')) ?>" maxlength="120">
    </label>
    <div class="row">
      <label class="field">
        <span>Carrier / airline</span>
        <input type="text" name="carrier" value="<?= e($page->value('carrier')) ?>" maxlength="80" placeholder="e.g. DHL, Emirates SkyCargo">
      </label>
      <label class="field">
        <span>Tracking number (if known)</span>
        <input class="track" type="text" name="trackingNo" value="<?= e($page->value('trackingNo')) ?>" maxlength="64">
      </label>
    </div>
    <label class="field">
      <span>Notes (internal)</span>
      <textarea class="long" name="notes" maxlength="4000"><?= e($page->value('notes')) ?></textarea>
    </label>
    <button class="btn primary big" type="submit" data-once data-busy-text="Creating&#8230;">Create shipment</button>
  </form>
</section>
