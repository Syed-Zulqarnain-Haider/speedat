<?php

declare(strict_types=1);

namespace Speedat\Core;

/**
 * Cookies are queued while the page is being built and written just before
 * the response goes out, so a handler can set one at any point without caring
 * whether output has started.
 *
 * Every cookie this application sets gets the same four properties and there
 * is no way to opt out of them:
 *   HttpOnly  - JavaScript cannot read the session token, so an XSS bug
 *               cannot walk off with a login
 *   SameSite  - Lax, so a cross-site form post arrives without the session
 *   Secure    - whenever the request came over https
 *   Path=/    - one cookie for the whole site
 */
final class Cookies
{
    /** @var list<array{name:string,value:string,expires:int}> */
    private array $queued = [];

    public function __construct(private readonly bool $secure)
    {
    }

    public static function forRequest(Request $request): self
    {
        // In production the cookie is Secure even if this one request arrived
        // over http: Hostinger redirects http to https, and a session token
        // that has ever been allowed over plaintext is a session token that
        // can be sniffed.
        return new self($request->https || Config::isProduction());
    }

    /** @param int $ttl Seconds from now; 0 means a session cookie that dies with the browser. */
    public function set(string $name, string $value, int $ttl = 0): void
    {
        $this->queued[] = [
            'name'    => $name,
            'value'   => $value,
            'expires' => $ttl > 0 ? Clock::timestamp() + $ttl : 0,
        ];
    }

    public function delete(string $name): void
    {
        $this->queued[] = ['name' => $name, 'value' => '', 'expires' => Clock::timestamp() - 86400];
    }

    /** Write every queued cookie. Called once, by App::send(), before the body. */
    public function flush(): void
    {
        if (headers_sent()) {
            return;
        }

        foreach ($this->queued as $cookie) {
            setcookie($cookie['name'], $cookie['value'], [
                'expires'  => $cookie['expires'],
                'path'     => '/',
                'secure'   => $this->secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        $this->queued = [];
    }

    /** @return list<string> names queued so far. Tests and diagnostics. */
    public function queuedNames(): array
    {
        return array_map(static fn (array $c): string => $c['name'], $this->queued);
    }

    /**
     * Everything queued, values included.
     *
     * Only the server ever sees this. The tests need it because the value a
     * cookie was set to - the session token - is what the NEXT request arrives
     * carrying, and there is no other way to hand it from one synthetic
     * request to the next.
     *
     * @return list<array{name:string,value:string,expires:int}>
     */
    public function queued(): array
    {
        return $this->queued;
    }

    /** The value queued for a name, latest wins, or null when there is none. */
    public function queuedValue(string $name): ?string
    {
        $found = null;

        foreach ($this->queued as $cookie) {
            if ($cookie['name'] === $name) {
                $found = $cookie['value'];
            }
        }

        return $found;
    }

    /** Will these cookies be sent with the Secure flag? */
    public function isSecure(): bool
    {
        return $this->secure;
    }
}
