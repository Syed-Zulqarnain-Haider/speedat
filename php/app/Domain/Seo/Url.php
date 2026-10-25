<?php

declare(strict_types=1);

namespace Speedat\Domain\Seo;

use Speedat\Core\Config;

/**
 * The site's own address, and the one place that decides what a URL looks like.
 *
 * Everything the SEO layer emits is absolute - canonicals, `og:url`, sitemap
 * entries, the share-card image, every `@id` in the JSON-LD - so every one of
 * them is built here and nowhere else. Two rules, both from
 * `docs/quality-bar.md` §1:
 *
 *   1. ONE URL PER PAGE. No query string, no fragment, no trailing slash. The
 *      home page is the sole exception and is exactly `https://host/`, because
 *      an origin with no path at all is not a URL a sitemap should contain.
 *      `/services/` and `/services?x=1` both canonicalise to `/services`; the
 *      routing layer 301s the other forms (see docs/seo.md).
 *
 *   2. THE BASE COMES FROM CONFIG, NOT FROM THE REQUEST. `app.url` wins
 *      whenever it is set, so a request that arrives with a spoofed Host
 *      header, on the IP, on the www form or on the temporary Hostinger
 *      preview domain still emits the real canonical. The request origin is
 *      used only as a development fallback when `app.url` is empty, and
 *      `isLocal()` lets the sitemap and the tests refuse to publish it.
 *
 * A base may carry a path (`https://host/staging`) so a staging copy in a
 * subdirectory still produces correct absolute URLs.
 */
final class Url
{
    private function __construct(
        /** Scheme + host + optional port + optional base path. Never a trailing slash. */
        private readonly string $base,
        private readonly string $host,
        private readonly string $scheme,
    ) {
    }

    /**
     * @throws SeoError when $base is not an absolute http(s) URL with a host.
     *                  Loud on purpose: see SeoError.
     */
    public static function of(string $base): self
    {
        $trimmed = trim($base);

        if ($trimmed === '') {
            throw new SeoError('The site URL is empty. Set "app.url" in config/config.php to the absolute address of the site, e.g. https://speedat.com.pk');
        }

        $parts = parse_url($trimmed);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new SeoError('The site URL must be absolute and include the scheme, e.g. https://speedat.com.pk');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new SeoError('The site URL must use http or https.');
        }

        $host = strtolower((string) $parts['host']);
        if ($host === '') {
            throw new SeoError('The site URL has no host.');
        }

        // parse_url() hands back an IPv6 literal WITH its brackets - "[::1]" -
        // because that is what belongs in a URL's authority. The brackets stay
        // in the authority below, but `host()` and `isLocal()` want the bare
        // address: an isLocal() that compares against "::1" and is handed
        // "[::1]" silently answers "no", and the guard that exists to stop a
        // development sitemap reaching a crawler stops guarding.
        $bareHost = (str_starts_with($host, '[') && str_ends_with($host, ']'))
            ? substr($host, 1, -1)
            : $host;

        // Credentials in a base URL are always a mistake and would be printed
        // into every canonical tag on the site.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new SeoError('The site URL must not contain a username or password.');
        }

        $authority = $host;
        if (isset($parts['port'])) {
            $port        = (int) $parts['port'];
            $defaultPort = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
            if (!$defaultPort) {
                $authority .= ':' . $port;
            }
        }

        // A base path is kept (subdirectory installs) but never its trailing
        // slash; the query and the fragment of a base URL are meaningless.
        $path = isset($parts['path']) ? rtrim(self::clean((string) $parts['path']), '/') : '';

        return new self($scheme . '://' . $authority . $path, $bareHost, $scheme);
    }

    /**
     * The configured base, or the request's own origin when `app.url` is empty.
     *
     * The fallback exists so the site renders on a laptop and on a fresh host
     * before the domain is pointed; `isLocal()` and the sitemap tests are what
     * stop that fallback ever reaching production.
     */
    public static function fromConfig(string $requestOrigin = ''): self
    {
        $configured = Config::loaded() ? trim(Config::str('app.url')) : '';

        return self::of($configured !== '' ? $configured : $requestOrigin);
    }

    public function base(): string
    {
        return $this->base;
    }

    public function host(): string
    {
        return $this->host;
    }

    public function scheme(): string
    {
        return $this->scheme;
    }

    /**
     * Whether this base is a development address that must never be published.
     *
     * Checked by the sitemap tests and worth checking before a deploy: a
     * sitemap full of `http://localhost:8081/...` is the classic way a staging
     * build teaches a search engine the wrong site.
     */
    public function isLocal(): bool
    {
        // `host` is the bare address: an IPv6 literal has already lost its
        // brackets in `of()`, so "::1" is compared against "::1".
        if ($this->host === 'localhost' || $this->host === '::1' || $this->host === '0.0.0.0' || $this->host === '::') {
            return true;
        }

        if (str_ends_with($this->host, '.localhost') || str_ends_with($this->host, '.test')
            || str_ends_with($this->host, '.local') || str_ends_with($this->host, '.internal')
            || str_ends_with($this->host, '.invalid') || str_ends_with($this->host, '.example')) {
            return true;
        }

        // 127.0.0.0/8, the IPv4 private ranges a laptop or a LAN box sits on,
        // and 169.254.0.0/16 (a machine that failed to get a lease).
        if (preg_match('/^(127\.|10\.|192\.168\.|169\.254\.|172\.(1[6-9]|2\d|3[01])\.)/', $this->host) === 1) {
            return true;
        }

        // IPv6: fc00::/7 (unique local) and fe80::/10 (link local). Both are
        // addresses that only exist inside one network and must never be the
        // host in a published canonical.
        return preg_match('/^(f[cd][0-9a-f]{2}:|fe[89ab][0-9a-f]:)/', $this->host) === 1;
    }

    /**
     * The canonical form of a path: leading slash, no query, no fragment, no
     * trailing slash except at the root, no doubled or dot segments.
     */
    public function path(string $path): string
    {
        return self::normalise($path);
    }

    /**
     * The canonical form of a path, as a static so `Routes` can reach it
     * without a `Url` instance.
     *
     * There is ONE of these on purpose. It used to be copied into
     * `Routes::canonical()` with a comment claiming the two were identical,
     * and they were not: the copy stripped a trailing slash but not an empty
     * or a dot segment, so `Routes::isDisallowed('//admin')` answered "allowed"
     * while `Url::path('//admin')` answered "/admin". A server that maps
     * `//admin/login` onto the admin handler - LiteSpeed and Apache both do -
     * would then have rendered the admin login with `index, follow` in its
     * head. Two normalisers is how that kind of hole opens; there is now one.
     */
    public static function normalise(string $path): string
    {
        $cut = strcspn($path, '?#');
        $out = self::clean(substr($path, 0, $cut));

        return $out === '' ? '/' : $out;
    }

    /** The absolute canonical URL of a path on this site. */
    public function absolute(string $path): string
    {
        $canonical = $this->path($path);

        // The root is the one URL that keeps its slash: "https://host/".
        return $canonical === '/' ? $this->base . '/' : $this->base . $canonical;
    }

    /**
     * Normalise a path: one leading slash, no "." or ".." segments, no empty
     * segments, no trailing slash. Returns "" for the root so callers can tell
     * it apart.
     */
    private static function clean(string $path): string
    {
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return $segments === [] ? '' : '/' . implode('/', $segments);
    }
}
