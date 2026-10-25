<?php

declare(strict_types=1);

namespace Speedat\View;

/**
 * The 404 and the 500. Port of `src/components/site/pages/NotFoundBody.tsx`,
 * `src/app/not-found.tsx` and `src/app/(site)/error.tsx`.
 *
 * Both wear the site's own layout - the same header, nav and footer as every
 * customer page - and both give two ways out: the calculator and WhatsApp. No
 * code figure, no accent, nothing centred, and on the 500 nothing at all about
 * what broke: the detail is in storage/logs, where it belongs.
 *
 * The Next.js error page had a "Try again" button, which was a client-side
 * `reset()`. There is nothing to reset in PHP - the way to try again is to ask
 * for the page again - so the 500 renders the same two buttons as the 404 and
 * no dead control.
 *
 * THE LINE UNDER "Something went wrong" IS NOT ALWAYS ERROR_LINE. A refusal
 * the site meant - an expired form token, an owner-only action, a 429, a body
 * too large - carries a sentence written for the person who caused it, and
 * `Http\ErrorPage` hands that sentence in here. Before it did, an owner whose
 * admin tab outlived its CSRF token pressed Publish and was told "This page did
 * not load" under the marketing buttons, with the one instruction that would
 * have fixed it ("Please reload the page and try again") thrown away. Only
 * `Core\HttpError`'s public message ever arrives here, never an exception's.
 */
final class ErrorView
{
    public const NOT_FOUND_TITLE = 'Page not found';
    public const NOT_FOUND_LINE  = 'Nothing is at this address.';
    public const ERROR_TITLE     = 'Something went wrong';
    public const ERROR_LINE      = 'This page did not load.';

    private function __construct(
        public readonly PageView $page,
        public readonly string $title,
        public readonly string $line,
        /** "" hides the green button - a document with no WhatsApp number. */
        public readonly string $waHref,
        /**
         * Site-relative path back into the admin, or "" on a customer page.
         *
         * Set only when the request was under `/admin`: the person reading an
         * admin refusal wants the editor back, not "Get a price". Relative so
         * the template prefixes it with `$this->url()` like every other link.
         */
        public readonly string $adminHref = '',
    ) {
    }

    public static function notFound(PageView $page): self
    {
        return new self($page, self::NOT_FOUND_TITLE, self::NOT_FOUND_LINE, self::wa($page->doc));
    }

    /**
     * @param string $line      the sentence under the heading. ERROR_LINE unless
     *                          the caller has an `HttpError` public message
     *                          that says what to do; "" falls back to it.
     * @param string $adminHref see the property.
     */
    public static function failed(PageView $page, string $line = self::ERROR_LINE, string $adminHref = ''): self
    {
        return new self(
            $page,
            self::ERROR_TITLE,
            $line !== '' ? $line : self::ERROR_LINE,
            self::wa($page->doc),
            $adminHref,
        );
    }

    private static function wa(Doc $doc): string
    {
        return $doc->company()->whatsapp === '' ? '' : $doc->waHref();
    }
}
