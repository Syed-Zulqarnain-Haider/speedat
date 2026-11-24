<?php

declare(strict_types=1);

/**
 * A marketer's own account: their second factor and nothing about anybody else.
 *
 * @var \Speedat\Core\View               $this
 * @var \Speedat\Core\Csrf               $csrf
 * @var \Speedat\View\Admin\SecurityPage $page
 * @var \Speedat\Http\Admin\AdminUser    $admin
 * @var string                           $theme
 */

use Speedat\View\Admin\Routes;

$this->layout('marketing/layouts/shell', ['title' => 'My account', 'theme' => $theme, 'current' => 'security', 'admin' => $admin]);
?>
<div class="mk-head">
  <div>
    <h1>My account</h1>
    <p class="mk-lede">Signed in as <?= e($page->viewerEmail) ?>.</p>
  </div>
</div>
<?php if ($page->toast !== '') { ?>
<div class="notice ok" role="status"><?= e($page->toast) ?></div>
<?php } ?>
<div class="mk-narrow">
<?= $this->partial('admin/partials/totp', [
    'page'    => $page,
    'begin'   => Routes::MARKETING_TOTP_BEGIN,
    'confirm' => Routes::MARKETING_TOTP_CONFIRM,
    'disable' => Routes::MARKETING_TOTP_DISABLE,
    'cancel'  => Routes::MARKETING_SECURITY,
]) ?>
</div>
