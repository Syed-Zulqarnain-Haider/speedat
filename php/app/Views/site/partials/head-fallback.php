<?php

declare(strict_types=1);

/**
 * THE FALLBACK <head>, not the real one.
 *
 * The SEO track owns the meta, canonical, Open Graph, Twitter and JSON-LD
 * markup for this site; when it hands `PageView::$headHtml` a rendered string,
 * the layout prints that and this file is never reached. This exists so that a
 * page rendered before that lands is still not shipped without the minimum the
 * quality bar names: a unique title, a description, a canonical URL, the two
 * share cards and a robots directive.
 *
 * WhatsApp is this business's main channel, so the share card matters more
 * than usual - `og:image` is a static asset we ship, with its dimensions
 * declared so the card does not reflow.
 *
 * @var \Speedat\Core\View     $this
 * @var \Speedat\View\PageView $page
 */

$doc  = $page->doc;
$desc = $page->description !== '' ? $page->description : $doc->company()->tagline;

// The canonical is the absolute URL with no query string and no trailing
// slash (the home page keeps its single "/"). The host comes from the request,
// which is why this is the floor and not the ceiling: the SEO track should
// pin it to the configured site URL instead.
$scheme = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off') ? 'https' : 'http';
$host   = is_string($_SERVER['HTTP_HOST'] ?? null) ? $_SERVER['HTTP_HOST'] : '';
$host   = preg_match('/^[A-Za-z0-9.\-:]{1,255}$/', $host) === 1 ? $host : '';
$base   = $host === '' ? '' : $scheme . '://' . $host;
$path   = $page->path === '/' ? '/' : rtrim($page->path, '/');
$canon  = $base . $this->url($path);
$canon  = $canon === '' ? '' : rtrim($canon, '/');
$canon  = $canon === $base ? $base . '/' : $canon;

$image = $this->assetExists('img/mark.svg') ? $base . $this->url('assets/img/mark.svg') : '';
?>
<title><?= e($page->documentTitle()) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<?php
/*
 * Index only what is in the sitemap. A page that reaches this fallback head and
 * is NOT one of the Seo\Routes sitemap pages - the /terms draft the owner is
 * still reviewing, an error page - is told noindex, so "footer-linked but
 * deliberately not announced to crawlers" is honoured by the robots tag too,
 * not just by absence from sitemap.xml. A real sitemap page that ever fell
 * through to this head still says index.
 */
?>
<meta name="robots" content="<?= \Speedat\Domain\Seo\Routes::byPath($page->path) !== null ? 'index, follow' : 'noindex, follow' ?>">
<?php if ($canon !== '') { ?>
<link rel="canonical" href="<?= e($canon) ?>">
<?php } ?>
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e($doc->company()->name) ?>">
<meta property="og:title" content="<?= e($page->documentTitle()) ?>">
<meta property="og:description" content="<?= e($desc) ?>">
<?php if ($canon !== '') { ?>
<meta property="og:url" content="<?= e($canon) ?>">
<?php } ?>
<?php if ($image !== '') { ?>
<meta property="og:image" content="<?= e($image) ?>">
<meta property="og:image:width" content="512">
<meta property="og:image:height" content="512">
<?php } ?>
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= e($page->documentTitle()) ?>">
<meta name="twitter:description" content="<?= e($desc) ?>">
<?php if ($image !== '') { ?>
<meta name="twitter:image" content="<?= e($image) ?>">
<?php } ?>
