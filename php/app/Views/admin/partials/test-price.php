<?php

declare(strict_types=1);

/**
 * "Test a price". Port of TestPrice.tsx, with one deliberate difference: it
 * posts, and the SERVER prices it with Speedat\Domain\Pricing\Engine.
 *
 * That is the whole point of the panel. Wiring it to the browser engine would
 * prove that the browser agrees with itself; the owner needs to know that the
 * number his customer will be charged - the one the server computes - is the
 * number he expects, including the unpublished changes sitting in the draft.
 *
 * @var \Speedat\Core\View                 $this
 * @var \Speedat\Core\Csrf                 $csrf
 * @var \Speedat\View\Admin\TestPricePanel $test
 */

use Speedat\View\Admin\Routes;
use Speedat\View\Admin\Sections;
?>
<section class="block" id="sec-test">
  <p class="eyebrow"><?= e(Sections::no('test')) ?> - Test</p>
  <h2>Test a price</h2>
  <p class="desc">Uses the rates in the editor, including unpublished changes, so you can check a price before publishing.</p>
  <form method="post" action="<?= e($this->url(Routes::TEST)) ?>">
    <?= $csrf->field() ?>
    <div class="inline">
      <label class="field">
        <span>Destination</span>
        <select name="destId">
          <option value=""<?= $test->destId === '' ? ' selected' : '' ?> disabled>Choose</option>
          <?php foreach ($test->destinations as $dest) { ?>
          <option value="<?= e($dest['id']) ?>"<?= $dest['id'] === $test->destId ? ' selected' : '' ?>><?= e($dest['name']) ?></option>
          <?php } ?>
        </select>
      </label>
      <?php /* No "Type" select: with the document rate gone there is one kind
               of shipment, and it is priced by weight. */ ?>
      <label class="field">
        <span>Weight per piece (kg)</span>
        <input type="number" step="any" min="0.1" name="kg" value="<?= e($test->kg) ?>" placeholder="2.5">
      </label>
      <label class="field">
        <span>Pieces</span>
        <input type="number" min="1" step="1" name="pieces" value="<?= e($test->pieces) ?>">
      </label>
      <label class="field">
        <span>L&#215;W&#215;H (cm)</span>
        <div class="dims">
          <input type="number" step="any" name="L" value="<?= e($test->length) ?>" placeholder="L" aria-label="Length">
          <span class="x">&#215;</span>
          <input type="number" step="any" name="W" value="<?= e($test->width) ?>" placeholder="W" aria-label="Width">
          <span class="x">&#215;</span>
          <input type="number" step="any" name="H" value="<?= e($test->height) ?>" placeholder="H" aria-label="Height">
        </div>
      </label>
      <button class="btn primary" type="submit">Test the price</button>
    </div>
  </form>
  <div class="test-result">
    <?php if (!$test->priced) { ?>
    <div class="meta"><?= e($test->notice) ?></div>
    <?php } else { ?>
    <div class="meta"><?= e($test->weightSentence) ?></div>
    <div class="test-out">
      <?php foreach ($test->cards as $card) { ?>
      <div class="test-card<?= $card['offered'] ? '' : ' dim' ?>">
        <div class="eyebrow"><?= e($card['name']) ?><?= $card['days'] !== '' ? e(" \u{b7} " . $card['days'] . ' days') : '' ?></div>
        <div class="display"><?= e($card['total']) ?></div>
        <div class="hint">
          <?= e($card['hint']) ?>
          <?php if ($card['eta'] !== '') { ?>
          <br><?= e($card['eta']) ?>
          <?php } ?>
        </div>
      </div>
      <?php } ?>
    </div>
    <?php } ?>
  </div>
</section>
