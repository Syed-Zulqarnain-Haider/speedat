<?php

declare(strict_types=1);

/**
 * The page shell. Phase 3 brings the v3 design across from src/styles/*.css
 * and this file links those stylesheets - it links them already, and skips any
 * that are not in public/assets/css yet, so nothing 404s in the meantime.
 *
 * @var \Speedat\Core\View $this
 * @var string             $content  rendered page body
 * @var string             $title
 * @var string             $description
 * @var string             $appName
 * @var string             $year
 */

$title       = isset($title) && is_string($title) && $title !== '' ? $title : 'Speedat International Courier';
$description = isset($description) && is_string($description) ? $description : 'International courier from Lahore. Live rates, honest delivery estimates.';
$stylesheets = ['css/tokens.css', 'css/site.css', 'css/calculator.css'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e($description) ?>">
<meta name="robots" content="index, follow">
<?php foreach ($stylesheets as $sheet) { ?>
<?php if ($this->assetExists($sheet)) { ?>
<link rel="stylesheet" href="<?= e($this->asset($sheet)) ?>">
<?php } ?>
<?php } ?>
<?php if ($this->assetExists('css/print.css')) { ?>
<link rel="stylesheet" href="<?= e($this->asset('css/print.css')) ?>" media="print">
<?php } ?>
<?php if ($this->assetExists('img/favicon.ico')) { ?>
<link rel="icon" href="<?= e($this->asset('img/favicon.ico')) ?>">
<?php } ?>
</head>
<body>
<?= $this->partial('partials/header') ?>
<main id="main">
<?= $content ?>
</main>
<?= $this->partial('partials/footer') ?>
</body>
</html>
