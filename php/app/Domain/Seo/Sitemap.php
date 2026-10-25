<?php

declare(strict_types=1);

namespace Speedat\Domain\Seo;

/**
 * /sitemap.xml - the five public pages, nothing else.
 *
 * `lastmod` is the published version's timestamp, not "now". A sitemap that
 * claims every page changed today is a sitemap a crawler stops believing, and
 * on this site there is exactly one event that changes what a page says: the
 * owner publishing a new version of the document. Before the first publish
 * there is no honest date, so `lastmod` is omitted entirely rather than
 * invented (see `SiteFacts::lastModified()`).
 *
 * URLs are absolute and come from `Url`, so the host is `app.url` and never
 * the request's Host header. Element order is loc, lastmod, changefreq,
 * priority, which is what the sitemap 0.9 schema requires - `SitemapTest`
 * validates the output against that schema rather than trusting this comment.
 */
final class Sitemap
{
    public const NAMESPACE_URI = 'http://www.sitemaps.org/schemas/sitemap/0.9';
    public const CONTENT_TYPE  = 'application/xml; charset=UTF-8';

    public function __construct(
        private readonly SiteFacts $facts,
        private readonly Url $url,
    ) {
    }

    public function document(): TextDocument
    {
        return new TextDocument($this->xml(), self::CONTENT_TYPE);
    }

    public function xml(): string
    {
        $lastmod = $this->facts->lastModified();

        $out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $out .= '<urlset xmlns="' . self::NAMESPACE_URI . '">' . "\n";

        foreach (Routes::all() as $route) {
            // Belt and braces: a route that is unindexable, or that somebody
            // later files under /admin, never reaches the sitemap.
            if (!$route->indexable || Routes::isDisallowed($route->path)) {
                continue;
            }

            $out .= '  <url>' . "\n";
            $out .= '    <loc>' . self::x($this->url->absolute($route->path)) . '</loc>' . "\n";

            if ($lastmod !== null) {
                $out .= '    <lastmod>' . self::x($lastmod) . '</lastmod>' . "\n";
            }

            $out .= '    <changefreq>' . self::x($route->changeFrequency) . '</changefreq>' . "\n";
            $out .= '    <priority>' . number_format($route->priority, 1, '.', '') . '</priority>' . "\n";
            $out .= '  </url>' . "\n";
        }

        return $out . '</urlset>' . "\n";
    }

    /** XML text escaping. & < > " ' - all five, so no owner-typed character can malform the document. */
    private static function x(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
