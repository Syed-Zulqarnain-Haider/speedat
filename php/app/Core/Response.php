<?php

declare(strict_types=1);

namespace Speedat\Core;

/**
 * What goes back. Built in full before anything is sent, so a failure
 * half-way through rendering still produces a clean error page instead of a
 * half-written one - PHP's output buffer is not a transaction, and a page
 * that has already started streaming cannot be taken back.
 */
final class Response
{
    /** @var array<string,string> header name (as sent) => value */
    private array $headers = [];

    /** See allowFirebaseAuth(). */
    private bool $firebaseAuth = false;

    private function __construct(
        public readonly int $status,
        private readonly string $body,
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return (new self($status, $body))->header('Content-Type', 'text/html; charset=utf-8');
    }

    public static function text(string $body, int $status = 200): self
    {
        return (new self($status, $body))->header('Content-Type', 'text/plain; charset=utf-8');
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return (new self($status, $encoded === false ? '{}' : $encoded))
            ->header('Content-Type', 'application/json; charset=utf-8');
    }

    /**
     * 303 by default: after a POST the browser must follow with a GET, so the
     * back button cannot resubmit an order.
     *
     * Only a same-origin absolute path is accepted - see sameOriginPath(). An
     * open redirect is a phishing tool, and this is the single place in the
     * application that could become one.
     */
    public static function redirect(string $location, int $status = 303): self
    {
        return (new self($status, ''))->header('Location', self::sameOriginPath($location));
    }

    /**
     * The only kind of value this class will ever put in a `Location`: an
     * absolute path on this origin. Anything else becomes "/".
     *
     * WHY THIS IS A METHOD WITH A DOCBLOCK AND NOT SIX LINES INSIDE redirect().
     * The six lines it replaces read as a guard and were not one. An
     * unauthenticated GET of `https://speedat.com.pk/%5Cattacker.example/` was
     * answered with `Location: /\attacker.example` and every current browser
     * landed on the attacker's page - a link carrying the real brand and the
     * real domain, needing no session, no form and no JavaScript. WhatsApp is
     * this business's main channel and it renders the speedat.com.pk host in
     * the link preview.
     *
     * THE THREE THINGS THAT WERE WRONG, and the rule that closes each:
     *
     *   1. Only "//" was known to be an authority. Per the WHATWG URL standard
     *      a BACKSLASH is a path separator too for http(s), so "/\evil.example"
     *      parses as the authority "evil.example". Rule 4 refuses a backslash
     *      anywhere in the value: no path this site issues contains one, and
     *      every browser reads it as a slash.
     *
     *   2. The guard ran BEFORE the sanitiser. header() strips CR and LF after
     *      this decision, so "/\n/evil.example" passed as "starts with one
     *      slash" and was then emitted as "//evil.example". Rule 2 therefore
     *      REFUSES a control character instead of stripping it: the decision is
     *      made on the exact bytes that were handed in, so nothing header()
     *      does afterwards can change what was approved. Stripping would also
     *      invent a path nobody asked for - "/x\0y" becoming "/xy" is a guess -
     *      and "/" is the honest answer to both a bug and an attack.
     *
     *   3. Nothing bounded the length. A Location is a header, and an unbounded
     *      header value is a footgun; the longest path this site can produce is
     *      a canonical plus a query string, both already capped at 1000 by
     *      Http\Kernel.
     *
     * WHY THE HEADER STAYS ORIGIN-RELATIVE rather than being built as
     * `app.url . $path`. An absolute path is resolved by the browser against
     * the origin the request arrived on, so it CANNOT name another host - which
     * is the same-origin property we need, by construction, with no
     * configuration to get wrong. Pinning the header to `app.url` instead would
     * make every redirect on a www/apex mismatch, a staging copy on another
     * hostname, or the temporary Hostinger preview domain bounce the visitor
     * off the site they were on; Core\Csrf::originOk() carries the same
     * reasoning for the same reason. The path is still validated as strictly as
     * if it were being pasted onto a host, because one day it might be.
     */
    public static function sameOriginPath(string $location): string
    {
        // 1. An absolute path, and nothing else. This is what refuses
        //    "https://evil.example", "evil.example", "javascript:alert(1)",
        //    "\\evil.example" and "" - anything that does not begin with the
        //    one character that makes a value a path on THIS origin.
        if ($location === '' || $location[0] !== '/') {
            return '/';
        }

        // 2. Refused, not stripped - see the docblock. \x00-\x20 covers CR, LF,
        //    TAB, NUL and the space; \x7F is DEL. Bytes >= 0x80 are left alone:
        //    they cannot delimit an authority, and a path with an Urdu segment
        //    in it is a path, not an attack.
        if (preg_match('/[\x00-\x20\x7F]/', $location) === 1) {
            return '/';
        }

        // 3. "//host" is an authority in every URL parser there has ever been.
        if (($location[1] ?? '') === '/') {
            return '/';
        }

        // 4. And so is "/\host", to every browser. Refused anywhere in the
        //    value, not just at position 1, so "/a/\/evil.example" dies here
        //    too.
        if (str_contains($location, '\\')) {
            return '/';
        }

        // 5. A cap, so a caller's runaway concatenation cannot become a header.
        if (strlen($location) > 2000) {
            return '/';
        }

        return $location;
    }

    public static function empty(int $status = 204): self
    {
        return new self($status, '');
    }

    public function header(string $name, string $value): self
    {
        // A header value may not carry a newline: that would let a caller
        // append headers of its own.
        $this->headers[$name] = trim(str_replace(["\r", "\n"], '', $value));

        return $this;
    }

    public function hasHeader(string $name): bool
    {
        foreach (array_keys($this->headers) as $existing) {
            if (strcasecmp($existing, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    /** Anything personalised, priced or authenticated. The default for every HTML page. */
    public function noStore(): self
    {
        return $this->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Pragma', 'no-cache');
    }

    /**
     * Mark this response as the page that runs Google sign-in, so Core\App
     * widens ITS Content-Security-Policy - and nobody else's - by the Firebase
     * SDK's sources (Domain\Auth\FirebaseBrowser::cspSources()) and the
     * optional security.csp_* extras.
     *
     * A marker on the response rather than a header a handler writes
     * (2026-10-26): the policy is still decided in one place, from config,
     * and a handler can only say "I am the sign-in page", never which hosts
     * to trust. Only Http\Admin\LoginController calls it. An error page that
     * replaces the login response is a new Response and keeps the plain
     * policy.
     */
    public function allowFirebaseAuth(): self
    {
        $this->firebaseAuth = true;

        return $this;
    }

    public function allowsFirebaseAuth(): bool
    {
        return $this->firebaseAuth;
    }

    public function cacheFor(int $seconds): self
    {
        return $this->header('Cache-Control', 'public, max-age=' . max(0, $seconds));
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * The status codes a web server is known to have a reason phrase for.
     *
     * This is Apache 2.4's own `status_lines[]` table, which is the set
     * `ap_index_of_response()` can find - and LiteSpeed, which is what
     * Hostinger runs, emulates Apache's semantics. Everything in it is an
     * IANA-registered code; 418 is deliberately absent because Apache's table
     * does not carry it either.
     *
     * @var list<int>
     */
    public const TRANSPORTABLE = [
        100, 101, 102, 103,
        200, 201, 202, 203, 204, 205, 206, 207, 208, 226,
        300, 301, 302, 303, 304, 305, 306, 307, 308,
        400, 401, 402, 403, 404, 405, 406, 407, 408, 409, 410, 411, 412, 413, 414, 415, 416, 417,
        421, 422, 423, 424, 425, 426, 428, 429, 431, 451,
        500, 501, 502, 503, 504, 505, 506, 507, 508, 510, 511,
    ];

    /** Is this a status the server is known to be able to put on the wire as itself? */
    public static function isTransportable(int $status): bool
    {
        return in_array($status, self::TRANSPORTABLE, true);
    }

    /**
     * The status line to write by hand, or null when `http_response_code()`
     * alone is the right call.
     *
     * WHY THIS EXISTS. MEASURED ON THE STAGING SERVER (Apache 2.4.68, mod_php
     * 8.2.34, 2026-09-29) with a probe that called `http_response_code($c)` and
     * did nothing else:
     *
     *     asked=400 wire=400    asked=419 wire=500
     *     asked=403 wire=403    asked=498 wire=500
     *     asked=422 wire=422    asked=451 wire=451
     *
     * `http_response_code()` hands the server an integer and no reason phrase.
     * Apache looks that integer up in `status_lines[]` through
     * `ap_index_of_response()`, whose fallback for a code it does not know is
     * the 500 slot - so an UNREGISTERED status goes down the wire as
     * `HTTP/1.1 500 Internal Server Error` with this application's headers and
     * this application's body behind it. Nothing is logged and nothing looks
     * wrong in a browser; every uptime monitor counts a customer's stale form as
     * the site falling over.
     *
     * IT IS NOT ErrorDocument - the site's own 404 page still answers `/nope`,
     * so `ap_die()` is not in play - and it is NOT a header already sent: the
     * application's own `Set-Cookie`, CSP and `Content-Length` all arrive
     * intact on the same response. It is the status-line lookup, alone. `php -S`
     * cannot show it, because the built-in server has no status table and emits
     * whatever integer it is handed.
     *
     * Handing `header()` a full `HTTP/1.1 NNN Phrase` line fixes it under
     * mod_php, which copies the line into `r->status_line`, and under CGI/FPM,
     * which forward it as `Status:`. Proved with the same probe:
     * `header("HTTP/1.1 419 Page Expired")` went down the wire as **419**.
     *
     * THIS IS A NET, NOT THE FIX, and the difference is the reason the
     * application changed status codes as well. In LSAPI mode - which is
     * Hostinger - PHP's litespeed SAPI passes `LSAPI_SetRespStatus(int)` and
     * the phrase written here is dropped, so on the one host that actually
     * matters this cannot be relied on and cannot be measured from here. That is
     * why nothing in this application emits a status outside TRANSPORTABLE any
     * more (see `Core\HttpError::invalidToken()`), and why this method exists
     * anyway: for the day somebody reintroduces one, it will be right on most
     * hosts, and `tests/Core/HttpErrorTest.php` fails before it reaches any.
     *
     * The phrase is the code's CLASS and not an invented name. An unregistered
     * code has no name - nobody, this file included, gets to decide one - and
     * "Client Error" says exactly what is known and nothing that is not.
     */
    public static function statusLine(int $status): ?string
    {
        if (self::isTransportable($status)) {
            return null;
        }

        $phrase = match (intdiv($status, 100)) {
            1       => 'Informational',
            2       => 'Success',
            3       => 'Redirection',
            4       => 'Client Error',
            5       => 'Server Error',
            default => 'Unknown',
        };

        return 'HTTP/1.1 ' . $status . ' ' . $phrase;
    }

    /** Write the response. Called once, by App::send(). */
    public function send(bool $withBody = true): void
    {
        if (!headers_sent()) {
            $line = self::statusLine($this->status);

            if ($line === null) {
                http_response_code($this->status);
            } else {
                // Both at once: the string is what reaches the server's status
                // line, and the third argument is what http_response_code()
                // reports back to anything in PHP that asks afterwards.
                header($line, true, $this->status);
            }

            foreach ($this->headersToSend($withBody) as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }

        if ($withBody) {
            echo $this->body;
        }
    }

    /**
     * Exactly the headers send() would write, in order.
     *
     * Separated from send() because send() can only be observed by starting a
     * web server: header() does nothing under CLI and headers_list() comes
     * back empty, so a unit test has no way to see what was decided here.
     *
     * @return array<string,string>
     */
    public function headersToSend(bool $withBody = true): array
    {
        $headers = $this->headers;

        if ($withBody && !$this->hasHeader('Content-Length') && self::bodyLengthSurvives()) {
            $headers['Content-Length'] = (string) strlen($this->body);
        }

        return $headers;
    }

    /**
     * Is strlen($body) still the number of bytes that will go down the wire?
     *
     * Only when nothing compresses the body after this point. If the host has
     * `zlib.output_compression` on - a php.ini setting, so it can be true on
     * the production host and false on this one - PHP gzips the body on its
     * way out and does NOT correct a Content-Length the application set
     * itself. The browser then reads strlen($body) bytes out of a much shorter
     * compressed stream, never gets them, and renders a truncated page: on
     * that host only, on every page, with nothing in any log to explain it.
     *
     * mod_deflate and LiteSpeed's own compression are not a problem - the
     * server drops the header and switches to chunked when it compresses - so
     * this only has to notice PHP's own two compressors: the ini setting, and
     * an ob_gzhandler on an output buffer.
     *
     * Public so tests/Core/ResponseTest.php can run it in a child process with
     * the ini flag actually on; there is no other honest way to check it. It
     * starts tests/Core/response-child.php under `php -d
     * zlib.output_compression=1`, again with `=4096` (a buffer size, which means
     * ON and which FILTER_VALIDATE_BOOL reads as false - hence the hand-written
     * check below), again with `=Off`, and once with an ob_gzhandler on the
     * stack. ini_set() inside the runner would prove something about a flag
     * rather than about a request.
     */
    public static function bodyLengthSurvives(): bool
    {
        // NOT filter_var(): zlib.output_compression may be a buffer size
        // ("4096"), which means ON, and FILTER_VALIDATE_BOOL reads that as
        // false.
        $zlib = strtolower(trim((string) ini_get('zlib.output_compression')));
        if ($zlib !== '' && $zlib !== '0' && $zlib !== 'off' && $zlib !== 'false') {
            return false;
        }

        foreach (ob_list_handlers() as $handler) {
            if (stripos((string) $handler, 'gz') !== false) {
                return false;
            }
        }

        return true;
    }
}
