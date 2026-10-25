<?php

declare(strict_types=1);

/**
 * The contact line that closes every inner page: the two numbers as plain
 * links and the hours on one line, then a WhatsApp button and, when there is a
 * landline, a Call button. A hairline above, no band, nothing centred. Port of
 * `src/components/site/CtaBand.tsx`.
 *
 * @var \Speedat\Core\View  $this
 * @var \Speedat\View\Reach $reach
 */
?>
<div class="reach">
<?php if ($reach->title !== '') { ?>
  <h2><?= e($reach->title) ?></h2>
<?php } ?>
<?php if ($reach->sub !== '') { ?>
  <p class="reach-sub"><?= e($reach->sub) ?></p>
<?php } ?>
<?php if ($reach->showLine) { ?>
  <p class="reach-line">WhatsApp <a href="<?= e($reach->waHref) ?>" target="_blank" rel="noopener"><?= e($reach->waText) ?></a><?php if ($reach->telHref !== '') { ?> · Call <a href="<?= e($reach->telHref) ?>"><?= e($reach->phone) ?></a><?php } ?><?php if ($reach->hours !== '') { ?> · <?= e($reach->hours) ?><?php } ?></p>
<?php } ?>
  <div class="reach-actions">
    <a class="btn wa big" href="<?= e($reach->waHref) ?>" target="_blank" rel="noopener"><?= $this->partial('site/partials/icon', ['id' => 'i-wa']) ?>WhatsApp us</a>
<?php if ($reach->telHref !== '') { ?>
    <a class="btn outline big" href="<?= e($reach->telHref) ?>"><?= $this->partial('site/partials/icon', ['id' => 'i-phone']) ?>Call</a>
<?php } ?>
  </div>
</div>
