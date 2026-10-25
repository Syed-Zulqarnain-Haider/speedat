<?php

declare(strict_types=1);

/**
 * The inbox. Port of app/admin/inbox/page.tsx and InboxList.tsx.
 *
 * Each card's actions are their own <form>, so status and notes are saved by a
 * real submit and the whole page works with JavaScript off. The form IS the
 * `.lead-actions` element rather than a wrapper around it: admin.css lays that
 * element out as a two-column grid, and an extra div between it and its
 * children would have collapsed the columns.
 *
 * @var \Speedat\Core\View            $this
 * @var \Speedat\Core\Csrf            $csrf
 * @var \Speedat\View\Admin\InboxPage $page
 * @var \Speedat\View\Admin\Shell     $shell
 */

use Speedat\View\Admin\LeadCard;
use Speedat\View\Admin\Routes;

$this->layout('admin/layouts/shell', ['title' => 'Inbox', 'shell' => $shell]);
?>
<section class="admin">
  <div class="topbar">
    <div>
      <p class="eyebrow"><?= e($page->eyebrow()) ?></p>
      <h1>Inbox</h1>
    </div>
    <div class="topbar-right">
      <?php if ($page->newCount() > 0) { ?>
      <span class="pill draft"><?= e($page->newCount()) ?> new</span>
      <?php } else { ?>
      <span class="pill live">nothing new</span>
      <?php } ?>
      <span class="meta">Booked quotes and contact messages. Work them left to right: new &#8594; contacted &#8594; booked.</span>
    </div>
  </div>

  <?php if (!$page->alertsConfigured) { ?>
  <div class="notice warn">
    Email alerts for new messages are not configured on this deployment. Messages still land here; check the inbox during the day.
  </div>
  <?php } ?>

  <nav class="subnav" aria-label="Lead status">
    <?php foreach ($page->tabs() as $tab) { ?>
    <a href="<?= e($this->url($tab['href'])) ?>"<?= $tab['active'] ? ' aria-current="page"' : '' ?>>
      <?= e($tab['label']) ?> <span class="n"><?= e($tab['count']) ?></span>
    </a>
    <?php } ?>
  </nav>

  <?php if ($page->isEmpty()) { ?>
  <p class="hint empty">Nothing here.</p>
  <?php } else { ?>
  <form id="leadfind" method="get" action="<?= e($this->url(Routes::INBOX)) ?>">
    <input type="hidden" name="status" value="<?= e($page->status) ?>">
    <label class="field">
      <span>Find a lead</span>
      <input type="text" name="q" value="<?= e($page->filter) ?>" data-list-filter="lead-list" placeholder="Name, number or destination">
    </label>
  </form>
  <ul class="hist leads" id="lead-list">
    <?php foreach ($page->leads as $lead) { ?>
    <li id="lead-<?= e($lead->id) ?>" class="lead<?= $lead->isNew() ? ' new' : '' ?>" data-search="<?= e($lead->searchKey()) ?>">
      <div class="lead-body">
        <div class="lead-head">
          <span class="tag kind-<?= e($lead->kind) ?>"><?= e($lead->kindLabel()) ?></span>
          <?php if ($lead->kind === 'quote') { ?>
          <span class="qid"><?= e($lead->quoteId) ?></span>
          <?php if ($lead->name !== '') { ?><span class="lead-name"><?= e($lead->name) ?></span><?php } ?>
          <?php } else { ?>
          <span class="lead-name"><?= e($lead->heading()) ?></span>
          <?php } ?>
          <?php if ($lead->destination !== '') { ?><span class="meta">&#8594; <?= e($lead->destination) ?></span><?php } ?>
          <?php if ($lead->weight !== '') { ?><span class="meta"><?= e($lead->weight) ?> kg</span><?php } ?>
          <span class="meta"><?= e($lead->when()) ?></span>
        </div>
        <?php if ($lead->phone !== '' || $lead->email !== '') { ?>
        <div class="lead-contact">
          <?php if ($lead->phone !== '') { ?><span class="meta"><?= e($lead->phone) ?></span><?php } ?>
          <?php if ($lead->email !== '') { ?><span class="meta"><?= e($lead->email) ?></span><?php } ?>
        </div>
        <?php } ?>
        <?php if ($lead->message !== '') { ?>
        <p class="lead-msg"><?= e($lead->message) ?></p>
        <?php } ?>
      </div>
      <form class="lead-actions" method="post" action="<?= e($this->url(Routes::INBOX_UPDATE)) ?>">
        <?= $csrf->field() ?>
        <input type="hidden" name="id" value="<?= e($lead->id) ?>">
        <label class="field">
          <span>Status</span>
          <select name="status">
            <?php foreach (LeadCard::statusOptions() as $value => $label) { ?>
            <option value="<?= e($value) ?>"<?= $value === $lead->status ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php } ?>
          </select>
        </label>
        <label class="field notes">
          <span>Notes</span>
          <input type="text" name="notes" value="<?= e($lead->notes) ?>" maxlength="4000"
                 placeholder="What was agreed, pickup time, receiver&#8230;">
        </label>
        <div class="lead-btns">
          <button class="btn primary small" type="submit">Save</button>
          <?php if ($lead->waHref() !== '') { ?>
          <a class="btn wa small" href="<?= e($lead->waHref()) ?>" target="_blank" rel="noopener noreferrer">Open in WhatsApp</a>
          <?php } ?>
          <a class="btn outline small" href="<?= e($this->url(Routes::shipmentFromLead($lead->id))) ?>">Create shipment</a>
        </div>
      </form>
      <?php if ($lead->updatedBy !== '') { ?>
      <span class="lead-foot">Last touched by <?= e($lead->updatedBy) ?>, <?= e($lead->touched()) ?></span>
      <?php } ?>
    </li>
    <?php } ?>
  </ul>
  <?php } ?>
</section>
