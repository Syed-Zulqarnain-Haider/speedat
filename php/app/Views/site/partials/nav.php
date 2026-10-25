<?php

declare(strict_types=1);

/**
 * The five page links with the current page marked. Port of
 * `src/components/site/Nav.tsx`.
 *
 * Rendered twice per page: in the header (hidden below 1024 by site.css) and
 * in the footer as "Pages", which on a phone is the site's only nav. Nothing
 * but links lives inside the landmark - the theme toggle is the footer's own
 * sibling, not a nav item.
 *
 * @var \Speedat\Core\View     $this
 * @var \Speedat\View\PageView $page
 * @var string                 $label the landmark's aria-label
 */

$label = isset($label) && is_string($label) && $label !== '' ? $label : 'Site';
?>
<nav class="nav" aria-label="<?= e($label) ?>">
<?php foreach ($page->navLinks() as $link) { ?>
<a href="<?= e($this->url($link['href'])) ?>"<?= $link['current'] ? ' aria-current="page"' : '' ?>><?= e($link['label']) ?></a>
<?php } ?>
</nav>
