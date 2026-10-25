<?php

declare(strict_types=1);

/**
 * A country flag, or the two-letter badge for a country with no file. Port of
 * `src/components/site/Flag.tsx`; the shape is php/docs/assets.md section 4.
 *
 * The name is always printed beside it, so the image is decorative: `alt=""`,
 * and the badge is `aria-hidden`. Width and height are always set so nothing
 * shifts as the file lands. Above-the-fold flags are fetched with high
 * priority - the flag is the one symbol a first-time visitor recognises and
 * must be there on the first paint; only the rates board below the fold is
 * lazy.
 *
 * @var \Speedat\Core\View  $this
 * @var \Speedat\View\Flag  $flag
 */
?>
<?php if ($flag->code === null) { ?>
<span class="flag flag-badge" style="width:<?= e($flag->width) ?>px;height:<?= e($flag->height) ?>px" aria-hidden="true"><?= e($flag->badge) ?></span>
<?php } else { ?>
<img class="flag" src="<?= e($this->asset($flag->assetPath())) ?>" alt="" width="<?= e($flag->width) ?>" height="<?= e($flag->height) ?>"<?= $flag->lazy ? ' loading="lazy"' : ' fetchpriority="high"' ?>>
<?php } ?>
