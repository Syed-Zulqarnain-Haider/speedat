<?php

declare(strict_types=1);

namespace Speedat\Http;

use Speedat\Core\App;
use Speedat\Core\HttpError;
use Speedat\Core\Log;
use Speedat\Core\Response;
use Speedat\Core\Router;
use Speedat\Domain\Seo\Url;

/**
 * One request in, one response out.
 *
 * `public/index.php` is wiring and nothing else, so the three decisions that
 * apply to EVERY request live here, where a test can reach them without
 * standing up a web server:
 *
 *  1. THE CANONICAL URL. `/faq/`, `//faq` and `/./faq` are one page, and the
 *     one they are is `/faq` - 301, once, before anything else looks at the
 *     path (docs/seo.md, "The URL policy the routing layer must enforce").
 *  2. ROUTING. `Core\Router` matches, or throws the 404/405.
 *  3. FAILURE. Every throw becomes a designed page or the JSON envelope, and
 *     there is no way out of `handle()` that is not a `Response`.
 *
 * WHY NOT `Bootstrap::run()`. It does the same three things and renders the
 * plain `errors/error` template, which predates the site's own 404. This
 * replaces it for the web front controller and keeps `Bootstrap::errorPage()` as
 * the floor underneath `ErrorPage` (see that class). Nothing else about the
 * bootstrap changes: the handlers, the log line and the fatal-error net are all
 * still `Core\Bootstrap`'s.
 */
final class Kernel
{
    /**
     * Route and render. Returns the response instead of sending it, so the
     * whole stack - redirects, statuses, headers, error pages - is testable
     * from a synthetic request.
     */
    public static function handle(App $app, Router $router): Response
    {
        try {
            $redirect = self::canonicalRedirect($app);
            if ($redirect !== null) {
                return $redirect;
            }

            $match    = $router->match($app->request);
            $handler  = $match['handler'];
            $response = $handler($app, $match['params']);

            if (!$response instanceof Response) {
                throw new \RuntimeException('Route handler did not return a Response.');
            }

            return $response;
        } catch (HttpError $e) {
            return ErrorPage::render($app, $e->status, $e);
        } catch (\Throwable $e) {
            // The one place an unexpected exception is turned into a page. The
            // message, the file, the line and the trace go to the log; the
            // visitor is told that something went wrong at our end and nothing
            // else at all.
            Log::exception('unhandled_error', $e, ['path' => $app->request->path]);

            return ErrorPage::render($app, 500, null);
        }
    }

    /** Route, render, send. What `public/index.php` calls. */
    public static function run(App $app, Router $router): void
    {
        $app->send(self::handle($app, $router));
    }

    /**
     * A 301 to the one URL this page has, or null when this already is it.
     *
     * `Url::normalise()` is the site's single normaliser - the same one
     * `Seo\Routes` and every canonical tag use - so "the address bar", "the
     * canonical tag" and "the sitemap" cannot disagree about what a page's URL
     * is. It strips a trailing slash, collapses `//` and resolves `.` and `..`.
     *
     * TWO THINGS IT DELIBERATELY DOES NOT DO:
     *
     *   - IT NEVER REDIRECTS A POST. A 301 is followed as a GET by every
     *     browser, so redirecting a submitted form would silently turn it into
     *     a page view and lose the message. `Core\Router` normalises the path it
     *     matches on, so `POST /contact/` still reaches the handler.
     *   - IT NEVER REDIRECTS TO A PATH WITH CONTROL CHARACTERS IN IT. A
     *     `Location` header is not a place to echo an arbitrary byte string
     *     back at whoever sent it; those requests fall through to the router
     *     and get an ordinary 404.
     *
     * http to https is NOT here: Hostinger forces it at the edge and a second
     * redirect in PHP is a wasted round trip on every request
     * (docs/hostinger-constraints.md section 8).
     */
    private static function canonicalRedirect(App $app): ?Response
    {
        $request = $app->request;

        if (!$request->isGet()) {
            return null;
        }

        $path      = $request->path;
        $canonical = Url::normalise($path);

        if ($canonical === $path) {
            return null;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $canonical) === 1 || strlen($canonical) > 1000) {
            return null;
        }

        return Response::redirect($request->basePath . $canonical . self::query($request->query), 301);
    }

    /**
     * The query string, rebuilt rather than passed through: `http_build_query()`
     * encodes what it is given, so nothing from the request can add a second
     * `?`, a fragment or a newline to the `Location`.
     *
     * An absurd query is dropped rather than reflected - the canonical page has
     * no use for it, and every parameter the site reads is optional.
     *
     * @param array<string,mixed> $query
     */
    private static function query(array $query): string
    {
        if ($query === []) {
            return '';
        }

        $encoded = http_build_query($query);

        return $encoded === '' || strlen($encoded) > 1000 ? '' : '?' . $encoded;
    }
}
