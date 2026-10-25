<?php

declare(strict_types=1);

/**
 * What the quote page shows while prices are on hold: the owner's message and
 * a short form that turns into a WhatsApp message, so a customer can still ask
 * for a rate by hand. Port of `src/components/site/HoldPanel.tsx`.
 *
 * NO PRICE MAY APPEAR ANYWHERE IN THIS HTML. That is not a rule this file
 * follows by being careful - it is true because `HomeView` built a `HoldData`
 * instead of a `CalculatorData`, `Board::rows()` never called the engine, and
 * the rate document is not on the page at all. The blob below carries four
 * scalars and a list of names.
 *
 * With JavaScript off the three fields are inert, so the `<noscript>` block
 * gives the same way out the live panel builds: a plain WhatsApp link and the
 * phone number.
 *
 * @var \Speedat\Core\View     $this
 * @var \Speedat\View\HomeView $view
 */

/** @var \Speedat\View\HoldData $hold */
$hold = $view->hold;
$doc  = $view->doc;
?>
<div data-hold-panel="<?= e(\Speedat\View\HoldData::MOUNT_ID) ?>">
  <div class="panel quick hold-panel">
    <h2 class="step-head"><span class="step-name">Prices are being updated</span></h2>
    <p class="hold-msg"><?= e($doc->holdMessage()) ?></p>
    <label class="field">
      <span class="lab">Country</span>
      <select><option value="" disabled selected>Tap to choose</option>
<?php foreach ($hold->destinations() as $dest) { ?>
        <option value="<?= e($dest->id) ?>"><?= e($dest->name) ?></option>
<?php } ?>
      </select>
    </label>
    <label class="field">
      <span class="lab">Weight (kg)</span>
      <input type="number" inputmode="decimal" min="0.1" step="0.1" max="10000" placeholder="<?= e($view->holdWeightHint()) ?>" value="">
    </label>
    <label class="field">
      <span class="lab">What is inside (optional)</span>
      <input type="text" maxlength="120" placeholder="Clothes, documents, gifts…" value="">
    </label>
    <a class="btn wa giant" href="<?= e($doc->waHref()) ?>" target="_blank" rel="noopener"><?= $this->partial('site/partials/icon', ['id' => 'i-wa']) ?>Ask for today&#039;s rate on WhatsApp</a>
    <p class="hint">Add the country and weight so we can quote straight away.</p>
<?php if ($doc->telHref() !== '') { ?>
    <noscript><p class="hint">Or call <a href="<?= e($doc->telHref()) ?>"><?= e($doc->company()->phone) ?></a>.</p></noscript>
<?php } ?>
  </div>
</div>
<script type="application/json" id="<?= e(\Speedat\View\HoldData::MOUNT_ID) ?>"><?= $hold->json() /* RAW by design; see the note in calculator.php. */ ?></script>
