<?php

declare(strict_types=1);

/**
 * A list of field groups - a sub-heading and its rows - as SettingsFields and
 * ContentFields describe them. Both sections have the same shape, so they
 * share this one loop rather than two nearly-identical templates that drift
 * apart on the next change.
 *
 * @var \Speedat\Core\View $this
 * @var list<array{heading:string,rows:list<array{type:string,fields:list<\Speedat\View\Admin\Field>}>}> $groups
 * @var string             $formId
 */

$formId = isset($formId) && is_string($formId) ? $formId : '';
?>
<?php foreach ($groups as $group) { ?>
<?php if ($group['heading'] !== '') { ?>
<h3 class="fh"><?= e($group['heading']) ?></h3>
<?php } ?>
<?php foreach ($group['rows'] as $row) { ?>
<?php if ($row['type'] === 'grid2') { ?>
<div class="grid2">
  <?php foreach ($row['fields'] as $field) { ?>
  <?= $this->partial('admin/partials/field', ['field' => $field, 'formId' => $formId]) ?>
  <?php } ?>
</div>
<?php } else { ?>
<?php foreach ($row['fields'] as $field) { ?>
<?= $this->partial('admin/partials/field', ['field' => $field, 'formId' => $formId]) ?>
<?php } ?>
<?php } ?>
<?php } ?>
<?php } ?>
