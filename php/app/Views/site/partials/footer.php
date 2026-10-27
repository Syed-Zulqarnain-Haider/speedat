<?php

declare(strict_types=1);

/**
 * The two-line footer: the five page links (on a phone the site's only nav),
 * the theme toggle beside them, then one wrapping line of facts with every
 * number a plain link. Port of the footer in `src/app/(site)/layout.tsx`.
 *
 * The toggle is rendered WITH its glyph and its label already correct for the
 * theme the server read from the cookie, so the button is not empty for the
 * moment before ui.js runs and does not pop a glyph in. ui.js repaints it with
 * the same two paths and rebinds the click - it is written to expect either
 * (`paintToggle` runs whatever the server printed).
 *
 * The label names what a tap DOES, so it changes with the state - the
 * play/pause pattern. No aria-pressed, which would contradict a changing label.
 *
 * @var \Speedat\Core\View     $this
 * @var \Speedat\View\PageView $page
 */

use Speedat\View\PageView;

$dark  = $page->theme === PageView::THEME_DARK;
$label = $dark ? 'Light mode' : 'Dark mode';
?>
<footer class="site-foot">
  <?= $this->partial('site/partials/nav', ['page' => $page, 'label' => 'Pages']) ?>
  <button type="button" class="btn outline theme" aria-label="<?= e($label) ?>" title="<?= e($label) ?>"><?= $this->partial('site/partials/icon', ['id' => $dark ? 'i-sun' : 'i-moon']) ?></button>
  <p class="foot-line">
<?php foreach ($page->footFacts() as $fact) { ?>
    <span class="foot-fact"><?php
        echo e($fact->prefix);
        if ($fact->href !== '') {
            echo '<a href="' . e($fact->href) . '"' . ($fact->external ? ' target="_blank" rel="noopener"' : '') . '>' . e($fact->text) . '</a>';
        } else {
            echo e($fact->text);
        }
    ?></span>
<?php } ?>
  </p>
  <p class="foot-legal"><a href="<?= e($this->url('/track')) ?>">Track your shipment</a> &middot; By booking you agree to our <a href="<?= e($this->url('/terms')) ?>">Terms and Conditions</a>.</p>
</footer>
