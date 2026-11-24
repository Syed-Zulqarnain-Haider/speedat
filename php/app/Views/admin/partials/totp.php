<?php

declare(strict_types=1);

/**
 * A person's own second factor: the panel the admin's Security page and the
 * marketers' account page both have, posting to their own addresses.
 *
 * @var \Speedat\Core\View               $this
 * @var \Speedat\Core\Csrf               $csrf
 * @var \Speedat\View\Admin\SecurityPage $page
 * @var string                           $begin
 * @var string                           $confirm
 * @var string                           $disable
 * @var string                           $cancel
 */

$enrolment = $page->enrolling() ? $page->enrolment : null;
?>
<div class="panel totp">
  <h2 class="step">Two-factor sign-in</h2>
  <p class="totp-state">
    <span class="pill<?= $page->totpEnabled ? ' live' : '' ?>"><?= $page->totpEnabled ? 'on' : 'off' ?></span>
    <span class="meta"><?= e($page->totpSentence()) ?></span>
  </p>
  <?php if ($page->error !== '') { ?>
  <div class="notice err" role="alert"><?= e($page->error) ?></div>
  <?php } ?>

  <?php if (!$page->totpEnabled && $enrolment === null) { ?>
  <form method="post" action="<?= e($this->url($begin)) ?>">
    <?= $csrf->field() ?>
    <button class="btn primary" type="submit" data-once data-busy-text="Preparing&#8230;">Set up an authenticator app</button>
  </form>
  <?php } ?>

  <?php if ($enrolment !== null) { ?>
  <div class="card qr">
    <p class="hint">Scan this with Google Authenticator, Authy or 1Password, then enter the code it shows.</p>
    <?php if ($enrolment['qrDataUrl'] !== '') { ?>
    <img src="<?= e($enrolment['qrDataUrl']) ?>" alt="QR code for the authenticator app" width="200" height="200">
    <?php } else { ?>
    <?php /* The QR is drawn with gd, which Hostinger lets an account switch
             off. Rather than a broken image, say so and let the key be
             typed - every authenticator app accepts that. */ ?>
    <p class="hint">This server cannot draw the QR image. Add the account by hand with the key below.</p>
    <?php } ?>
    <details class="fold"<?= $enrolment['qrDataUrl'] === '' ? ' open' : '' ?>>
      <summary>Cannot scan? Enter the key by hand</summary>
      <code class="secret"><?= e($enrolment['secret']) ?></code>
    </details>
  </div>
  <form method="post" action="<?= e($this->url($confirm)) ?>">
    <?= $csrf->field() ?>
    <div class="inline">
      <label class="field code-field">
        <span>6-digit code</span>
        <input type="text" class="code" name="code" inputmode="numeric" autocomplete="one-time-code"
               maxlength="7" pattern="[0-9 ]{6,7}" required autofocus>
      </label>
      <button class="btn primary" type="submit">Turn on</button>
      <?php /* Cancel is a link, not a button: it changes nothing except
               which page you are looking at, and the pending enrolment
               expires on its own. */ ?>
      <a class="btn outline" href="<?= e($this->url($cancel)) ?>">Cancel</a>
    </div>
  </form>
  <?php } ?>

  <?php if ($page->totpEnabled) { ?>
  <form method="post" action="<?= e($this->url($disable)) ?>">
    <?= $csrf->field() ?>
    <div class="inline">
      <label class="field code-field">
        <span>Current code to turn off</span>
        <input type="text" class="code" name="code" inputmode="numeric" autocomplete="one-time-code"
               maxlength="7" pattern="[0-9 ]{6,7}" required>
      </label>
      <button class="btn danger outline" type="submit" data-confirm="Confirm turn off">Turn off two-factor</button>
    </div>
  </form>
  <?php } ?>
</div>
