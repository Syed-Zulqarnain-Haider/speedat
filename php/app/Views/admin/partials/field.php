<?php

declare(strict_types=1);

/**
 * One labelled control. Port of Fld/Area in fields.tsx plus the selects and
 * checkboxes the React forms wrote out by hand.
 *
 * A checkbox is printed with a hidden companion of the same name carrying "0",
 * because a browser posts nothing at all for an unticked box: without the
 * companion the server cannot tell "he unticked Show on site" from "that field
 * was not on this form", and the difference is whether a country disappears
 * from the website.
 *
 * `$formId` associates the control with a form it is not inside. The rates
 * editor needs it: HTML forms cannot nest, Bulk adjust and Test a price sit
 * between the editor's own sections, and `form="editor"` is the standard way
 * to say which form a field belongs to. It needs no JavaScript, and
 * `new FormData(form)` picks these fields up as well.
 *
 * @var \Speedat\Core\View        $this
 * @var \Speedat\View\Admin\Field $field
 * @var string                    $formId  the id of the form to post with, "" when inside it
 */

use Speedat\View\Admin\Field;

$formId    = isset($formId) && is_string($formId) ? $formId : '';
$formAttr  = $formId !== '' ? ' form="' . e($formId) . '"' : '';
$disabled  = $field->disabled ? ' disabled' : '';
$maxLength = $field->maxLength > 0 ? ' maxlength="' . e($field->maxLength) . '"' : '';
// `cssClass` comes from the view model, never from a request; it is still
// escaped, because "it cannot contain a quote" is a claim that ages badly.
$cssClass  = $field->cssClass !== '' ? ' class="' . e($field->cssClass) . '"' : '';
$areaClass = ' class="' . e(($field->mono ? 'paste' : 'long') . ($field->cssClass !== '' ? ' ' . $field->cssClass : '')) . '"';
?>
<?php if ($field->type === Field::CHECKBOX) { ?>
<label class="chk">
  <input type="hidden" name="<?= e($field->name) ?>" value="0"<?= $formAttr ?>>
  <input type="checkbox" name="<?= e($field->name) ?>" value="<?= e($field->value) ?>"<?= $field->checked ? ' checked' : '' ?><?= $formAttr ?><?= $disabled ?>>
  <?= e($field->label) ?>
</label>
<?php } else { ?>
<label class="field">
  <span><?= e($field->label) ?></span>
  <?php if ($field->type === Field::TEXTAREA) { ?>
  <textarea<?= $areaClass ?> name="<?= e($field->name) ?>"<?php if ($field->placeholder !== '') { ?> placeholder="<?= e($field->placeholder) ?>"<?php } ?><?= $maxLength ?><?= $formAttr ?><?= $disabled ?>><?= e($field->value) ?></textarea>
  <?php } elseif ($field->type === Field::SELECT) { ?>
  <select name="<?= e($field->name) ?>"<?= $formAttr ?><?= $disabled ?>>
    <?php foreach ($field->options as $value => $label) { ?>
    <option value="<?= e($value) ?>"<?= (string) $value === $field->value ? ' selected' : '' ?>><?= e($label) ?></option>
    <?php } ?>
  </select>
  <?php } else { ?>
  <input type="<?= e($field->type) ?>"<?= $cssClass ?> name="<?= e($field->name) ?>" value="<?= e($field->value) ?>"<?php if ($field->placeholder !== '') { ?> placeholder="<?= e($field->placeholder) ?>"<?php } ?><?php if ($field->type === Field::NUMBER) { ?> inputmode="decimal" step="<?= e($field->step !== '' ? $field->step : 'any') ?>"<?php } ?><?= $maxLength ?><?= $formAttr ?><?= $disabled ?>>
  <?php } ?>
  <?php if ($field->hint !== '') { ?>
  <span class="hint"><?= e($field->hint) ?></span>
  <?php } ?>
</label>
<?php } ?>
