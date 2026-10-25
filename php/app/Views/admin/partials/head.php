<?php

declare(strict_types=1);

/**
 * The `<head>` every admin page shares.
 *
 * It exists so the stylesheet ORDER lives in one file. The seven stylesheets
 * load in the order `src/app/globals.css` pulls them in (php/docs/assets.md 2)
 * and that order is not negotiable - two copies of the list are two chances for
 * the next change to fix one and forget the other.
 *
 * The word is "stylesheet", spelled out, everywhere below. This file used to say
 * "sheet", which is also what the removed Excel rate-sheet feature called its
 * uploads: a grep for the dead feature landed here seven times and found
 * nothing every time, and a sweep that cannot trust its own grep is a sweep that
 * either edits the wrong file or gives up.
 *
 * `data-theme` is printed by the caller, on `<html>`, from the theme cookie.
 * Nothing here is indexable: `noindex, nofollow` on every admin page,
 * including the sign-in.
 *
 * @var \Speedat\Core\View $this
 * @var string             $title
 * @var string             $theme    light|dark
 * @var list<string>       $scripts  asset paths, in load order
 */

$title   = isset($title) && is_string($title) && $title !== '' ? $title : 'Admin';
$theme   = isset($theme) && $theme === 'dark' ? 'dark' : 'light';
$scripts = isset($scripts) && is_array($scripts) ? $scripts : [];

$stylesheets = [
    'css/base.css',
    'css/fonts.css',
    'css/tokens.css',
    'css/site.css',
    'css/calculator.css',
    'css/admin.css',
];
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="<?= $theme === 'dark' ? '#0B1424' : '#FFFFFF' ?>">
<?php foreach ($stylesheets as $stylesheet) { ?>
<?php if ($this->assetExists($stylesheet)) { ?>
<link rel="stylesheet" href="<?= e($this->asset($stylesheet)) ?>">
<?php } ?>
<?php } ?>
<?php if ($this->assetExists('css/print.css')) { ?>
<link rel="stylesheet" href="<?= e($this->asset('css/print.css')) ?>" media="print">
<?php } ?>
<?php
/*
 * The same icon set as the public site - see site/layout.php for why the mark
 * is the red S on yellow rather than the wordmark. The admin gets it too: the
 * owner keeps the rates editor open beside the live site all day, and two
 * unlabelled tabs are two tabs you have to click to tell apart.
 */
?>
<?php if ($this->assetExists('img/favicon.ico')) { ?>
<link rel="icon" href="<?= e($this->asset('img/favicon.ico')) ?>" sizes="any">
<?php } ?>
<?php if ($this->assetExists('img/favicon-32.png')) { ?>
<link rel="icon" type="image/png" sizes="32x32" href="<?= e($this->asset('img/favicon-32.png')) ?>">
<?php } ?>
<?php if ($this->assetExists('img/favicon-180.png')) { ?>
<link rel="apple-touch-icon" href="<?= e($this->asset('img/favicon-180.png')) ?>">
<?php } ?>
<?php foreach ($scripts as $script) { ?>
<?php if (is_string($script) && $this->assetExists($script)) { ?>
<script defer src="<?= e($this->asset($script)) ?>"></script>
<?php } ?>
<?php } ?>
