<?php

declare(strict_types=1);

/**
 * Website pages - the words on the site. Port of ContentForm.tsx.
 *
 * Like Settings, the fields belong to the editor form: publishing rates
 * publishes these too, which is what the description says and what the owner
 * expects when he fixes a typo and presses Publish.
 *
 * @var \Speedat\Core\View            $this
 * @var \Speedat\View\Admin\RatesPage $page
 */

use Speedat\View\Admin\ContentFields;
use Speedat\View\Admin\Sections;
?>
<section class="block" id="sec-content">
  <p class="eyebrow"><?= e(Sections::no('content')) ?> - Pages</p>
  <h2>Website pages</h2>
  <p class="desc">The words on the site. One line per item where it says so; separate the parts of a line with a vertical bar |. Publishing rates publishes these too.</p>
  <?= $this->partial('admin/partials/field-groups', [
      'groups' => ContentFields::groups($page->draft, $page->readOnly),
      'formId' => 'editor',
  ]) ?>
  <?php /* The same save as Settings, at the other end of a long section: both
           store this one form, and admin.js hides both once it is autosaving
           it. The countries are not in this form and are not saved by it. */ ?>
  <div class="btn-row">
    <button class="btn primary" type="submit" form="editor" data-save-submit<?= $page->readOnly ? ' disabled' : '' ?>>Save settings and pages</button>
  </div>
</section>
