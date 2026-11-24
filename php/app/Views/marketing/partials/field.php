<?php

declare(strict_types=1);

/**
 * One labelled box in the marketers' editor. The same `Field` the admin draws,
 * with its label tied to it by id and its hint by aria-describedby.
 *
 * @var \Speedat\View\Admin\Field $field
 * @var string                    $role  title|desc for the search preview, "" otherwise
 * @var int                       $best  the length a search engine shows, 0 for none
 */

use Speedat\View\Admin\Field;

// The page is the tab being looked at, so its name is not said again.
$label = (string) preg_replace('/^(Home|Services|About|Contact|FAQ): /', '', $field->label);
$label = ucfirst($label);

$id   = 'f-' . preg_replace('/[^A-Za-z0-9]/', '', $field->name);
$hint = $field->hint !== '' ? $id . '-hint' : '';
$max  = $field->maxLength > 0 ? ' maxlength="' . e($field->maxLength) . '"' : '';
$aria = $hint !== '' ? ' aria-describedby="' . e($hint) . '"' : '';
$data = $role !== '' ? ' data-serp-field="' . e($role) . '" data-best="' . e($best) . '"' : '';
?>
<div class="mk-field">
  <label for="<?= e($id) ?>"><?= e($label) ?></label>
  <?php if ($field->type === Field::TEXTAREA || $role === 'desc') { ?>
  <textarea id="<?= e($id) ?>" name="<?= e($field->name) ?>" rows="<?= $role === 'desc' ? 3 : 7 ?>"<?= $max . $aria . $data ?>><?= e($field->value) ?></textarea>
  <?php } else { ?>
  <input type="text" id="<?= e($id) ?>" name="<?= e($field->name) ?>" value="<?= e($field->value) ?>" autocomplete="off"<?= $max . $aria . $data ?>>
  <?php } ?>
  <?php if ($hint !== '') { ?>
  <span class="hint" id="<?= e($hint) ?>"<?= $role !== '' ? ' data-count' : '' ?>><?= e($field->hint) ?></span>
  <?php } ?>
</div>
