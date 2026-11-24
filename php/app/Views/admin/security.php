<?php

declare(strict_types=1);

/**
 * Security: this admin's second factor, who else can sign in, what the
 * deployment is missing, and the last forty things anyone did. Port of
 * app/admin/security/page.tsx and TotpSetup.tsx.
 *
 * THERE IS NO SHEETS-WEBHOOK CHECK in the deployment list. Nothing arrives by
 * email any more, so a red light for a secret that nothing reads would be an
 * alarm with no off switch. The list is handed in by the caller; this template
 * prints whatever is in it.
 *
 * The enrolment is a THREE-STEP FORM, not a component with state: press Set
 * up, the server mints the secret and re-renders this page with the QR, then
 * the code is posted to confirm it. The secret therefore lives in the session
 * between the two posts and never in a hidden field a browser extension could
 * read - and the whole flow works with JavaScript off.
 *
 * @var \Speedat\Core\View               $this
 * @var \Speedat\Core\Csrf               $csrf
 * @var \Speedat\View\Admin\SecurityPage $page
 * @var \Speedat\View\Admin\Shell        $shell
 */

use Speedat\View\Admin\Routes;

$this->layout('admin/layouts/shell', [
    'title' => 'Security',
    'shell' => $shell,
    'toast' => $page->toast,
]);

$admins    = $page->adminRows();
$activity  = $page->activityRows();
?>
<section class="admin">
  <div class="topbar">
    <div>
      <p class="eyebrow"><?= e($page->eyebrow()) ?></p>
      <h1>Security</h1>
    </div>
    <div class="topbar-right">
      <?php if ($page->checksPass()) { ?>
      <span class="pill live"><?= e($page->checksPill()) ?></span>
      <?php } else { ?>
      <span class="pill hold"><?= e($page->checksPill()) ?></span>
      <?php } ?>
      <span class="meta"><?= e($page->adminsMeta()) ?></span>
    </div>
  </div>

  <div class="grid2 sec-grid">
    <div>
      <?= $this->partial('admin/partials/totp', [
          'page'    => $page,
          'begin'   => Routes::TOTP_BEGIN,
          'confirm' => Routes::TOTP_CONFIRM,
          'disable' => Routes::TOTP_DISABLE,
          'cancel'  => Routes::SECURITY,
      ]) ?>

      <div class="panel">
        <h2 class="step">Who can sign in</h2>
        <?php if ($admins === []) { ?>
        <p class="hint">Nobody is listed. Nobody can sign in.</p>
        <?php } else { ?>
        <ul class="hist admins">
          <?php foreach ($admins as $admin) { ?>
          <li>
            <span class="mono admin-email"><?= e($admin['email']) ?></span>
            <span class="tag role"><?= e(\Speedat\View\Admin\Shell::roleName($admin['role'])) ?></span>
            <?php if ($admin['totp']) { ?>
            <span class="tag">2FA on</span>
            <?php } else { ?>
            <span class="meta">2FA off</span>
            <?php } ?>
            <span class="hint">added <?= e($admin['added']) ?></span>
          </li>
          <?php } ?>
        </ul>
        <?php } ?>
        <p class="hint panel-note">
          Admins live in the <code>admins</code> table and every sign-in is checked against it. Adding or removing one
          is a database change an owner makes deliberately, not a button on this page. Marketers are added and removed
          on the Logins page.
        </p>
      </div>
    </div>

    <div>
      <div class="panel">
        <h2 class="step">Deployment checks</h2>
        <ul class="hist checks">
          <?php foreach ($page->checks as $check) { ?>
          <li class="check <?= $check['ok'] ? 'ok' : 'bad' ?>">
            <span class="check-dot" aria-hidden="true"></span>
            <strong><?= e($check['label']) ?></strong>
            <?php if ($check['ok']) { ?>
            <span class="tag">OK</span>
            <?php } else { ?>
            <span class="meta warn">attention</span>
            <?php } ?>
            <span class="hint"><?= e($check['note']) ?></span>
          </li>
          <?php } ?>
        </ul>
      </div>
      <div class="panel">
        <h2 class="step">Recent activity</h2>
        <?php if ($activity === []) { ?>
        <p class="hint">Nothing recorded yet.</p>
        <?php } else { ?>
        <ul class="hist activity">
          <?php foreach ($activity as $row) { ?>
          <li>
            <span class="meta"><?= e($row['at']) ?></span>
            <strong><?= e($row['action']) ?></strong>
            <span class="meta"><?= e($row['actor']) ?></span>
            <?php if ($row['ip'] !== '') { ?>
            <span class="hint"><?= e($row['ip']) ?></span>
            <?php } ?>
          </li>
          <?php } ?>
        </ul>
        <?php } ?>
      </div>
    </div>
  </div>
</section>
