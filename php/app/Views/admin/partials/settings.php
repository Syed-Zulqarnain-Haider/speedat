<?php

declare(strict_types=1);

/**
 * Settings. Port of SettingsForm.tsx.
 *
 * Its fields belong to the editor form (`form="editor"`) even though they are
 * not inside it: a settings change and a price change are one save and one
 * published version, which is what keeps "tax went up and so did the prices"
 * from being two versions where the site was briefly wrong.
 *
 * `form#editor` carries Settings and Website pages and nothing else now: the
 * countries post one form each, because the whole document in one request
 * body crossed PHP's `max_input_vars` and stopped saving entirely. So this
 * section has its own Save - a plain submit, marked `data-save-submit` so
 * that admin.js hides it once it is autosaving the same form.
 *
 * @var \Speedat\Core\View            $this
 * @var \Speedat\View\Admin\RatesPage $page
 */

use Speedat\View\Admin\Sections;
use Speedat\View\Admin\SettingsFields;
?>
<section class="block" id="sec-settings">
  <p class="eyebrow"><?= e(Sections::no('settings')) ?> - Settings</p>
  <h2>Settings</h2>
  <p class="desc">Changes here apply to every destination.</p>
  <?= $this->partial('admin/partials/field-groups', [
      'groups' => SettingsFields::groups($page->draft, $page->readOnly),
      'formId' => 'editor',
  ]) ?>
  <?php /* With JavaScript the editor autosaves this form; without it this is
           the save, and it saves Settings and Website pages - not the
           countries, which have their own. */ ?>
  <div class="btn-row">
    <button class="btn primary" type="submit" form="editor" data-save-submit<?= $page->readOnly ? ' disabled' : '' ?>>Save settings and pages</button>
  </div>
</section>
