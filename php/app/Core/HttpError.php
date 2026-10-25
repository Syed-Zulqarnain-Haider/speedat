<?php

declare(strict_types=1);

namespace Speedat\Core;

/**
 * An error with an HTTP status attached: the handler throws, the front
 * controller turns it into a page.
 *
 * The `public` message is the only text a visitor ever sees. Anything more
 * specific - which record, which user, which query - goes in `detail`, which
 * is logged and never rendered.
 */
final class HttpError extends \RuntimeException
{
    /** @param array<string,mixed> $detail */
    public function __construct(
        public readonly int $status,
        string $publicMessage,
        public readonly array $detail = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($publicMessage, $status, $previous);
    }

    /** @param array<string,mixed> $detail */
    public static function notFound(array $detail = []): self
    {
        return new self(404, 'That page does not exist.', $detail);
    }

    /** @param array<string,mixed> $detail */
    public static function badRequest(string $message = 'That request could not be understood.', array $detail = []): self
    {
        return new self(400, $message, $detail);
    }

    /**
     * Sign-in required.
     *
     * @param array<string,mixed> $detail
     */
    public static function unauthorized(string $message = 'Please sign in to continue.', array $detail = []): self
    {
        return new self(401, $message, $detail);
    }

    /** @param array<string,mixed> $detail */
    public static function forbidden(string $message = 'You do not have access to that.', array $detail = []): self
    {
        return new self(403, $message, $detail);
    }

    /** @param array<string,mixed> $detail */
    public static function methodNotAllowed(array $detail = []): self
    {
        return new self(405, 'That method is not allowed here.', $detail);
    }

    /**
     * A rejected form token. Usually a stale tab rather than an attack, so the
     * message says what to do about it.
     *
     * 403, AND IT USED TO BE 419. The go-live rehearsal measured every CSRF
     * refusal on the installed copy - an expired contact form, a stale admin
     * login - answering **500 Internal Server Error** over real Apache while
     * the body was the right page with the right sentence on it. The cause is
     * in `Core\Response::statusLine()`: 419 is not in the IANA registry, so a
     * server has no reason phrase for it and Apache's lookup falls back to the
     * 500 slot. The transport net in that method fixes it under mod_php and
     * FPM - but NOT under LSAPI, which is what Hostinger runs and what nobody
     * here can measure, so the status itself had to stop being a guess.
     *
     * WHY 403 AND NOT 400. Nothing about the request is malformed - 400 says
     * the server could not understand it, and this server understood it
     * perfectly and refused it. RFC 9110 §15.5.4 describes 403 as the server
     * understanding the request and refusing to fulfil it, with any supplied
     * credentials judged insufficient, which is precisely a rejected form token
     * or a foreign `Origin`. It is also what Django, Symfony and Spring answer
     * for the same event, so a WAF, a log dashboard and whoever is on call all
     * read it the way it is meant.
     *
     * WHAT 419 WAS CARRYING, AND WHERE IT WENT. It was the only way a machine
     * client could tell "your token is stale, get a fresh page" apart from "you
     * are signed in and may not do this". That distinction is now on the error
     * instead of the status: `code` rides in `detail`, `Http\ErrorPage` prefers
     * it over the status-derived word, and the JSON envelope still answers
     * `invalid_token`. The visitor-facing sentence has not changed at all.
     *
     * @param array<string,mixed> $detail
     */
    public static function invalidToken(array $detail = []): self
    {
        return new self(
            403,
            'This form expired. Please reload the page and try again.',
            $detail + ['code' => 'invalid_token'],
        );
    }

    /** @param array<string,mixed> $detail */
    public static function tooManyRequests(int $retryAfter = 60, array $detail = []): self
    {
        return new self(429, 'Too many requests. Please wait a moment and try again.', $detail + ['retryAfter' => $retryAfter]);
    }

    public function retryAfter(): int
    {
        $v = $this->detail['retryAfter'] ?? null;

        return is_numeric($v) ? (int) $v : 0;
    }
}
