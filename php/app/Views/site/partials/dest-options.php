<?php

declare(strict_types=1);

/**
 * The options inside a destination `<select>`. Port of `DestOptions` in
 * `Calculator.tsx`: up to eight destinations are one flat A-Z list; beyond
 * that they are split into "Popular" (the first five in the admin's order) and
 * "All destinations" (every one, A-Z), because a flat list of forty is a
 * scroll and the five the business actually ships to are the answer.
 *
 * No option is pre-selected: the placeholder is `disabled`, so the control
 * cannot be submitted empty by accident and the browser shows it until a real
 * choice is made.
 *
 * @var \Speedat\Core\View                       $this
 * @var \Speedat\View\Doc                        $doc
 * @var string                                   $placeholder
 */

$placeholder = isset($placeholder) && is_string($placeholder) && $placeholder !== '' ? $placeholder : 'Choose a country';
$active      = $doc->active();
$alpha       = $doc->activeAlpha();
?>
<option value="" disabled selected><?= e($placeholder) ?></option>
<?php if (count($active) <= 8) { ?>
<?php foreach ($alpha as $dest) { ?>
<option value="<?= e($dest->id) ?>"><?= e($dest->name) ?></option>
<?php } ?>
<?php } else { ?>
<optgroup label="Popular">
<?php foreach (array_slice($active, 0, 5) as $dest) { ?>
<option value="<?= e($dest->id) ?>"><?= e($dest->name) ?></option>
<?php } ?>
</optgroup>
<optgroup label="All destinations">
<?php foreach ($alpha as $dest) { ?>
<option value="<?= e($dest->id) ?>"><?= e($dest->name) ?></option>
<?php } ?>
</optgroup>
<?php } ?>
