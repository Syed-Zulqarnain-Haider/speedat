<?php

declare(strict_types=1);

/**
 * Logins: who may sign in. Owner only; it adds and removes marketers.
 *
 * @var \Speedat\Core\View            $this
 * @var \Speedat\Core\Csrf            $csrf
 * @var \Speedat\View\Admin\UsersPage $page
 * @var \Speedat\View\Admin\Shell     $shell
 */

use Speedat\View\Admin\Routes;

$this->layout('admin/layouts/shell', ['title' => 'Logins', 'shell' => $shell, 'toast' => $page->toast]);
?>
<section class="admin">
  <div class="topbar">
    <div>
      <p class="eyebrow">Logins</p>
      <h1>Logins</h1>
    </div>
  </div>

  <?php if ($page->error !== '') { ?>
  <div class="notice err" role="alert"><?= e($page->error) ?></div>
  <?php } ?>
  <?php if (!$page->googleReady) { ?>
  <div class="notice warn">Google sign-in is not set up on this website yet, so a marketer you add here cannot sign in. Set up Firebase first; the steps are in the Google sign-in guide.</div>
  <?php } ?>

  <div class="panel">
    <h2 class="step">Add a marketer</h2>
    <p class="hint">A marketer can change the words and search titles on the website, and nothing else: no prices, inbox, shipments or visitor numbers.</p>
    <form method="post" action="<?= e($this->url(Routes::USERS_ADD)) ?>">
      <?= $csrf->field() ?>
      <div class="inline">
        <label class="field">
          <span>Google email</span>
          <input type="email" name="email" maxlength="190" autocomplete="off" required>
        </label>
        <label class="field">
          <span>Name (optional)</span>
          <input type="text" name="name" maxlength="120" autocomplete="off">
        </label>
        <button class="btn primary" type="submit" data-once data-busy-text="Adding&#8230;">Add marketer</button>
      </div>
      <p class="hint">Type it exactly as Google shows it, dots included. They sign in at <?= e($this->url(Routes::LOGIN)) ?> with Continue with Google; there is no password to send.</p>
    </form>
  </div>

  <div class="panel">
    <h2 class="step">Who can sign in</h2>
    <ul class="hist admins">
      <?php foreach ($page->rows() as $user) { ?>
      <li>
        <span class="mono admin-email"><?= e($user['email']) ?></span>
        <span class="tag role"><?= e($user['label']) ?></span>
        <span class="meta">2FA <?= $user['totp'] ? 'on' : 'off' ?></span>
        <span class="hint">added <?= e($user['added']) ?></span>
        <?php if ($user['removable']) { ?>
        <form method="post" action="<?= e($this->url(Routes::USERS_REMOVE)) ?>">
          <?= $csrf->field() ?>
          <input type="hidden" name="email" value="<?= e($user['email']) ?>">
          <button class="btn danger outline small" type="submit" data-confirm="Confirm remove">Remove</button>
        </form>
        <?php } ?>
      </li>
      <?php } ?>
    </ul>
    <p class="hint panel-note">Removing a marketer signs them out straight away. Admins are added and removed in the database on purpose, not here.</p>
  </div>
</section>
