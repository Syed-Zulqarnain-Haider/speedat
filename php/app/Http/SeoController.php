<?php

declare(strict_types=1);

namespace Speedat\Http;

use Speedat\Core\App;
use Speedat\Core\Response;
use Speedat\Domain\Seo\Robots;
use Speedat\Domain\Seo\Sitemap;
use Speedat\Domain\Seo\TextDocument;
use Speedat\Domain\Seo\Url;

/**
 * /sitemap.xml and /robots.txt.
 *
 * `Domain\Seo` builds both documents and this decides the three things a
 * document cannot decide for itself - the status, the content type and the
 * caching - which is exactly the split `TextDocument`'s own note asks for.
 *
 * WHAT IS IN THEM IS NOT DECIDED HERE. The five public pages come from
 * `Seo\Routes`, and `/admin` and `/api` are excluded by the one constant
 * (`Routes::DISALLOWED`) that the sitemap filters on and robots.txt disallows -
 * so a prefix cannot be hidden from crawlers in one file and listed in the
 * other. `lastmod` is the published version's timestamp, never "now".
 *
 * THE HOST COMES FROM `app.url`, never from the request's Host header, so a
 * request on the IP or on a preview domain still emits the real canonical
 * (`Url::fromConfig()`). When the base is a development address the response
 * carries `X-Robots-Tag: noindex` - it does not refuse, because a route that
 * cannot be exercised locally is a route nobody checks, and the deploy checklist
 * plus `SitemapTest` are what stop a local base reaching production.
 *
 * CACHED FOR AN HOUR, not no-store: neither file carries a price or anything
 * personal, both change only when the owner publishes, and a crawler that gets
 * them from a cache costs the host nothing.
 */
final class SeoController
{
    private const CACHE_SECONDS = 3600;

    /** GET /sitemap.xml */
    public function sitemap(App $app): Response
    {
        $url = Page::url($app);
        if ($url === null) {
            return self::unconfigured();
        }

        $sitemap = new Sitemap(Page::facts(Page::doc()), $url);

        return self::serve($sitemap->document(), $url);
    }

    /** GET /robots.txt */
    public function robots(App $app): Response
    {
        $url = Page::url($app);
        if ($url === null) {
            return self::unconfigured();
        }

        return self::serve((new Robots($url))->document(), $url);
    }

    private static function serve(TextDocument $document, Url $url): Response
    {
        $response = Response::text($document->body)
            // Response::text() sets text/plain; the document knows better -
            // the sitemap is application/xml and must be served as such or a
            // crawler will not read it.
            ->header('Content-Type', $document->contentType)
            ->cacheFor(self::CACHE_SECONDS);

        if ($url->isLocal()) {
            // A laptop or a staging box. The file is still served so it can be
            // checked; nothing that finds it is invited to index it.
            $response->header('X-Robots-Tag', 'noindex');
        }

        return $response;
    }

    /**
     * `app.url` is empty and the request carried no usable origin. Saying so in
     * one plain sentence is right for a machine endpoint: an XML document with
     * the wrong host in it would be believed.
     */
    private static function unconfigured(): Response
    {
        return Response::text("The site address is not configured yet.\n", 503)->noStore();
    }
}
