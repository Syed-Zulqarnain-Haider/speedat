<?php

declare(strict_types=1);

/**
 * The layout for an admin page that is NOT inside the signed-in frame: the
 * sign-in screen, and anything else reached without a session.
 *
 * It is not the shell with the nav removed. `.login` is a full-height grid at
 * body level and admin.css styles it outside `.admin-app` on purpose, so a
 * wrapper would change the layout. There is also nothing here to navigate to:
 * a nav on a sign-in page is four links that all bounce back to it.
 *
 * `admin.js` is not loaded - it boots only inside `.admin-app` and there is
 * nothing on these pages for it to decorate.
 *
 * @var \Speedat\Core\View $this
 * @var string             $content
 * @var string             $title
 * @var string             $theme   light|dark, from the theme cookie
 */

$title = isset($title) && is_string($title) && $title !== '' ? $title : 'Sign in';
$theme = isset($theme) && $theme === 'dark' ? 'dark' : 'light';
?>
<!doctype html>
<html lang="en" data-theme="<?= e($theme) ?>">
<head>
<?= $this->partial('admin/partials/head', [
    'title'   => $title,
    'theme'   => $theme,
    'scripts' => ['js/ui.js'],
]) ?>
</head>
<body>
<?= $content ?>
</body>
</html>
