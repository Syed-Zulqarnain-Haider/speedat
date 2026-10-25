<?php

declare(strict_types=1);

/**
 * The shared page header: a plain h1 and an optional lede, nothing to decode.
 * Port of `src/components/site/PageHead.tsx`.
 *
 * This is the page's ONE h1. Nothing else on an inner page prints one - the
 * section heads below it are h2s - which is what keeps the "exactly one h1"
 * rule of the quality bar true by construction rather than by review.
 *
 * @var \Speedat\Core\View $this
 * @var string             $title
 * @var string             $lede
 */

$lede = isset($lede) && is_string($lede) ? trim($lede) : '';
?>
<header class="page-head">
  <h1><?= e($title) ?></h1>
<?php if ($lede !== '') { ?>
  <p class="lede"><?= e($lede) ?></p>
<?php } ?>
</header>
