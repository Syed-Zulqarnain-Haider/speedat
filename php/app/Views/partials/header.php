<?php

declare(strict_types=1);

/**
 * Site header. Structure only - phase 3 replaces the markup with the v3
 * design's header from src/components, and the class names below are the ones
 * that design already uses so the CSS lands on something.
 *
 * @var \Speedat\Core\View $this
 * @var string             $appName
 */
?>
<a class="skip-link" href="#main">Skip to content</a>
<header class="site-header">
  <div class="site-header__inner">
    <a class="site-header__brand" href="<?= e($this->url('/')) ?>">
      <?= e($appName ?? 'Speedat International') ?>
    </a>
    <nav class="site-nav" aria-label="Primary">
      <a class="site-nav__link" href="<?= e($this->url('/')) ?>">Quote</a>
      <a class="site-nav__link" href="<?= e($this->url('/services')) ?>">Services</a>
      <a class="site-nav__link" href="<?= e($this->url('/contact')) ?>">Contact</a>
    </nav>
  </div>
</header>
