<?php

declare(strict_types=1);

/**
 * The customer shell. Port of `src/app/(site)/layout.tsx` plus the root
 * layout's <head>.
 *
 * One header row (the mark, the name, Call and WhatsApp; the page links only
 * from 1024), the page, and a two-line footer: the five page links on one line
 * (on a phone the site's only nav), then one wrapping line of facts with every
 * number a plain link. The theme toggle is not navigation, so it sits beside
 * the nav, not in it.
 *
 * `data-theme` is printed HERE, from the cookie, on the server. That is the
 * whole reason the site never flashes the wrong theme, and it is why nothing
 * in ui.js decides the first paint.
 *
 * The stylesheet ORDER is not negotiable (php/docs/assets.md section 2): base
 * before fonts before tokens before the rest, because each is written against
 * the one before it. The scripts are ordered too - they are `defer`, so they
 * run in document order, and calculator.js needs ui.js's helpers and
 * pricing.js's engine to already be there.
 *
 * @var \Speedat\Core\View     $this
 * @var string                 $content the rendered page body
 * @var \Speedat\View\PageView $page
 */

$sheets = [
    'css/base.css',
    'css/fonts.css',
    'css/tokens.css',
    'css/site.css',
    'css/calculator.css',
    'css/admin.css',
    'css/print.css',
];

// crossorigin is required on a font preload even same-origin, or the file is
// fetched twice. Only the two on the LCP path are preloaded; the mono face and
// the non-latin subsets are gated by unicode-range and never fetched here.
//
// THE PRELOAD MUST NAME THE URL THE STYLESHEET WILL ASK FOR, BYTE FOR BYTE.
// It used to go through asset(), which appends `?v=<filemtime>`; fonts.css
// asks for the bare path, so the browser saw two different URLs, fetched both
// files, and warned that the preloaded one was never used - the preload cost
// a second download instead of saving the first. Fonts are already versioned
// by their filename (barlow-400-latin), so there is nothing for `?v=` to bust
// here: url() is the right helper and asset() is the wrong one.
$preloadFonts = ['fonts/barlow-400-latin.woff2', 'fonts/bigshoulders-var-latin.woff2'];
?>
<!doctype html>
<html lang="en" data-theme="<?= e($page->theme) ?>">
<head>
<meta charset="utf-8">
<?php
/*
 * DEFENSE-IN-DEPTH CSP AS A META TAG, because Hostinger's hcdn edge replaces
 * the Content-Security-Policy response header App.php sets with a bare
 * `upgrade-insecure-requests` (proven: the full policy is on the local dev
 * server's header and gone at the edge). A <meta http-equiv> policy the CDN
 * cannot strip, so the strong policy still reaches the browser; the two
 * intersect, so an injected <script> is blocked even while the header is
 * weakened. frame-ancestors and upgrade-insecure-requests are omitted here on
 * purpose - both are ignored inside a meta CSP, and framing stays covered by
 * X-Frame-Options: DENY, which does survive the edge. The nonce is the same
 * per-request value App.php mints, so the page's own nonced inline scripts
 * keep running and only injected ones are refused.
 */
?>
<meta http-equiv="Content-Security-Policy" content="default-src 'self'; base-uri 'none'; object-src 'none'; form-action 'self'; img-src 'self' data:; font-src 'self'; script-src 'self' 'nonce-<?= e($this->nonce()) ?>'; style-src 'self' 'unsafe-inline'; connect-src 'self'">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="<?= e($page->themeColor()) ?>">
<?php
/*
 * The head has one owner per page, never two. `partials/seo` (the SEO track's)
 * prints the title, every meta and link tag and the JSON-LD graph, so when it
 * runs this layout prints none of those - two titles is worse than none.
 */
switch ($page->headSource()) {
    case 'seo':
        echo $this->partial('partials/seo', ['seo' => $page->seo]);
        break;
    case 'raw':
        // RAW by design: a caller that already has head markup owns its escaping.
        echo $page->headHtml;
        break;
    default:
        echo $this->partial('site/partials/head-fallback', ['page' => $page]);
}
?>
<?php foreach ($preloadFonts as $font) { ?>
<?php if ($this->assetExists($font)) { ?>
<link rel="preload" as="font" type="font/woff2" crossorigin href="<?= e($this->url('assets/' . $font)) ?>">
<?php } ?>
<?php } ?>
<?php foreach ($sheets as $sheet) { ?>
<?php if ($this->assetExists($sheet)) { ?>
<link rel="stylesheet" href="<?= e($this->asset($sheet)) ?>">
<?php } ?>
<?php } ?>
<?php
/*
 * THE TAB ICON IS THE RED S ON THE BRAND YELLOW, not the wordmark and not the
 * old stripe mark. The logo is 4.96 : 1 and turns to mush below about 48px;
 * `bin/local/make-favicons.php` crops the first glyph and sets it on #FFCC00,
 * the same pairing as the header, which is still legible in a crowded tab
 * strip at 16px.
 *
 * ORDER MATTERS: a browser takes the LAST icon it understands, so the .ico
 * (which carries 16, 32 and 48) is declared first and the PNG after it. The
 * `mark.svg` line that used to sit here is GONE rather than reordered - an
 * SVG beats both, and that file is the stripe mark the brand no longer uses,
 * so it was quietly overriding the favicon on every modern browser.
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
<meta name="theme-color" content="#FFCC00">
<?php
/*
 * Without JavaScript the calculator cannot price anything, so the controls
 * that would do nothing are taken off and the way out under them - the green
 * WhatsApp button and the phone number, printed inside <noscript> - is what
 * the visitor sees. The country tiles STAY: their "from PKR ..." is
 * server-rendered and is real information, so they read as the board's
 * summary rather than as dead buttons.
 *
 * It lives in <head> because that is the one place the HTML spec allows a
 * <style> inside <noscript>. The CSP permits it: style-src is
 * 'self' 'unsafe-inline', and no script-src hole is opened by a stylesheet.
 * Every selector is scoped to .instrument, so the rules match nothing on the
 * pages that have no calculator.
 */
?>
<noscript><style>.instrument .kgs,.instrument [data-step="weight"],.instrument .result > .wait{display:none}.instrument .ctile{pointer-events:none;cursor:default}.instrument select.ctile-more{pointer-events:none}</style></noscript>
</head>
<body>
<?= $this->partial('site/partials/sprite') ?>
<div class="site wrap">
<?= $this->partial('site/partials/header', ['page' => $page]) ?>
<main>
<?= $content /* Already-rendered template output; every value inside it went through e(). */ ?>
</main>
<?= $this->partial('site/partials/footer', ['page' => $page]) ?>
<?php
/*
 * The discount popup, inside .site.wrap so the "lift above the sticky price
 * bar" rule in site.css (keyed on a .pricebar being present) can reach it. It
 * renders nothing at all unless the owner has switched the promo on, which is
 * off by default, and it prints its own nonced <script> only when shown.
 */
?>
<?= $this->partial('site/partials/promo', ['page' => $page]) ?>
<?php if ($page->headSource() === 'fallback') { ?>
<?php /* The floor, only when nothing else printed a graph. ejson() carries the same JSON_HEX_* flags as JsonLd::encode(), so a company name containing "</script>" closes nothing. */ ?>
<script type="application/ld+json" <?= $this->nonceAttr() ?>><?= ejson($page->localBusiness()) ?></script>
<?php } ?>
</div>
<?php foreach ($page->scripts as $script) { ?>
<?php if ($this->assetExists($script)) { ?>
<script defer src="<?= e($this->asset($script)) ?>"></script>
<?php } ?>
<?php } ?>
</body>
</html>
