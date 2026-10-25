<?php

declare(strict_types=1);

/**
 * The sign-in screen. Port of app/admin/login/page.tsx and LoginForm.tsx -
 * the same two ways in, the same second step, the same words.
 *
 * ONE STRUCTURAL DIFFERENCE, and it is the point of the port. The React form
 * held the Firebase ID token in the browser between the two steps and posted
 * it again with the code. Here each step is a plain POST: the first step's
 * result is kept server-side against the session and the browser is given
 * nothing but a cookie, so a token good enough to sign in never sits in a page
 * the owner might leave open on the office laptop.
 *
 * "Continue with Google" is a SUBMIT, and the token rides in a hidden field on
 * it. Only the browser can get an ID token from Google, so js/firebase-signin.js
 * intercepts the submit, does the popup, fills that field and lets the form go.
 * The route on the other end is the same plain POST either way, which is what
 * keeps the token out of anything long-lived.
 *
 * AND THE BUTTON STARTS HIDDEN. It is the one control on this page that cannot
 * work without JavaScript - with JS off there is no popup, no token, and the
 * post would reach the route only to be told to use the password form. So the
 * server renders it `hidden` and the script un-hides it once the SDK is actually
 * loaded and initialised. With JS off, or with Google's CDN unreachable, the
 * page is the password form and nothing else: no dead button, no "or" line
 * pointing at a way in that is not there.
 *
 * Refusals are worded identically whichever half failed, so nothing here can
 * be used to find out which addresses are admins.
 *
 * @var \Speedat\Core\View            $this
 * @var \Speedat\Core\Csrf            $csrf
 * @var \Speedat\View\Admin\LoginPage $page
 * @var string                        $firebase JSON for the browser, '' when unconfigured
 * @var string                        $theme    light|dark, from the theme cookie
 */

/*
 * '' when any of the four Firebase values is missing - see
 * Http\Admin\LoginController::firebaseConfigJson(). Defaulted here because the
 * template renders under callers that predate it.
 */
$firebase = isset($firebase) && is_string($firebase) ? $firebase : '';

use Speedat\View\Admin\Routes;

$this->layout('admin/layouts/plain', [
    'title' => 'Sign in',
    'theme' => isset($theme) && $theme === 'dark' ? 'dark' : 'light',
]);
?>
<div class="login">
  <aside class="login-side">
    <?php /* The real logo on the brand-yellow panel, the front door the owner
             knows from the live site (docs/brand.md). The dark-ink artwork sits
             directly on the yellow — 10.6:1 — so no inner plate is needed, and
             the tagline is inside the artwork, so it is not printed again. */ ?>
    <img class="brand-logo login-logo"
         src="<?= e($this->asset('img/brand/speedat-logo.png')) ?>"
         srcset="<?= e($this->asset('img/brand/speedat-logo.png')) ?> 1x, <?= e($this->asset('img/brand/speedat-logo@2x.png')) ?> 2x"
         width="660" height="133" alt="<?= e($page->companyName) ?>" decoding="async">
    <p class="eyebrow login-eyebrow">Admin</p>
  </aside>
  <main class="login-main">
    <div class="login-card">
      <p class="eyebrow">Admin</p>
      <h1>Rates admin</h1>
      <p class="lede"><?= e($page->lede()) ?></p>

      <?php if ($page->blocked()) { ?>
      <?php /* A deployment that cannot sign anyone in says so instead of
               showing a form that will always refuse. */ ?>
      <div class="notice warn"><?= e($page->configWarning) ?></div>
      <?php } elseif ($page->onCodeStep()) { ?>
      <form class="panel login-panel" method="post" action="<?= e($this->url(Routes::LOGIN_CODE)) ?>">
        <?= $csrf->field() ?>
        <p class="login-step">
          <span class="eyebrow">Second step</span>
          <?= e($page->codePrompt()) ?>
        </p>
        <label class="field">
          <span>Code</span>
          <input type="text" class="code" name="code" inputmode="numeric" autocomplete="one-time-code"
                 maxlength="7" pattern="[0-9 ]{6,7}" required autofocus>
        </label>
        <button class="btn primary big" type="submit">Continue</button>
        <?php if ($page->error !== '') { ?>
        <div class="notice err" role="alert"><?= e($page->error) ?></div>
        <?php } ?>
      </form>
      <?php /* The way out of a half-finished sign-in. The first step's result
               lives in the session, so abandoning it IS destroying the
               session - which is what the sign-out route does, and why this
               is that route rather than an invented one. */ ?>
      <form method="post" action="<?= e($this->url(Routes::LOGOUT)) ?>">
        <?= $csrf->field() ?>
        <button class="btn outline small" type="submit">Start again</button>
      </form>
      <?php } else { ?>
      <div class="panel login-panel">
        <?php if ($page->googleReady) { ?>
        <?php /* `hidden` on the wrapper, not the button: the "or" line is only
                 true while there is something above it to be "or" from, so both
                 appear and disappear together. js/firebase-signin.js removes
                 this attribute, and only after the SDK has initialised. */ ?>
        <div data-google-signin hidden>
          <form method="post" action="<?= e($this->url(Routes::LOGIN_GOOGLE)) ?>" data-google-form>
            <?= $csrf->field() ?>
            <?php /* Filled by the script from the popup's result, posted once,
                     and never written back into a rendered page - the code step
                     is a fresh request with nothing but a cookie. */ ?>
            <input type="hidden" name="idToken" value="" data-google-token>
            <button class="btn outline big" type="submit">Continue with Google</button>
          </form>
          <p class="login-or">or with email and password</p>
        </div>
        <?php } ?>
        <form method="post" action="<?= e($this->url(Routes::LOGIN_PASSWORD)) ?>">
          <?= $csrf->field() ?>
          <label class="field">
            <span>Email</span>
            <input type="email" name="email" value="<?= e($page->email) ?>" autocomplete="username" maxlength="190" required>
          </label>
          <label class="field">
            <span>Password</span>
            <input type="password" name="password" autocomplete="current-password" maxlength="200" required>
          </label>
          <button class="btn primary big" type="submit">Sign in</button>
        </form>
        <?php if ($page->error !== '') { ?>
        <div class="notice err" role="alert"><?= e($page->error) ?></div>
        <?php } ?>
      </div>
      <?php if ($page->googleReady && $firebase !== '') { ?>
      <?php /* RAW by design: firebaseConfigJson() is the escaper - JSON_HEX_TAG
               and friends make a "</script>" impossible inside any value. An
               application/json block is data, not code, so it needs no nonce.
               Reading the values from here rather than from the script file is
               what lets staging and live carry different projects. */ ?>
      <script type="application/json" id="firebase-config"><?= $firebase ?></script>
      <?php if ($this->assetExists('js/firebase-signin.js')) { ?>
      <script defer src="<?= e($this->asset('js/firebase-signin.js')) ?>"></script>
      <?php } ?>
      <?php } ?>
      <?php } ?>

      <p class="login-back">
        <a class="link" href="<?= e($this->url(Routes::SITE)) ?>">&#8592; Back to the website</a>
      </p>
    </div>
  </main>
</div>
