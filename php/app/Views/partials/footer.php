<?php

declare(strict_types=1);

/**
 * Site footer. Structure only; phase 3 brings the v3 design's footer.
 *
 * @var \Speedat\Core\View $this
 * @var string             $appName
 * @var string             $year
 */
?>
<footer class="site-footer">
  <div class="site-footer__inner">
    <p class="site-footer__legal">
      &copy; <?= e($year ?? date('Y')) ?> <?= e($appName ?? 'Speedat International') ?>, Lahore.
    </p>
    <p class="site-footer__note">
      Rates shown are indicative until confirmed by the office.
    </p>
  </div>
</footer>
