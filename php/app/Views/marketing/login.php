<?php

declare(strict_types=1);

/**
 * The marketers' sign-in. Google only: marketers have no passwords. The same
 * two steps as the admin's, and the same script, which needs only the
 * data-google-* hooks; see admin/login.php for why each of them is as it is.
 *
 * It says nothing about the deployment. What is missing from config.php is for
 * the admin, whose own sign-in page says so.
 *
 * @var \Speedat\Core\View            $this
 * @var \Speedat\Core\Csrf            $csrf
 * @var \Speedat\View\Admin\LoginPage $page
 * @var string                        $firebase JSON for the browser, '' when unconfigured
 * @var string                        $theme
 */

use Speedat\View\Admin\Routes;

$firebase = isset($firebase) && is_string($firebase) ? $firebase : '';
$ready    = $page->googleReady && $firebase !== '';

$this->layout('marketing/layouts/plain', ['title' => 'Marketing sign in', 'theme' => $theme]);
?>
<main class="mk-login">
  <div class="mk-login-card">
    <img class="mk-login-logo" src="<?= e($this->asset('img/brand/speedat-logo.png')) ?>" srcset="<?= e($this->asset('img/brand/speedat-logo.png')) ?> 1x, <?= e($this->asset('img/brand/speedat-logo@2x.png')) ?> 2x" width="660" height="133" alt="<?= e($page->companyName) ?>" decoding="async">
    <p class="mk-login-tag">Marketing</p>
    <h1><?= $page->onCodeStep() ? 'One more step' : 'Sign in to Marketing' ?></h1>

    <?php if ($page->onCodeStep()) { ?>
    <p class="mk-lede" id="code-help"><?= e($page->codePrompt()) ?></p>
    <form method="post" action="<?= e($this->url(Routes::MARKETING_LOGIN_CODE)) ?>">
      <?= $csrf->field() ?>
      <div class="mk-field">
        <label for="code">6-digit code</label>
        <input type="text" id="code" class="code" name="code" inputmode="numeric" autocomplete="one-time-code" enterkeyhint="done"
               maxlength="7" pattern="[0-9 ]{6,7}" aria-describedby="code-help<?= $page->error !== '' ? ' login-error' : '' ?>"<?= $page->error !== '' ? ' aria-invalid="true"' : '' ?> required autofocus>
      </div>
      <button class="btn primary big" type="submit">Continue</button>
    </form>
    <form method="post" action="<?= e($this->url(Routes::MARKETING_LOGOUT)) ?>">
      <?= $csrf->field() ?>
      <button class="btn outline small" type="submit">Start again</button>
    </form>
    <?php } elseif (!$ready) { ?>
    <div class="notice warn">Sign-in is not set up on this website yet. Please ask your Speedat admin.</div>
    <?php } else { ?>
    <p class="mk-lede">Use the Google account your Speedat admin added.</p>
    <div data-google-signin hidden>
      <form method="post" action="<?= e($this->url(Routes::MARKETING_LOGIN_GOOGLE)) ?>" data-google-form>
        <?= $csrf->field() ?>
        <input type="hidden" name="idToken" value="" autocomplete="off" data-google-token>
        <button class="btn primary big" type="submit">Continue with Google</button>
      </form>
    </div>
    <p class="hint" data-google-fallback>Sign-in is with Google. If no button appears, turn JavaScript on, check your connection and reload this page. If you opened the link from WhatsApp or Facebook, open it in Chrome or Safari instead.</p>
    <?php } ?>

    <?php if ($page->error !== '') { ?>
    <div class="notice err" role="alert" id="login-error"><?= e($page->error) ?></div>
    <?php } ?>

    <p class="mk-login-back"><a href="<?= e($this->url(Routes::SITE)) ?>">Back to the website</a></p>
  </div>
</main>
<?php if ($ready && !$page->onCodeStep()) { ?>
<?php /* RAW by design: firebaseConfigJson() is the escaper. */ ?>
<script type="application/json" id="firebase-config"><?= $firebase ?></script>
<script defer src="<?= e($this->asset('js/firebase-signin.js')) ?>"></script>
<?php } ?>
