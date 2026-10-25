<?php

declare(strict_types=1);

/**
 * The admin nav, in both its shapes. Port of AdminNav.tsx.
 *
 * `side` is the sidebar list from 1024px, and it carries the rates editor's
 * section sub-list while the editor is the page being shown. `top` is the row
 * that scrolls sideways on a phone. There is no drawer and no hamburger -
 * there is none in the Next app either, and adding one would not be the same
 * site.
 *
 * `aria-current="page"` is printed by PHP, which is what ui.js scrolls the
 * phone row to.
 *
 * @var \Speedat\Core\View        $this
 * @var \Speedat\View\Admin\Shell $shell
 * @var string                    $variant side|top
 */

$variant = isset($variant) && $variant === 'side' ? 'side' : 'top';
?>
<nav class="anav anav-<?= e($variant) ?>" aria-label="Admin sections">
<?php foreach ($shell->links() as $link) { ?>
  <a href="<?= e($this->url($link['href'])) ?>"<?= $link['active'] ? ' aria-current="page"' : '' ?>>
    <?= e($link['label']) ?>
    <?php if ($link['count'] > 0) { ?><span class="count"><?= e($link['count']) ?></span><?php } ?>
  </a>
  <?php if ($variant === 'side' && $link['key'] === 'rates' && $shell->sections() !== []) { ?>
  <ul class="anav-sub" aria-label="Rates sections">
    <?php foreach ($shell->sections() as $section) { ?>
    <li>
      <a href="#<?= e($section['anchor']) ?>">
        <span class="anav-n" aria-hidden="true"><?= e($section['no']) ?></span>
        <?= e($section['label']) ?>
      </a>
    </li>
    <?php } ?>
  </ul>
  <?php } ?>
<?php } ?>
</nav>
