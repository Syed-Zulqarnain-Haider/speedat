<?php

declare(strict_types=1);

/**
 * The calculator's mount and its first paint. Port of the quick view in
 * `src/components/calculator/Calculator.tsx`, and the other side of the
 * contract in php/docs/assets.md section 6.
 *
 * THE MOUNT. `calculator.js` finds `[data-calculator]`, reads the blob whose
 * id that attribute names, and - only once it has a document AND a working
 * `window.SpeedatPricing` - replaces the mount's children in one go. So what
 * PHP prints inside it is two things at once: the first paint (which is why it
 * is the real panel and not a spinner, and why nothing moves when the script
 * takes over) and the no-script way out (which is why the `<noscript>` block is
 * there). If pricing.js fails to load, the script leaves this markup alone
 * rather than building half a calculator.
 *
 * THE STATE IS "country". That is where the React app starts too: no country
 * chosen, so no price, and the readout is the one line that says what to do
 * next. `data-state` and `data-n` are what calculator.css reserves the room
 * from - the block is 200px tall in every state - so the price lands in a slot
 * that already existed and the board under it never jumps.
 *
 * NOTHING HERE IS A PRICE THE ENGINE DID NOT GIVE. Each tile's "from PKR ..."
 * is `Copy::fromPrice()`, which is `Engine::priceAll` for 1 kg plus the
 * default add-ons - the same call calculator.js makes for the same tile. The
 * two cannot disagree.
 *
 * @var \Speedat\Core\View      $this
 * @var \Speedat\View\HomeView  $view
 */

/** @var \Speedat\View\CalculatorData $data */
$data = $view->calculator;
$doc  = $view->doc;
?>
<div data-calculator="<?= e(\Speedat\View\CalculatorData::MOUNT_ID) ?>">
  <div class="panel quick">
    <h2 class="sr">Get your price</h2>

    <div class="step-head is-next" data-step="country"><span class="step-name">1 Country</span></div>
    <div class="ctiles" role="radiogroup" aria-label="Country">
<?php foreach ($view->tiles as $tile) {
    /*
     * A spoken name for the tile. As a bare `role="radio"` the button read out
     * as just "button" on some screen readers, so the country - and its from-
     * price, when there is one - is stated outright. The dash in a days range
     * ("8-12") becomes "to" so it is not read as "minus". calculator.js builds
     * the identical label, so the scripted re-render announces the same thing.
     */
    $tileLabel = $tile->name;
    if ($tile->from !== null) {
        $tileLabel .= ', from ' . $tile->fromMoney;
        if ($tile->from->days !== '') {
            $tileLabel .= ', ' . str_replace(["\u{2013}", "\u{2014}", '-'], ' to ', $tile->from->days) . ' days';
        }
    }
?>
      <button type="button" role="radio" class="ctile" aria-checked="false" aria-label="<?= e($tileLabel) ?>"><?= $this->partial('site/partials/flag', ['flag' => $tile->flag]) ?><span class="ctile-name"><?= e($tile->name) ?></span><?php if ($tile->from !== null) { ?><span class="ctile-from">from <?= e($tile->fromMoney) ?><?php if ($tile->from->days !== '') { ?><span class="ctile-days"> · <?= e($tile->from->days) ?> days</span><?php } ?></span><?php } ?></button>
<?php } ?>
<?php if ($view->moreCountries) { ?>
      <select class="ctile-more" aria-label="More countries"><?= $this->partial('site/partials/dest-options', ['doc' => $doc, 'placeholder' => 'More countries']) ?></select>
<?php } ?>
    </div>

    <div class="step-head awaiting" data-step="weight"><span class="step-name">2 Weight</span></div>
    <div class="kgs" role="group" aria-label="Weight">
      <?php /* The next-step hint. Before a country is chosen it points back up
               ("First, pick a country above") and the section reads as waiting;
               once there is a country calculator.js changes it to "Now pick your
               parcel weight" and the section lights up. It is the FIRST child so
               the eye meets it before the buttons, and it holds one line so its
               wording can change without the price below it moving. */ ?>
      <p class="kg-guide awaiting" data-guide="weight">First, pick a country above</p>
<?php foreach ($view->weights as $kg) { ?>
      <button type="button" class="kg" aria-pressed="false"><strong><?= e($kg) ?></strong><span>kg</span></button>
<?php } ?>
<?php if ($view->overMaxLabel() !== '') { ?>
      <button type="button" class="kg more" aria-pressed="false"><?= e($view->overMaxLabel()) ?></button>
<?php } ?>
      <?php /* The way out to the detailed quote. It used to read "Documents · box size";
               the document choice went with the document rate (owner, 2026-09-27) and what
               is left behind the button is what it always also did: box sizes and several
               pieces. The label MUST match calculator.js's own, or the row reflows when the
               script takes the mount over. */ ?>
      <button type="button" class="kg other">Box size · many pieces</button>
      <?php /* EXACT WEIGHT (owner, 2026-09-30): the preset buttons cover only some
               weights, so a 4 kg or 7 kg parcel had nowhere to go. This dropdown
               lets the customer pick any weight the rate card prices. It is disabled
               here because, like every control in this row, it needs the script to
               price - calculator.js rebuilds it enabled and fills it with every
               supported weight through the SAME pricing path the buttons use. With
               JavaScript off the whole .kgs row is hidden and the WhatsApp way out
               below takes over, exactly as it does for the preset buttons. */ ?>
      <div class="kg-exact">
        <label class="kg-exact-lab"><span class="kg-exact-text">Or pick your exact weight</span>
          <select class="kg-select" disabled>
            <option value="" disabled selected>Exact weight…</option>
          </select>
        </label>
      </div>
    </div>

    <div class="result" data-state="country" data-n="<?= e($view->resultRows()) ?>">
      <p class="wait" aria-live="polite">Tap a country</p>
      <noscript>
        <div class="pricebar" data-state="nojs">
          <div class="pricebar-sum"><span class="tnote">Send us the country and the weight and we will price it by hand.</span></div>
          <a class="btn book giant" href="<?= e($doc->waHref()) ?>" target="_blank" rel="noopener"><?= $this->partial('site/partials/icon', ['id' => 'i-wa']) ?>Ask on WhatsApp</a>
        </div>
<?php if ($doc->telHref() !== '') { ?>
        <p class="wait">Or call <a href="<?= e($doc->telHref()) ?>"><?= e($doc->company()->phone) ?></a>.</p>
<?php } ?>
      </noscript>
    </div>
  </div>
</div>
<?php if ($doc->sampleNotice()) { ?>
<p class="sample">Sample prices for now. We confirm the real price on WhatsApp.</p>
<?php } ?>
<script type="application/json" id="<?= e(\Speedat\View\CalculatorData::MOUNT_ID) ?>"><?= $data->json() /* RAW by design: json() is the escaper - JSON_HEX_TAG and friends make a "</script>" in any value impossible. Passing this through e() would print the JSON as visible text. */ ?></script>
