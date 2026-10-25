<?php

declare(strict_types=1);

/**
 * The analytics screen. Landings and how they arrived, countries, weights,
 * services, WhatsApp.
 *
 * NO CHART LIBRARY, AND NOT BECAUSE OF THE CONTENT SECURITY POLICY. The CSP
 * does forbid an external script, so a CDN was never an option - but the bars
 * here are CSS rather than SVG for a second reason that outlives the policy:
 * SVG text scales with the picture, and an SVG sized to fit a 375px phone
 * renders its labels at nine or ten pixels. The admin's floor is 14px
 * (php/docs/quality-bar.md, and tests/ViewAdmin/AdminStylesheetTest.php pins
 * it), so every word on this page is HTML at a size the stylesheet controls
 * and the only thing the bars carry is height.
 *
 * THE CHART IS NOT THE DATA. `<details>` under it opens a real table of the
 * same numbers - no JavaScript, native disclosure - because a bar is a
 * comparison and the owner sometimes wants the figure. The bar strip itself is
 * `role="img"` with the summary as its label, so a screen reader is told what
 * it is instead of reading out ninety empty divs.
 *
 * EVERY BLOCK CAN SAY "NOTHING YET" AND SAYS IT IN ITS OWN WORDS. A zero here
 * is either a measurement or an absence and the two must not look alike: an
 * empty chart drawn at full height, or a "0%" beside "pressed Book" in a week
 * with no quotes at all, is a lie with an axis on it. The page model decides
 * which case each block is in (`landings()['has']`, `quotes()['has']`,
 * `whatsApp()['has']`); this file only prints.
 *
 * The date form is a GET with no token, deliberately and like the two search
 * boxes: it narrows a view and writes nothing, and a CSRF token in a GET would
 * sit in the address bar and the browser history.
 *
 * @var \Speedat\Core\View                $this
 * @var \Speedat\Core\Csrf                $csrf
 * @var \Speedat\View\Admin\AnalyticsPage $page
 * @var \Speedat\View\Admin\Shell         $shell
 */

use Speedat\Domain\Pricing\Format;
use Speedat\View\Admin\Routes;

$this->layout('admin/layouts/shell', ['title' => 'Analytics', 'shell' => $shell]);

$landings = $page->landings();
$quotes   = $page->quotes();
$whatsApp = $page->whatsApp();
$bars     = $page->bars();
?>
<section class="admin an">
  <div class="topbar">
    <div>
      <p class="eyebrow"><?= e($page->eyebrow()) ?></p>
      <h1>Analytics</h1>
    </div>
    <div class="topbar-right">
      <span class="meta"><?= e($page->rangeLabel()) ?></span>
    </div>
  </div>

  <?php /* The presets are links and the boxes are a form: both work with
           scripting off, and a preset is bookmarkable as "the last N days"
           rather than as the dates that were current when it was copied. */ ?>
  <form class="an-range" method="get" action="<?= e($this->url(Routes::ANALYTICS)) ?>">
    <div class="an-presets">
      <?php foreach ($page->presets() as $preset) { ?>
      <a class="btn outline small<?= $preset['active'] ? ' on' : '' ?>" href="<?= e($this->url($preset['href'])) ?>"<?= $preset['active'] ? ' aria-current="true"' : '' ?>><?= e($preset['label']) ?></a>
      <?php } ?>
    </div>
    <label class="field">
      <span>From</span>
      <input type="date" name="from" value="<?= e($page->fromInput()) ?>">
    </label>
    <label class="field">
      <span>To</span>
      <input type="date" name="to" value="<?= e($page->toInput()) ?>">
    </label>
    <button class="btn primary small" type="submit">Show these dates</button>
  </form>

  <?php if ($page->rangeNotice !== '') { ?>
  <p class="notice warn"><?= e($page->rangeNotice) ?></p>
  <?php } ?>

  <?php /* ---------------------------------------------------- the four tiles */ ?>
  <div class="dash">
    <div class="tile<?= $landings['has'] ? ' tone-ok' : '' ?>">
      <p class="eyebrow">Landings</p>
      <p class="val"><?= $landings['has'] ? e(Format::fmtNum((float) $landings['value'])) : '-' ?></p>
      <p class="note"><?= e($page->landingsNote()) ?></p>
    </div>
    <div class="tile<?= $quotes['has'] ? ' tone-ok' : '' ?>">
      <p class="eyebrow">Prices shown</p>
      <p class="val"><?= $quotes['has'] ? e(Format::fmtNum((float) $quotes['value'])) : '-' ?></p>
      <p class="note">quotes the calculator gave</p>
    </div>
    <?php /* THE NUMBER THE OWNER ASKED FOR. Named for the button, not for a
             funnel: it counts taps on "Book on WhatsApp", which is a real
             thing that happened, where "conversion" would be a claim about
             money nothing here measures. */ ?>
    <div class="tile<?= $whatsApp['has'] && $whatsApp['value'] > 0 ? ' tone-hot' : '' ?>">
      <p class="eyebrow">Pressed Book on WhatsApp</p>
      <p class="val"><?= e($page->whatsAppShare()) ?></p>
      <p class="note"><?= e($page->whatsAppSentence()) ?></p>
    </div>
    <div class="tile">
      <p class="eyebrow">Busiest day</p>
      <p class="val"><?= $page->peakViews() > 0 ? e(Format::fmtNum((float) $page->peakViews())) : '-' ?></p>
      <p class="note"><?= $page->peakLabel() === '' ? 'no landings counted yet' : e($page->peakLabel()) ?></p>
    </div>
  </div>

  <?php /* ------------------------------------------------------- the landings */ ?>
  <h2 class="an-h">Landings, day by day</h2>
  <p class="hint"><?= e($page->countingNote()) ?></p>

  <?php if (!$landings['has']) { ?>
  <p class="hint empty">No data yet for these dates. Nothing was counted between
    <?= e(Format::fmtDate($page->fromInput())) ?> and <?= e(Format::fmtDate($page->toInput())) ?>.</p>
  <?php } else { ?>
  <div class="an-chart">
    <?php /* Each column is a day; the inner bar's height is the percentage of
             the busiest day. The wrapper keeps its height when the bar is 0 so
             the strip reads as a row of days rather than as a row of gaps. */ ?>
    <div class="an-bars" role="img" aria-label="<?= e($page->chartSummary()) ?>">
      <?php foreach ($bars as $bar) { ?>
      <div class="an-col<?= $bar['peak'] ? ' peak' : '' ?>" title="<?= e($bar['label'] . ': ' . $bar['views'] . ' landing' . ($bar['views'] === 1 ? '' : 's')) ?>">
        <span class="an-bar" style="height: <?= e($bar['pct']) ?>%"></span>
      </div>
      <?php } ?>
    </div>
    <div class="an-axis" aria-hidden="true">
      <?php foreach ($bars as $bar) { ?>
      <span class="an-tick"><?= $bar['labelled'] ? e($bar['short']) : '' ?></span>
      <?php } ?>
    </div>
    <p class="meta an-axis-note">Day of the month &#183; <?= e($page->rangeLabel()) ?></p>
  </div>

  <details class="fold an-table">
    <summary>Show the daily numbers</summary>
    <div class="tablewrap">
      <table class="ships">
        <thead><tr><th>Day</th><th class="num">Landings</th></tr></thead>
        <tbody>
          <?php foreach ($bars as $bar) { ?>
          <tr<?= $bar['peak'] ? ' class="changed"' : '' ?>>
            <td><?= e($bar['label']) ?></td>
            <td class="num"><?= e(Format::fmtNum((float) $bar['views'])) ?></td>
          </tr>
          <?php } ?>
        </tbody>
      </table>
    </div>
  </details>
  <?php } ?>

  <?php
  /*
   * One ranked block: heading, lede, the rows, an optional footnote. Written
   * once and used by both groups below, because the arrivals lists (added
   * 2026-10-25) are the same shape as the quote lists and a second copy of
   * this markup is a second place for a share to lose its escaping.
   *
   * The bars are a comparison and the share is the number: `pct` is of the
   * biggest row so the widest bar fills the column, `share` is the true
   * proportion of the whole range - including the rows past the cap, which is
   * why the shares in a capped list (countries, referring sites) need not add
   * up to 100%.
   *
   * @param list<array{label:string,n:int,share:string,pct:int}> $rows
   */
  $block = static function (string $heading, string $lede, array $rows, string $foot): void {
      ?>
    <section class="an-block">
      <h3><?= e($heading) ?></h3>
      <p class="hint"><?= e($lede) ?></p>
      <?php if ($rows === []) { ?>
      <p class="hint empty">Nothing in these dates.</p>
      <?php } else { ?>
      <ul class="an-list">
        <?php foreach ($rows as $row) { ?>
        <li>
          <span class="an-label"><?= e($row['label']) ?></span>
          <span class="an-track" aria-hidden="true"><span class="an-fill" style="width: <?= e($row['pct']) ?>%"></span></span>
          <span class="an-n"><?= e(Format::fmtNum((float) $row['n'])) ?></span>
          <span class="an-share meta"><?= e($row['share']) ?></span>
        </li>
        <?php } ?>
      </ul>
      <?php } ?>
      <?php if ($foot !== '') { ?>
      <p class="meta"><?= e($foot) ?></p>
      <?php } ?>
    </section>
      <?php
  };
  ?>

  <?php /* --------------------------------------------------- how they arrived */ ?>
  <?php if ($landings['has']) { ?>
  <?php /* Only under a landings figure that means something: with nothing
           counted, the empty-state sentence above already says so once, and
           three more "Nothing in these dates" would be the same news four
           times. */ ?>
  <h2 class="an-h">How people arrived</h2>
  <div class="an-cols">
    <?php
    $block('Where they came from', 'The site that sent them here. A click from one of our own pages is not a landing, so it is not in this list.', $page->referrersRanked(), 'Busiest ten. Shares are of every landing, so they need not add up to 100%.');
    $block('Phone or computer', 'The kind of screen they arrived on.', $page->devicesRanked(), '');
    $block('First page seen', 'The page they arrived on.', $page->pagesRanked(), '');
    ?>
  </div>
  <?php } ?>

  <?php /* --------------------------------------------- what people asked for */ ?>
  <h2 class="an-h">What people asked for</h2>
  <?php if (!$quotes['has']) { ?>
  <p class="hint empty">No data yet for these dates. The calculator showed no prices between
    <?= e(Format::fmtDate($page->fromInput())) ?> and <?= e(Format::fmtDate($page->toInput())) ?>,
    so there is no country, weight or service to rank.</p>
  <?php } else { ?>
  <div class="an-cols">
    <?php
    $block('Top countries', 'Where people wanted to send something, most asked first.', $page->countriesRanked(), '');
    $block('Weight chosen', 'The weight buttons on the calculator. A band is everything up to it: a 4 kg parcel is counted under 5 kg, because that is the price it was shown.', $page->bandsInOrder(), 'Every band is listed, including the ones nobody picked.');
    $block('Service chosen', 'Which service the price was taken for.', $page->servicesRanked(), '');
    ?>
  </div>
  <?php } ?>

  <?php /* ----------------------------------------------- the WhatsApp number */ ?>
  <h2 class="an-h">Prices that ended on WhatsApp</h2>
  <?php if (!$whatsApp['has']) { ?>
  <p class="hint empty">No data yet for these dates. No prices were shown, so there was no button to press.</p>
  <?php } else { ?>
  <p class="hint">
    <strong><?= e($page->whatsAppShare()) ?></strong> of the prices the calculator showed ended with the
    customer pressing &#8220;Book on WhatsApp&#8221; - <?= e($page->whatsAppSentence()) ?>.
    That is a count of the button being pressed, not of parcels booked: the conversation may still have
    gone nowhere. Booked shipments live in
    <a href="<?= e($this->url(Routes::SHIPMENTS)) ?>">Shipments</a>, and the messages people sent are in the
    <a href="<?= e($this->url(Routes::INBOX)) ?>">Inbox</a>.
  </p>
  <?php } ?>
</section>
