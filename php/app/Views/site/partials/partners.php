<?php

declare(strict_types=1);

/**
 * "Our partners" - the carriers Speedat ships with.
 *
 * Six named partners the owner confirmed: DHL, FedEx, Team Global Express, UPS,
 * DPD and SkyNet (DPD replaced the originally-listed "DPT" once the owner gave
 * the dpd.co.uk logo, 2026-10-11). Each shows the partner's logo from
 * `public/assets/img/partners/<slug>.(svg|png|webp)` - the first that exists, so
 * a vector (DPD) is preferred where there is one. If a file is missing the
 * section still reads correctly: the logo falls back to the partner's name set
 * as plain styled text. Nothing is invented - these are the real carriers, and
 * the fallback never claims a logo that is not there.
 *
 * The alt text is the partner NAME (not "logo"), so a screen reader and a broken
 * image both say "DHL", "FedEx" and so on. The list is static, owner-confirmed
 * content; no value here comes from the request, so there is nothing to escape
 * beyond the names, which go through e() anyway as the house rule asks.
 *
 * @var \Speedat\Core\View $this
 */

/** @var list<array{slug:string,name:string}> The six carriers, in the owner's order. */
$partners = [
    ['slug' => 'dhl',        'name' => 'DHL'],
    ['slug' => 'fedex',      'name' => 'FedEx'],
    ['slug' => 'teamglobal', 'name' => 'Team Global Express'],
    ['slug' => 'ups',        'name' => 'UPS'],
    ['slug' => 'dpd',        'name' => 'DPD'],
    ['slug' => 'skynet',     'name' => 'SkyNet'],
];
?>
<section class="partners" aria-labelledby="partners-title">
  <h2 id="partners-title">Our partners</h2>
  <p class="partners-lede">We ship with the carriers you already trust.</p>
  <ul class="partner-list" aria-label="Our shipping partners">
<?php foreach ($partners as $partner) { ?>
<?php
// The logo may be a vector (DPD) or a raster; use the first file that exists.
$logo = null;
foreach (['svg', 'png', 'webp'] as $ext) {
    $candidate = 'img/partners/' . $partner['slug'] . '.' . $ext;
    if ($this->assetExists($candidate)) { $logo = $candidate; break; }
}
?>
    <li class="partner">
<?php if ($logo !== null) { ?>
      <img class="partner-logo" src="<?= e($this->asset($logo)) ?>" alt="<?= e($partner['name']) ?>" loading="lazy" decoding="async">
<?php } else { ?>
      <span class="partner-name"><?= e($partner['name']) ?></span>
<?php } ?>
    </li>
<?php } ?>
  </ul>
</section>
