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
 * A SEARCH KEEPS ITS BOX (fixed 2026-10-25). The box used to sit inside the
 * "there are leads" branch, so a search that matched nothing replaced it with
 * "Nothing here." and the search could not be edited - only abandoned by
 * clicking a tab. The box is now drawn whenever there are leads OR a search,
 * and a search that finds nothing says so in its own words, with a link back
 * to the whole tab. A tab that is simply empty still has no box: there is
 * nothing on it to look for.
 *
 * EACH CARD POSTS WHERE IT WAS SAVED FROM - the tab, the search, the page - so
 * the redirect comes back to the same screen and the same card rather than
 * to New, with the card gone and no word said.
 *
 * @var \Speedat\Core\View            $this
 * @var \Speedat\Core\Csrf            $csrf
 * @var \Speedat\View\Admin\InboxPage $page
 * @var \Speedat\View\Admin\Shell     $shell
 */

use Speedat\View\Admin\LeadCard;
use Speedat\View\Admin\Routes;

$this->layout('admin/layouts/shell', ['title' => 'Inbox', 'shell' => $shell, 'toast' => $page->toast]);
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

  <?php if ($page->error !== '') { ?>
  <div class="notice err" role="alert"><?= e($page->error) ?></div>
  <?php } ?>

  <nav class="subnav" aria-label="Lead status">
    <?php foreach ($page->tabs() as $tab) { ?>
    <a href="<?= e($this->url($tab['href'])) ?>"<?= $tab['active'] ? ' aria-current="page"' : '' ?>>
      <?= e($tab['label']) ?> <span class="n"><?= e($tab['count']) ?></span>
    </a>
    <?php } ?>
  </nav>

  <?php if (!$page->isEmpty() || $page->isSearch()) { ?>
  <?php /* The search ALWAYS goes to the server (fixed 2026-10-25), which
           looks through every lead in the tab, not only the 200 on screen
           (LeadRepository). The input used to carry `data-list-filter`, and
           admin.js cancels the submit of any form whose input does - so with
           scripting on, which is every office laptop and phone, Enter only hid
           cards among those already loaded: a customer from three months ago
           could not be found, and a search that matched none of them left a
           blank list with no "No leads match" line, since only the server
           draws that. Same rule as the shipment desk's #shipfind. */ ?>
  <form id="leadfind" method="get" action="<?= e($this->url(Routes::INBOX)) ?>">
    <input type="hidden" name="status" value="<?= e($page->status) ?>">
    <div class="inline">
      <label class="field">
        <span>Find a lead</span>
        <input type="text" name="q" value="<?= e($page->filter) ?>" placeholder="Name, number or destination">
      </label>
      <button class="btn outline" type="submit">Find</button>
    </div>
  </form>
  <?php } ?>

  <?php if ($page->isEmpty()) { ?>
  <p class="hint empty"><?= e($page->emptyLine()) ?><?php if ($page->isSearch()) { ?> <a href="<?= e($this->url($page->clearHref())) ?>">Clear the search</a><?php } ?></p>
  <?php if ($page->newerHref() !== '') { ?>
  <p class="hint"><a href="<?= e($this->url($page->newerHref())) ?>">&#8592; Newer leads</a></p>
  <?php } ?>
  <?php } else { ?>
  <?php if ($page->rangeLine() !== '') { ?>
  <p class="hint"><?= e($page->rangeLine()) ?></p>
  <?php } ?>
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
        <input type="hidden" name="tab" value="<?= e($page->status) ?>">
        <input type="hidden" name="q" value="<?= e($page->filter) ?>">
        <input type="hidden" name="page" value="<?= e($page->pageNo()) ?>">
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
  <?php if ($page->newerHref() !== '' || $page->olderHref() !== '') { ?>
  <p class="hint">
    <?php if ($page->newerHref() !== '') { ?><a href="<?= e($this->url($page->newerHref())) ?>">&#8592; Newer leads</a><?php } ?>
    <?php if ($page->olderHref() !== '') { ?><a href="<?= e($this->url($page->olderHref())) ?>">Older leads &#8594;</a><?php } ?>
  </p>
  <?php } ?>
  <?php } ?>
</section>
