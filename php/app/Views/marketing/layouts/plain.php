<?php

declare(strict_types=1);

/**
 * The marketers' page outside the signed-in frame: the sign-in.
 *
 * @var \Speedat\Core\View $this
 * @var string             $content
 * @var string             $title
 * @var string             $theme
 */

$theme = isset($theme) && $theme === 'dark' ? 'dark' : 'light';
?>
<!doctype html>
<html lang="en" data-theme="<?= e($theme) ?>">
<head>
<?= $this->partial('admin/partials/head', ['title' => $title, 'theme' => $theme, 'scripts' => ['js/ui.js']]) ?>
<link rel="stylesheet" href="<?= e($this->asset('css/marketing.css')) ?>">
</head>
<body class="mk-body">
<?= $content ?>
</body>
</html>
