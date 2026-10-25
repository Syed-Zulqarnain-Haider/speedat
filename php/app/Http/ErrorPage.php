<?php

declare(strict_types=1);

namespace Speedat\Http;

use Speedat\Core\App;
use Speedat\Core\Bootstrap;
use Speedat\Core\HttpError;
use Speedat\Core\Log;
use Speedat\Core\Response;
use Speedat\Domain\Seo\Url;
use Speedat\View\ErrorView;

/**
 * The 404 and the 500, in the site's own layout.
 *
 * WHY THIS EXISTS RATHER THAN `Bootstrap::errorPage()`. That one renders
 * `errors/error`, the plain template that has to work when nothing else does,
 * and it is still the floor underneath this. A visitor who mistypes a URL
 * deserves the real site - the same header, nav, footer and JSON-LD as every
 * page, and two ways out (the calculator and WhatsApp) - which is what
 * `View\ErrorView` and `site/error` draw.
 *
 * THREE RULES IT MAY NOT BREAK:
 *
 *  1. THE STATUS IS REAL. A 404 that answers 200 teaches a search engine that
 *     the page exists; a 500 that answers 200 teaches a monitor that nothing is
 *     wrong. The template's own note says the same.
 *  2. IT LEAKS NOTHING. No path, no query, no SQL, no driver message, no stack
 *     trace - on any status. The detail is logged where it is useful and unread
 *     from outside. The requested path is not even reflected into the nav (see
 *     `Page::forError()`).
 *  3. IT CANNOT ITSELF FAIL. Rendering the designed page reads the published
 *     document, which means it can fail in exactly the way that produced the
 *     error. So: designed page, else the plain template, else one line of text.
 *     A white screen is not on the list.
 *
 * A request that wanted JSON - or anything under `/api` - gets the project's
 * envelope instead, `{"error": {"code": "...", "message": "..."}}`, because a
 * machine client handed an HTML page has to guess what happened.
 */
final class ErrorPage
{
    /** The machine half of the site. Must be one of `Seo\Routes::DISALLOWED`. */
    private const API_PREFIX = '/api';

    /**
     * Status to the envelope's `code`. One word per status, stable for clients.
     *
     * 419 is gone from this table because nothing answers 419 any more: an
     * unregistered status is turned into a 500 on the wire by Apache, and the
     * measurement and the reasoning are in `Core\HttpError::invalidToken()`. A
     * rejected token answers 403 and carries `invalid_token` on the error
     * instead - see code() at the bottom of this file, which is what keeps that
     * word in the envelope for the clients that read it.
     */
    private const CODES = [
        400 => 'bad_request',
        401 => 'unauthorized',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        413 => 'payload_too_large',
        429 => 'rate_limited',
        503 => 'unavailable',
    ];

    /**
     * @param HttpError|null $error the thrown error, when there was one. Its
     *                              `detail` is logged and never rendered; a
     *                              caught `Throwable` passes null, because
     *                              nothing about it is safe to show.
     */
    public static function render(App $app, int $status, ?HttpError $error = null): Response
    {
        if ($error !== null && $error->detail !== []) {
            $level = $status >= 500 ? 'error' : 'warn';
            Log::{$level}('http_error', ['status' => $status, 'path' => $app->request->path] + $error->detail);
        }

        $message = self::message($status, $error);

        $response = self::wantsJson($app)
            ? Response::json(['error' => ['code' => self::code($status, $error), 'message' => $message]], $status)
            : self::designed($app, $status, $message);

        $response->noStore();

        // A 405 without an Allow header is a 405 a client cannot act on.
        $allow = $error?->detail['allow'] ?? null;
        if ($status === 405 && is_string($allow)) {
            $response->header('Allow', $allow);
        }

        if ($status === 429 && $error !== null && $error->retryAfter() > 0) {
            $response->header('Retry-After', (string) $error->retryAfter());
        }

        return $response;
    }

    /**
     * The designed page, with the two fallbacks behind it.
     *
     * 404 gets "Page not found"; everything else gets "Something went wrong" -
     * including 405, 419 and 429, because `ErrorView` has two states and
     * inventing a third here would put copy in a controller. The status is what
     * distinguishes them to a machine, and the sentence a visitor needs in all
     * three cases is the same: this did not work, here are two ways to reach us.
     */
    private static function designed(App $app, int $status, string $message): Response
    {
        try {
            $notFound = $status === 404;
            $page     = Page::forError($app, $notFound ? ErrorView::NOT_FOUND_TITLE : ErrorView::ERROR_TITLE);
            $view     = $notFound ? ErrorView::notFound($page) : ErrorView::failed($page);

            return $app->page('site/error', ['view' => $view], $status);
        } catch (\Throwable $e) {
            Log::exception('error_page_failed', $e, ['status' => $status]);
        }

        try {
            // The plain template. It needs no document and no rate card.
            return Bootstrap::errorPage($app, $status, $message, null);
        } catch (\Throwable $e) {
            Log::exception('plain_error_page_failed', $e, ['status' => $status]);
        }

        return Response::text($message . "\n", $status);
    }

    /**
     * Whether the caller wants the envelope: it said so in Accept or
     * Content-Type, or it is talking to a machine endpoint.
     *
     * The path test is not cosmetic. A browser following a stale link to
     * `/api/quotes` sends `Accept: text/html`, and answering a JSON endpoint
     * with a rendered page is how a client ends up parsing HTML for an error
     * code.
     */
    private static function wantsJson(App $app): bool
    {
        if ($app->request->wantsJson()) {
            return true;
        }

        // `/api` is one of `Seo\Routes::DISALLOWED`, and the machine half of
        // it: `/admin` is the other, and an admin 404 is a page for a person.
        // Normalised first, so `//api/quotes` is recognised as the same thing
        // the router will hand to the same handler.
        $path = Url::normalise($app->request->path);

        return $path === self::API_PREFIX || str_starts_with($path, self::API_PREFIX . '/');
    }

    /** The one sentence a visitor or a client is told. Never the exception's. */
    private static function message(int $status, ?HttpError $error): string
    {
        if ($error !== null) {
            // HttpError's message is the public half by construction.
            return $error->getMessage();
        }

        return $status === 404 ? 'That page does not exist.' : 'Something went wrong at our end.';
    }

    /**
     * The envelope's `code`.
     *
     * The status alone is no longer enough to name what happened, and that is
     * the deliberate cost of the fix above it. A rejected form token was the
     * only thing answering 419, so `invalid_token` could be read straight off
     * the status; it now answers 403 like the other refusals. The specific word
     * therefore travels ON the error, in `detail['code']`, with the
     * status-derived word as the floor under it - so a client that was reading
     * `invalid_token` still reads `invalid_token`.
     *
     * `detail` is author-written: it is built by the `HttpError` factories and
     * their callers and never from a request. This still validates what it
     * finds, because `detail` is also the bag every caller drops context into,
     * and a stray value in there must not be able to become the envelope.
     */
    private static function code(int $status, ?HttpError $error): string
    {
        $carried = $error?->detail['code'] ?? null;

        if (is_string($carried) && preg_match('/^[a-z][a-z0-9_]{1,40}$/', $carried) === 1) {
            return $carried;
        }

        return self::CODES[$status] ?? ($status >= 500 ? 'server_error' : 'bad_request');
    }
}
