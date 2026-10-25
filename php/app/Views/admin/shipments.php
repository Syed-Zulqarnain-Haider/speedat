<?php

declare(strict_types=1);

/**
 * The shipments list. Port of app/admin/shipments/page.tsx.
 *
 * Read-only: every control on this page is a link, so there is nothing here
 * to post and nothing to lose. The names are the ones the shipment was BOOKED
 * under - the caller resolved them against the version that was live then, so
 * a country since removed from the rates still reads as a country here.
 *
 * At 1366px the seven columns fit; `.tablewrap` scrolls it on a phone and the
 * first column is pinned by admin.css, so the shipment number stays visible
 * while the rest slides.
 *
 * @var \Speedat\Core\View                $this
 * @var \Speedat\Core\Csrf                $csrf
 * @var \Speedat\View\Admin\ShipmentsPage $page
 * @var \Speedat\View\Admin\Shell         $shell
 */

use Speedat\View\Admin\Routes;

$this->layout('admin/layouts/shell', ['title' => 'Shipments', 'shell' => $shell]);

$rows = $page->table();
?>
<section class="admin">
  <div class="topbar">
    <div>
      <p class="eyebrow"><?= e($page->eyebrow()) ?></p>
      <h1>Shipments</h1>
    </div>
    <div class="topbar-right">
      <?php if ($page->attentionPill() !== '') { ?>
      <span class="pill hold"><?= e($page->attentionPill()) ?></span>
      <?php } ?>
      <span class="meta"><?= e($page->openCount()) ?> open</span>
      <a class="btn primary small" href="<?= e($this->url(Routes::SHIPMENT_NEW)) ?>">New shipment</a>
    </div>
  </div>

  <nav class="subnav" aria-label="Shipment status">
    <?php foreach ($page->tabs() as $tab) { ?>
    <a href="<?= e($this->url($tab['href'])) ?>"<?= $tab['active'] ? ' aria-current="page"' : '' ?>>
      <?= e($tab['label']) ?>
      <?php /* Only Open carries a number, as in the React nav: a zero beside
               every state reads as "nothing is happening" when plenty is. */ ?>
      <?php if ($tab['count'] > 0) { ?><span class="n"><?= e($tab['count']) ?></span><?php } ?>
    </a>
    <?php } ?>
  </nav>

  <?php /* A GET form of its own: the search word is not part of any shipment.
           It is ALWAYS here, and it always goes to the server, which searches
           every shipment before the page limit (ShipmentRepository::list()).
           It used to be drawn only when the page had rows, and admin.js
           filtered the rows on screen instead of submitting - so a search
           could only ever look through the 200 most recently touched, and
           one that matched none of them took the box away and suggested
           creating the shipment again. That is why the input carries no
           `data-list-filter`: admin.js cancels the submit of any form whose
           input does. */ ?>
  <form id="shipfind" method="get" action="<?= e($this->url(Routes::SHIPMENTS)) ?>">
    <input type="hidden" name="status" value="<?= e($page->status) ?>">
    <div class="inline">
      <label class="field">
        <span>Find a shipment</span>
        <input type="text" name="q" value="<?= e($page->filter) ?>"
               placeholder="Number, customer, destination or tracking">
      </label>
      <button class="btn outline" type="submit">Find</button>
    </div>
  </form>

  <?php if ($rows === [] && $page->filter !== '') { ?>
  <?php /* Not "No shipments here. Create one": the shipment probably exists,
           under another spelling or another tab, and a second booking of the
           same parcel is the outcome to avoid. */ ?>
  <p class="hint empty">No shipment matches &#8220;<?= e($page->filter) ?>&#8221;.
    <a href="<?= e($this->url(Routes::shipments($page->status))) ?>">Clear the search</a><?php if ($page->status !== 'all') { ?>
    or <a href="<?= e($this->url(Routes::shipments('all') . '&q=' . rawurlencode($page->filter))) ?>">look in All</a><?php } ?>.</p>
  <?php } elseif ($rows === []) { ?>
  <p class="hint empty">No shipments here. Create one from a lead in the Inbox, or with &#8220;New shipment&#8221;.</p>
  <?php } else { ?>
  <div class="tablewrap">
    <table class="ships">
      <thead>
        <tr>
          <th>Shipment</th>
          <th>Customer</th>
          <th>To</th>
          <th>Service</th>
          <th>Carrier / tracking</th>
          <th>Status</th>
          <th>Updated</th>
        </tr>
      </thead>
      <tbody id="ship-rows">
        <?php foreach ($rows as $row) { ?>
        <tr<?= $row['needsAttention'] !== '' ? ' class="changed"' : '' ?> data-search="<?= e($row['searchKey']) ?>">
          <td>
            <a href="<?= e($this->url($row['href'])) ?>"><span class="qid"><?= e($row['id']) ?></span></a>
            <?php if ($row['quoteId'] !== '') { ?>
            <div class="hint"><?= e($row['quoteId']) ?></div>
            <?php } ?>
          </td>
          <td>
            <?= e($row['customerName']) ?>
            <?php if ($row['customerPhone'] !== '') { ?>
            <div class="hint"><?= e($row['customerPhone']) ?></div>
            <?php } ?>
          </td>
          <td><?= e($row['destination']) ?></td>
          <td><?= e($row['service']) ?></td>
          <td>
            <?= e($row['carrier']) ?>
            <?php if ($row['trackingNo'] !== '') { ?>
            <div class="hint"><?= e($row['trackingNo']) ?></div>
            <?php } ?>
          </td>
          <td><span class="pill s-<?= e($row['status']) ?>"><?= e($row['statusLabel']) ?></span></td>
          <td class="meta"><?= e($row['updatedAt']) ?></td>
        </tr>
        <?php } ?>
      </tbody>
    </table>
  </div>
  <?php } ?>
</section>
