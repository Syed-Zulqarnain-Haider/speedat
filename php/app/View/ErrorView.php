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
    ) {
    }

    public static function notFound(PageView $page): self
    {
        return new self($page, self::NOT_FOUND_TITLE, self::NOT_FOUND_LINE, self::wa($page->doc));
    }

    public static function failed(PageView $page): self
    {
        return new self($page, self::ERROR_TITLE, self::ERROR_LINE, self::wa($page->doc));
    }

    private static function wa(Doc $doc): string
    {
        return $doc->company()->whatsapp === '' ? '' : $doc->waHref();
    }
}
