<?php

declare(strict_types=1);

/**
 * The price hold. Port of HoldBar.tsx.
 *
 * Any admin may take prices OFF the website - that is the safe direction and
 * the one somebody needs at 9pm when a carrier's rates change. Only an owner
 * puts them back, the same trust as publishing, so the Resume button is not
 * printed for anyone else and the server checks the role again anyway.
 *
 * The confirm dialog is in the page already, hidden; with JavaScript off the
 * button is a link to `?hold=1` and the server renders it open.
 *
 * @var \Speedat\Core\View            $this
 * @var \Speedat\Core\Csrf            $csrf
 * @var \Speedat\View\Admin\HoldPanel $hold
 */

use Speedat\View\Admin\HoldPanel;
use Speedat\View\Admin\Routes;
?>
<?php if ($hold->on) { ?>
<div class="hold-bar on" id="hold">
  <div class="notice warn hold" role="status">
    <div class="hold-row">
      <div>
        <strong>Prices are on hold.</strong> Customers see your message and a WhatsApp form instead of prices<?= e($hold->sinceSentence()) ?>.
        <div class="meta">&#8220;<?= e($hold->message) ?>&#8221;</div>
        <div class="meta">Key in the new rates below and publish them to show prices again, or resume with the old ones.</div>
      </div>
      <?php if ($hold->isOwner) { ?>
      <form method="post" action="<?= e($this->url(Routes::HOLD)) ?>">
        <?= $csrf->field() ?>
        <input type="hidden" name="on" value="0">
        <button class="btn primary" type="submit" data-once data-busy-text="Resuming&#8230;">Resume showing prices</button>
      </form>
      <?php } else { ?>
      <span class="meta">An owner resumes prices.</span>
      <?php } ?>
    </div>
  </div>
</div>
<?php } else { ?>
<div class="hold-bar<?= $hold->dialogOpen ? ' open' : '' ?>" id="hold">
  <?php /* THE STATE, PRINTED, AND THE ACTION NAMED AFTER WHAT IT DOES.
           This was one small outline button reading "Hold prices", computed
           byte for byte the same as "Sort A-Z" and "Compare one weight" three
           hundred pixels below it, whose only explanation was a `title=` -
           hover-only, and so invisible on the phone the owner checks things
           on. Two presses take the whole price list off speedat.co. It sat
           alone under the heading with no label, no section and no grouping,
           so it also read as decoration: easy to press by accident and hard
           to find in a hurry at 9pm when a carrier changes rates, which is
           the one case the feature exists for.

           It now says where prices stand, says what the button will do in
           words at reading size, and wears the danger red instead of the same
           navy outline as a sort control. The block is `data-hold-idle` so
           admin.js puts it away with the button when the dialog opens. */ ?>
  <div class="hold-state" data-hold-idle<?= $hold->dialogOpen ? ' hidden' : '' ?>>
    <strong class="hold-now">Prices are showing on the website</strong>
    <a class="btn small danger hold-btn" href="<?= e($this->url(Routes::holdDialog())) ?>" data-hold-open>Take prices off the website</a>
    <span class="hint">Customers then see your message and a WhatsApp form instead of prices, until you publish new rates.</span>
  </div>
  <form class="notice info hold" method="post" action="<?= e($this->url(Routes::HOLD)) ?>" data-hold-dialog role="dialog" aria-label="Hold prices"<?= $hold->dialogOpen ? '' : ' hidden' ?>>
    <?= $csrf->field() ?>
    <input type="hidden" name="on" value="1">
    <strong>Take every price off the website now?</strong>
    <p class="desc">Nothing in the editor changes. Customers see the message below with a WhatsApp form until you publish new rates (or press Resume).</p>
    <label class="field">
      <span>Message shown to customers</span>
      <textarea class="long" name="message" rows="3" maxlength="<?= e(HoldPanel::MESSAGE_MAX) ?>" data-hold-count="hold-count"><?= e($hold->message) ?></textarea>
      <span class="hint"><span id="hold-count"><?= e($hold->messageLength()) ?></span>/<?= e(HoldPanel::MESSAGE_MAX) ?></span>
    </label>
    <div class="btn-row">
      <button class="btn primary" type="submit" data-once data-busy-text="Holding&#8230;">Hold prices now</button>
      <a class="btn outline" href="<?= e($this->url(Routes::RATES)) ?>" data-hold-cancel>Cancel</a>
    </div>
  </form>
</div>
<?php } ?>
