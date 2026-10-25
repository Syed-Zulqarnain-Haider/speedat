<?php

declare(strict_types=1);

/**
 * Bulk adjust. Port of BulkAdjust.tsx.
 *
 * Its own form, posting to its own action: the arithmetic touches every rate
 * in the document, so the server does it and re-renders the editor. admin.js
 * repeats the two refusals in the browser only so the owner is not made to
 * wait for a round trip to be told he ticked nothing.
 *
 * TWO QUESTIONS, TWO FIELDSETS. The first row used to print five identical
 * checkboxes in one line - "Express | Normal | Express Saver | First slab and
 * every per-kilogram price | Per step" - with nothing saying that the first
 * three answer "which services" and the last two answer "which numbers". This
 * is the screen the owner opens when a carrier raises everything by 5%, so it
 * is the highest-stakes form in the product; it should not ask two questions
 * in one undifferentiated row.
 *
 * `$grid` hides "Per step" when the document is priced per kilogram: there is
 * no per-step price to raise, and the server applies the rise to the ladder
 * when "First slab" is ticked (see BulkForm::fields() and the `bulk` action).
 * A checkbox that means nothing under the live pricing model is a question
 * with no answer.
 *
 * APPLYING IS TWO PRESSES. "Apply to editor" rewrites every price in the
 * draft, and the only way back is Discard - which drops the whole draft, every
 * country. The first press now states what is about to happen; the second does
 * it. (It still does not PREVIEW the result; that wants the diff the review
 * card already builds, and is not in this change.)
 *
 * @var \Speedat\Core\View           $this
 * @var \Speedat\Core\Csrf           $csrf
 * @var \Speedat\View\Admin\BulkForm $bulk
 * @var bool                         $grid whether the draft prices per kilogram
 */

use Speedat\View\Admin\Routes;
use Speedat\View\Admin\Sections;

$grid = isset($grid) && (bool) $grid;
?>
<section class="block" id="sec-bulk">
  <p class="eyebrow"><?= e(Sections::no('bulk')) ?> - Bulk adjust</p>
  <h2>Bulk adjust</h2>
  <p class="desc">Change many prices at once, for example when a carrier raises everything by 5%. Applies to the editor; nothing is published until you review.</p>
  <form method="post" action="<?= e($this->url(Routes::BULK)) ?>" data-bulk-form>
    <?= $csrf->field() ?>
    <div class="pickers">
      <fieldset class="picker">
        <legend>Which services</legend>
        <div class="inline">
          <?php foreach ($bulk->services as $service) { ?>
          <label class="chk">
            <input type="checkbox" name="services[]" value="<?= e($service->id) ?>"<?= $bulk->serviceOn($service->id) ? ' checked' : '' ?>>
            <?= e($service->name) ?>
          </label>
          <?php } ?>
        </div>
      </fieldset>
      <fieldset class="picker">
        <legend>Which prices</legend>
        <div class="inline">
          <?php foreach ($bulk->fields() as $field) { ?>
          <?php if ($grid && $field['key'] === 'addl') { continue; } ?>
          <label class="chk">
            <input type="checkbox" name="fields[]" value="<?= e($field['key']) ?>"<?= $bulk->fieldOn($field['key']) ? ' checked' : '' ?>>
            <?= e($field['label']) ?>
          </label>
          <?php } ?>
        </div>
      </fieldset>
    </div>
    <div class="inline">
      <label class="field">
        <span>Change</span>
        <select name="mode">
          <?php foreach ($bulk->modes() as $value => $label) { ?>
          <option value="<?= e($value) ?>"<?= $value === $bulk->mode ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php } ?>
        </select>
      </label>
      <label class="field">
        <span>Value (negative to reduce)</span>
        <input type="number" step="any" name="value" value="<?= e($bulk->value) ?>" placeholder="5">
      </label>
      <label class="field">
        <span>Round result to nearest</span>
        <input type="number" min="1" step="1" name="roundTo" value="<?= e($bulk->roundTo) ?>">
      </label>
      <?php /* Two presses, the same arming the Remove buttons use: the first
               says what is about to happen to every price in the draft. */ ?>
      <button class="btn primary" type="submit" data-confirm="Change every ticked price in the draft?"<?= $bulk->readOnly ? ' disabled' : '' ?>>Apply to editor</button>
    </div>
    <div class="notice <?= e($bulk->messageKind !== '' ? $bulk->messageKind : 'warn') ?>" data-bulk-msg<?= $bulk->hasMessage() ? '' : ' hidden' ?>><?= e($bulk->message) ?></div>
  </form>
</section>
