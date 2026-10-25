<?php

declare(strict_types=1);

namespace Speedat\Domain\Seo;

/**
 * The public route list: the five pages a search engine is allowed to know
 * about, and the two path prefixes it is not.
 *
 * This is the single source for the sitemap, the breadcrumbs and the robots
 * file. It is deliberately a list and not a scan of `app/routes.php`: a
 * sitemap built by reflection over the router would quietly grow whatever the
 * next phase registers - the cron endpoint, a form post, a JSON partial - and
 * "what is in the sitemap" must be a decision somebody wrote down. When a page
 * is added to the site it is added here too, and `SitemapTest` is what notices
 * if the two ever disagree.
 *
 * Titles are the Next.js ones, unchanged, from the page.tsx files under
 * src/app/(site).
 */
final class Routes
{
    /**
     * Path prefixes that are never indexed and never listed.
     *
     * `/admin` is the whole back office. `/api` is every machine endpoint. Both
     * appear in robots.txt as `Disallow` and neither ever appears in the
     * sitemap - RobotsTest and SitemapTest assert both directions, because
     * "excluded" that is only true in one of the two files is not excluded.
     */
    public const DISALLOWED = ['/admin', '/api'];

    /** @return list<Route> in the order the sitemap prints them */
    public static function all(): array
    {
        return [
            new Route(key: 'home', path: '/', title: 'Get a price', breadcrumb: 'Home', changeFrequency: 'daily', priority: 1.0),
            new Route(key: 'services', path: '/services', title: 'Services', breadcrumb: 'Services', changeFrequency: 'weekly', priority: 0.8),
            new Route(key: 'about', path: '/about', title: 'About us', breadcrumb: 'About us'),
            new Route(key: 'contact', path: '/contact', title: 'Contact', breadcrumb: 'Contact'),
            new Route(key: 'faq', path: '/faq', title: 'Questions', breadcrumb: 'Questions'),
        ];
    }

    public static function home(): Route
    {
        return self::all()[0];
    }

    /**
     * The route for a page that is not one of the five: a 404, a 500.
     *
     * It exists so an error page can carry a real head - a title, the share
     * tags, the LocalBusiness - while telling a crawler plainly not to index
     * it. Without it `Route::indexable = false` was a branch nothing could
     * reach, and an error page either had no head at all or borrowed the home
     * page's, which is how a 404 ends up in the index under the home page's
     * canonical.
     *
     * `Meta` emits no canonical and no `og:url` for it (see `Meta::tags()`),
     * so the requested path never reaches the head and an error page issues no
     * indexing instruction that contradicts its own `noindex`.
     */
    public static function error(string $title = 'Page not found'): Route
    {
        return new Route(
            key: 'error',
            path: '/',
            title: $title,
            breadcrumb: $title,
            indexable: false,
        );
    }

    public static function byKey(string $key): ?Route
    {
        foreach (self::all() as $route) {
            if ($route->key === $key) {
                return $route;
            }
        }

        return null;
    }

    /** The route serving this path, matched on the canonical form so "/faq/" finds "/faq". */
    public static function byPath(string $path): ?Route
    {
        $wanted = self::canonical($path);

        foreach (self::all() as $route) {
            if ($route->path === $wanted) {
                return $route;
            }
        }

        return null;
    }

    /** Whether a path is inside one of the disallowed prefixes. */
    public static function isDisallowed(string $path): bool
    {
        $canonical = self::canonical($path);

        foreach (self::DISALLOWED as $prefix) {
            if ($canonical === $prefix || str_starts_with($canonical, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Trailing slash, query, fragment, empty segments and dot segments all
     * removed - by `Url::normalise()`, which is the ONLY implementation of
     * this. It was once duplicated here "identically" and was not: this copy
     * left `//admin` and `/./admin` alone, so `isDisallowed()` called them
     * public and `Meta::robots()` would have put `index, follow` on the admin
     * login if a crawler ever reached it through a doubled slash.
     */
    private static function canonical(string $path): string
    {
        return Url::normalise($path);
    }
}
