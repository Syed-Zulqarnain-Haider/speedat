<?php

declare(strict_types=1);

/**
 * The marketers' frame. Their own area with its own header: nothing of the
 * admin's - no sidebar, no badges, no links to screens they cannot open.
 *
 * @var \Speedat\Core\View                 $this
 * @var \Speedat\Core\Csrf                 $csrf
 * @var string                             $content
 * @var string                             $title
 * @var string                             $theme   light|dark
 * @var string                             $current editor|security
 * @var \Speedat\Http\Admin\AdminUser      $admin
 */

use Speedat\View\Admin\Routes;

$theme = isset($theme) && $theme === 'dark' ? 'dark' : 'light';
?>
<!doctype html>
<html lang="en" data-theme="<?= e($theme) ?>">
<head>
<?= $this->partial('admin/partials/head', [
    'title'   => $title . ' - Speedat Marketing',
    'theme'   => $theme,
    'scripts' => ['js/ui.js', 'js/marketing.js'],
]) ?>
<link rel="stylesheet" href="<?= e($this->asset('css/marketing.css')) ?>">
</head>
<body class="mk-body">
<a class="mk-skip" href="#main">Skip to the page</a>
<header class="mk-top">
  <a class="mk-brand" href="<?= e($this->url(Routes::MARKETING)) ?>" aria-label="Speedat Marketing home">
    <img src="<?= e($this->asset('img/brand/speedat-logo.png')) ?>" srcset="<?= e($this->asset('img/brand/speedat-logo.png')) ?> 1x, <?= e($this->asset('img/brand/speedat-logo@2x.png')) ?> 2x" width="660" height="133" alt="Speedat" decoding="async">
    <span>Marketing</span>
  </a>
  <nav class="mk-nav" aria-label="Marketing">
    <a href="<?= e($this->url(Routes::MARKETING)) ?>"<?= $current === 'editor' ? ' aria-current="page"' : '' ?>>Website text</a>
    <a href="<?= e($this->url(Routes::MARKETING_SECURITY)) ?>"<?= $current === 'security' ? ' aria-current="page"' : '' ?>>My account</a>
  </nav>
  <div class="mk-user">
    <span class="mk-email" title="<?= e($admin->email) ?>"><?= e($admin->label()) ?></span>
    <form method="post" action="<?= e($this->url(Routes::MARKETING_LOGOUT)) ?>">
      <?= $csrf->field() ?>
      <button class="btn small" type="submit">Sign out</button>
    </form>
  </div>
</header>
<main class="mk-main" id="main" tabindex="-1">
<?= $content ?>
</main>
</body>
</html>
