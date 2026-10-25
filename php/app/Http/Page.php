<?php

declare(strict_types=1);

namespace Speedat\Http;

use Speedat\Core\App;
use Speedat\Core\Clock;
use Speedat\Core\Log;
use Speedat\Domain\Seo\Head;
use Speedat\Domain\Seo\Route;
use Speedat\Domain\Seo\Routes;
use Speedat\Domain\Seo\SeoError;
use Speedat\Domain\Seo\SiteFacts;
use Speedat\Domain\Seo\Url;
use Speedat\Domain\SiteDoc\Live;
use Speedat\View\Doc;
use Speedat\View\PageView;

/**
 * The shell every public page is built on: the published document, the price
 * hold, the head, the theme and the script list.
 *
 * Every controller starts here, so there is exactly one answer to each of the
 * questions a page must not decide for itself:
 *
 *   WHICH DOCUMENT   `Live::site()` and `Live::hold()`, memoised per request,
 *                    so the header, the board and the footer of one page all
 *                    read the same version and the same hold.
 *   WHICH HEAD       `Head::forRoute()` from `Domain\Seo`, never the layout's
 *                    fallback - the fallback exists so a page is never shipped
 *                    with no title, not as something a controller chooses.
 *   WHICH THEME      the "theme" cookie, read on the server. That is the whole
 *                    reason the site never flashes the wrong theme; `ui.js`
 *                    writes the same cookie when the toggle is tapped and
 *                    nothing in the browser decides the first paint. There is
 *                    no POST route for it, because ui.js needs none.
 *   WHICH SCRIPTS    one page gets the calculator's three, the hold page gets
 *                    two of them, and everything else gets `ui.js`.
 *
 * THE HEAD CAN FAIL AND A PAGE MAY NOT. `Url::fromConfig()` throws when
 * `app.url` is empty and the request carries no usable origin - a
 * misconfiguration, and a loud one in the log - but a courier site that answers
 * 500 because a canonical could not be built is worse than one that answers
 * with the layout's fallback head. So the failure is logged once and the page
 * still renders.
 */
final class Page
{
    /**
     * The hold page's scripts. NOT `pricing.js`: while prices are on hold the
     * rate document does not reach the browser at all, so the engine has
     * nothing to price and shipping it would only hand a visitor the rates the
     * hold exists to withhold. calculator.js boots its HoldPanel without it
     * (see its `boot()`).
     *
     * @var list<string>
     */
    public const HOLD_SCRIPTS = ['js/ui.js', 'js/calculator.js'];

    /** The published document and the hold, as the views read them. */
    public static function doc(): Doc
    {
        return Doc::of(Live::site(), Live::hold());
    }

    /**
     * The shell for one of the five public routes.
     *
     * @param string            $key     a `Domain\Seo\Routes` key: home, services, about, contact, faq
     * @param list<string>|null $scripts null means "just ui.js", the default for every page but the quote page
     */
    public static function forKey(App $app, string $key, ?array $scripts = null): PageView
    {
        $doc   = self::doc();
        $route = Routes::byKey($key) ?? Routes::home();

        return self::build($app, $doc, $route, $route->path, $scripts ?? ['js/ui.js']);
    }

    /**
     * The shell for an error page.
     *
     * `Routes::error()` carries `noindex, nofollow` and emits no canonical and
     * no `og:url`, so a 404 is never indexed under the address that produced
     * it. The nav path is deliberately EMPTY rather than the requested one:
     * nothing on an error page should mark a nav item current, and the
     * requested path - which is a stranger's string - then has no route into
     * the markup at all.
     */
    public static function forError(App $app, string $title): PageView
    {
        return self::build($app, self::doc(), Routes::error($title), '', ['js/ui.js']);
    }

    /**
     * The document as the SEO layer reads it.
     *
     * The hold is passed through on purpose: structured data is the copy a
     * search engine reads, so while prices are held it must not keep
     * publishing the price range the page itself has stopped showing.
     */
    public static function facts(Doc $doc): SiteFacts
    {
        return new SiteFacts($doc->data(), $doc->version(), $doc->publishedAt(), $doc->pricesVisible());
    }

    /**
     * The site's own address: `app.url` when it is set, the request's origin
     * only as a development fallback.
     *
     * Null when neither is usable, which is a configuration error the caller
     * has to survive rather than a page it may refuse to serve.
     */
    public static function url(App $app): ?Url
    {
        try {
            return Url::fromConfig($app->request->origin());
        } catch (SeoError $e) {
            Log::warn('seo_url_unconfigured', ['reason' => $e->getMessage()]);

            return null;
        }
    }

    /** @param list<string> $scripts */
    private static function build(App $app, Doc $doc, Route $route, string $navPath, array $scripts): PageView
    {
        $url = self::url($app);
        $seo = $url === null ? null : Head::forRoute(self::facts($doc), $url, $route);

        return new PageView(
            doc: $doc,
            path: $navPath,
            title: $route->title,
            description: $seo?->description() ?? '',
            theme: PageView::themeFromCookie($app->request->cookie('theme')),
            seo: $seo,
            year: (int) Clock::now()->format('Y'),
            scripts: $scripts,
        );
    }
}
