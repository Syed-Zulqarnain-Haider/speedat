<?php

declare(strict_types=1);

/**
 * The head, everything a search engine or a WhatsApp scraper reads.
 *
 * The layout includes it once:
 *
 *     <?= $this->partial('partials/seo', ['seo' => $seo]) ?>
 *
 * and then owns nothing else in the head except the charset, the viewport, the
 * stylesheets and the icons. In particular the layout must NOT keep printing
 * its own <title>, description or robots tag: two of each is worse than none,
 * and this partial prints all three.
 *
 * ESCAPING. Every attribute name and every attribute value goes through e().
 * The tags arrive as data (Speedat\Domain\Seo\Tag), never as markup, so there
 * is nothing here that could print a half-escaped tag.
 *
 * THE ONE RAW PRINT is the JSON-LD body, and it is raw because it is already
 * encoded with JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT by
 * JsonLd::encode() - the same flags as the ejson() helper. A "</script>" typed
 * into an FAQ answer comes out as "</script>" and closes nothing.
 * Escaping it again with e() would produce &quot;-riddled JSON that no parser
 * reads. This is the named, reviewed exception the quality bar asks for.
 *
 * The nonce on that element is belt and braces: a script element whose type is
 * not a JavaScript MIME type is a data block, which CSP does not execute and
 * does not block. Carrying the nonce anyway costs nothing and survives a CSP
 * that is tightened later.
 *
 * @var \Speedat\Core\View              $this
 * @var \Speedat\Domain\Seo\Head|null   $seo
 */

use Speedat\Core\Config;
use Speedat\Domain\Seo\Head;

if (!isset($seo) || !$seo instanceof Head) {
    // A page that forgot its head is a bug, not a page. Say so where a
    // developer will see it, and stay silent where a visitor would.
    if (Config::loaded() && Config::isDebug()) {
        echo "<!-- seo: no Speedat\\Domain\\Seo\\Head was passed to partials/seo.php -->\n";
    }

    return;
}
?>
<title><?= e($seo->title()) ?></title>
<?php foreach ($seo->tags() as $tag) { ?>
<<?= e($tag->element) ?><?php foreach ($tag->attributes as $name => $value) { ?> <?= e($name) ?>="<?= e($value) ?>"<?php } ?>>
<?php } ?>
<script type="application/ld+json" <?= $this->nonceAttr() ?>><?= $seo->jsonLd() ?></script>
