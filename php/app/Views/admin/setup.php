<?php

declare(strict_types=1);

/**
 * The first-run screen: create the owner, once, from a browser.
 *
 * It wears the sign-in layout on purpose. This is the page a new installation
 * shows in place of a sign-in it cannot yet offer, and an operator who has just
 * uploaded a release should recognise it as the same site rather than wonder
 * what he has reached.
 *
 * THE SET-UP KEY IS TYPED IN, NEVER PRINTED. It lives in config/config.php and
 * the person filling this in has that file open in hPanel's File Manager;
 * echoing it back - even into a hidden field - would put the one secret guarding
 * this door into the browser's cache and the page's HTML.
 *
 * `autocomplete="new-password"` on both password boxes so the browser offers to
 * generate one and does not paste the owner's Gmail password into a field that
 * will become this site's only credential.
 *
 * @var \Speedat\Core\View           $this
 * @var \Speedat\Core\Csrf           $csrf
 * @var \Speedat\Http\Admin\SetupPage $page
 * @var string                       $theme light|dark, from the theme cookie
 */

use Speedat\Http\Admin\SetupController;

$this->layout('admin/layouts/plain', [
    'title' => 'Set up this site',
    'theme' => isset($theme) && $theme === 'dark' ? 'dark' : 'light',
]);
?>
<div class="login">
  <aside class="login-side">
    <span class="mark" aria-hidden="true" style="width: 48px; height: 48px"></span>
    <p class="login-name"><?= e($page->companyName) ?></p>
    <p class="eyebrow"><?= e($page->tagline) ?></p>
    <span class="login-ghost" aria-hidden="true">Set up</span>
  </aside>
  <main class="login-main">
    <div class="login-card">
      <p class="eyebrow">First run</p>
      <h1>Create the first owner</h1>
      <p class="lede"><?= e($page->lede()) ?></p>

      <?php if ($page->blocked()) { ?>
      <?php /* No sign-in method is switched on, so an account created here
               could not be used. This page refuses rather than creating one:
               an owner nobody can sign in as is the same dead end one step
               further along. */ ?>
      <div class="notice warn" role="alert">
        Nothing has been created. Switch a sign-in method on first, then reload this page.
      </div>
      <?php } else { ?>
      <form class="panel login-panel" method="post" action="<?= e($this->url(SetupController::PATH)) ?>">
        <?= $csrf->field() ?>
        <label class="field">
          <span>Set-up key</span>
          <input type="password" name="setup_key" autocomplete="off" maxlength="200" required autofocus>
        </label>
        <label class="field">
          <span>Owner email</span>
          <input type="email" name="email" value="<?= e($page->email) ?>" autocomplete="username" maxlength="190" required>
        </label>
        <label class="field">
          <span>Name</span>
          <input type="text" name="name" value="<?= e($page->name) ?>" autocomplete="name" maxlength="190">
        </label>
        <?php if ($page->wantsPassword()) { ?>
        <label class="field">
          <span>Password</span>
          <input type="password" name="password" autocomplete="new-password" minlength="<?= (int) $page->minChars ?>" maxlength="128" required>
        </label>
        <label class="field">
          <span>Password again</span>
          <input type="password" name="password_again" autocomplete="new-password" minlength="<?= (int) $page->minChars ?>" maxlength="128" required>
        </label>
        <p class="login-or">At least <?= (int) $page->minChars ?> characters. A phrase you will remember beats a short jumble.</p>
        <?php } ?>
        <button class="btn primary big" type="submit">Create owner and continue</button>
        <?php if ($page->error !== '') { ?>
        <div class="notice err" role="alert"><?= e($page->error) ?></div>
        <?php } ?>
      </form>
      <?php /* Said before it happens, because it cannot be undone from here:
               once this works the page is gone, and the way back in is the
               sign-in screen. */ ?>
      <p class="login-or">
        This page disappears as soon as an owner exists. Remove the auth.setup_token line from
        config/config.php afterwards.
      </p>
      <?php } ?>

      <p class="login-back">
        <a class="link" href="<?= e($this->url('/')) ?>">&#8592; Back to the website</a>
      </p>
    </div>
  </main>
</div>
