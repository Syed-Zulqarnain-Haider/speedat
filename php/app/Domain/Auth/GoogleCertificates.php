<?php

declare(strict_types=1);

namespace Speedat\Domain\Auth;

use Closure;

/**
 * Google's public signing certificates, fetched over HTTPS and cached on disk.
 *
 * No service-account JSON and no Admin SDK. Verifying a Firebase ID token needs
 * only PUBLIC keys, and Google publishes them at a fixed URL; the private key
 * never leaves Google. That is what makes this possible on shared hosting with
 * no Composer.
 *
 * THE CACHE IS NOT AN OPTIMISATION, IT IS THE DIFFERENCE BETWEEN A LOGIN THAT
 * TAKES 20 MS AND ONE THAT TAKES A ROUND TRIP TO Google. The response carries
 * `Cache-Control: max-age=...` (typically a few hours); we honour exactly that,
 * as Google asks, and never invent a longer life of our own.
 *
 * WHERE THE CACHE LIVES: a directory the caller passes in, which must be
 * outside the web root. A cached certificate is not secret, but a writable
 * directory under public/ is one upload bug away from being a web shell, and
 * this class refuses to be the thing that creates one.
 *
 * WHEN GOOGLE IS UNREACHABLE: an expired cache is still used, for up to
 * $graceSeconds (24 hours by default). Google keeps a key in the published set
 * for far longer than its max-age, so a stale set still verifies today's
 * tokens - and locking the owner out of his own admin because Google had a bad
 * minute is a worse failure than verifying against a set that is six hours old.
 * Past the grace window it fails closed, with CertificatesUnavailable - "we
 * could not check", which LoginController answers with a 503 rather than the
 * "refused" a bad token gets (2026-10-26). A failed fetch is remembered on disk
 * for a minute (FAIL_BACKOFF), so an outage costs one timeout a minute rather
 * than one per click.
 */
final class GoogleCertificates implements CertificateSource
{
    public const URL = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';

    /** Used when the response says nothing useful about caching. */
    private const FALLBACK_MAX_AGE = 3600;

    /** Never trust a max-age longer than this, however generous the header. */
    private const MAX_MAX_AGE = 86400;

    /**
     * After a failed fetch, how long every request skips the network and goes
     * straight to the grace copy (or the 503).
     *
     * WHY (2026-10-26). An outage used to cost every sign-in attempt a full
     * cURL timeout - eight seconds to connect plus eight to read - because
     * nothing remembered that the last request had just failed the same way.
     * On a shared host that is a PHP worker held per click, and the owner
     * clicking "Continue with Google" again because nothing happened.
     */
    private const FAIL_BACKOFF = 60;

    /**
     * refresh() refetches only a set older than this. Google publishes a new
     * key well before signing with it, so a set this young already has it,
     * and a token naming a kid it lacks is a forgery, not a rotation.
     */
    private const REFRESH_AFTER = 300;

    /** @var array<string,string>|null in-process memo, so two verifies in one request read the disk once */
    private ?array $memo = null;

    /** When the memoised set was fetched from Google, unix seconds. */
    private int $fetchedAt = 0;

    /** Did the last fetch fail to reach the disk cache? See cacheWriteFailed(). */
    private bool $cacheWriteFailed = false;

    /**
     * @param string       $cacheDir  absolute path to a writable directory OUTSIDE the web root
     * @param Closure|null $fetcher   fn(string $url): array{body:string, maxAge:int|null}
     *                                - injected by the tests so they never touch the network
     * @param Closure|null $clock     fn(): int returning unix seconds
     * @param list<string> $webRoots  directories the web server is KNOWN to serve
     *                                (the caller passes Paths::publicDir()); the
     *                                live DOCUMENT_ROOT is always added
     *
     * @throws CertificatesUnavailable when $cacheDir is inside a web root - a
     *                                 configuration error, not a bad token
     */
    public function __construct(
        private readonly string $cacheDir,
        private readonly ?Closure $fetcher = null,
        private readonly ?Closure $clock = null,
        private readonly string $url = self::URL,
        private readonly int $graceSeconds = 86400,
        private readonly array $webRoots = [],
    ) {
        $this->refuseWebRoot($cacheDir);
    }

    public function keys(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $now    = $this->now();
        $cached = $this->readCache();

        if ($cached !== null && $cached['expires_at'] > $now) {
            $this->fetchedAt = $cached['fetched_at'];

            return $this->memo = $cached['keys'];
        }

        return $this->memo = $this->fetchOrFallBack($cached, $now);
    }

    /**
     * See CertificateSource::refresh(). Fetches only when the set in hand is
     * more than REFRESH_AFTER old and no fetch failed in the last minute -
     * between them, a flood of made-up key ids costs Google one request every
     * five minutes at most.
     */
    public function refresh(): array
    {
        $keys = $this->keys();
        $now  = $this->now();

        if ($this->fetchedAt + self::REFRESH_AFTER > $now) {
            return $keys;
        }

        return $this->memo = $this->fetchOrFallBack(['keys' => $keys, 'fetched_at' => $this->fetchedAt], $now);
    }

    /**
     * True when this instance fetched a fresh set and could not write it to
     * the cache directory. Sign-in still works - the set is in hand - but
     * every request is now a round trip to Google, and that used to be
     * completely silent (2026-10-26). LoginController logs
     * `cert_cache_unwritable` when it sees this.
     */
    public function cacheWriteFailed(): bool
    {
        return $this->cacheWriteFailed;
    }

    /** Forget the in-process memo (used by tests, and after a rotation). */
    public function forget(): void
    {
        $this->memo      = null;
        $this->fetchedAt = 0;
    }

    /**
     * Ask Google, or survive not being able to.
     *
     * @param array{keys: array<string,string>, fetched_at: int}|null $cached what is in hand, however old
     *
     * @return array<string,string>
     *
     * @throws CertificatesUnavailable
     */
    private function fetchOrFallBack(?array $cached, int $now): array
    {
        $inGrace = $cached !== null && $cached['fetched_at'] + $this->graceSeconds > $now;

        if ($this->failedRecently($now)) {
            if ($inGrace) {
                $this->fetchedAt = $cached['fetched_at'];

                return $cached['keys'];
            }

            throw new CertificatesUnavailable(
                'Fetching Google certificates failed less than ' . self::FAIL_BACKOFF
                . ' seconds ago and no usable cached set is left; not retrying yet',
            );
        }

        try {
            $fresh = $this->fetch();
        } catch (\Throwable $e) {
            // \Throwable, not FirebaseTokenError: a fetcher that dies with an
            // Error (a missing extension, a TypeError) is still Google being
            // unreachable from here, and must not become a 500.
            $this->markFailure($now);

            // Google is unreachable. An old set is better than no sign-in at
            // all, within the grace window.
            if ($inGrace) {
                $this->fetchedAt = $cached['fetched_at'];

                return $cached['keys'];
            }

            throw $e instanceof CertificatesUnavailable
                ? $e
                : new CertificatesUnavailable('Could not obtain Google certificates: ' . $e->getMessage(), $e);
        }

        $this->clearFailure();
        $this->cacheWriteFailed = !$this->writeCache($fresh['keys'], $now, $fresh['maxAge']);
        $this->fetchedAt        = $now;

        return $fresh['keys'];
    }

    /**
     * @return array{keys: array<string,string>, maxAge: int}
     */
    private function fetch(): array
    {
        $fetcher = $this->fetcher ?? self::curlFetcher();

        /** @var array{body?: mixed, maxAge?: mixed} $response */
        $response = $fetcher($this->url);
        $body     = is_string($response['body'] ?? null) ? $response['body'] : '';

        if ($body === '') {
            throw new CertificatesUnavailable('Google returned an empty certificate list');
        }

        /** @var mixed $decoded */
        $decoded = json_decode($body, true);

        if (!is_array($decoded) || $decoded === []) {
            throw new CertificatesUnavailable('Google certificate list is not a JSON object');
        }

        $keys = [];

        foreach ($decoded as $kid => $pem) {
            // A key id reaches a file name below, so nothing exotic is allowed in.
            if (!is_string($kid) || !is_string($pem) || preg_match('/^[A-Za-z0-9_-]{1,128}$/', $kid) !== 1) {
                continue;
            }

            if (!str_contains($pem, '-----BEGIN CERTIFICATE-----')) {
                continue;
            }

            $keys[$kid] = $pem;
        }

        if ($keys === []) {
            throw new CertificatesUnavailable('Google certificate list held no usable certificates');
        }

        $maxAge = $response['maxAge'] ?? null;
        $maxAge = is_int($maxAge) && $maxAge > 0 ? $maxAge : self::FALLBACK_MAX_AGE;

        return ['keys' => $keys, 'maxAge' => min($maxAge, self::MAX_MAX_AGE)];
    }

    /**
     * The real fetcher. cURL because file_get_contents() over HTTPS is disabled
     * by allow_url_fopen=0 on plenty of shared hosts.
     *
     * Three seconds to connect and five in all (2026-10-26; it was eight and
     * eight). The person waiting is watching a button that says "Waiting for
     * Google", and a host that cannot connect in three seconds is not going to
     * in eight - after which FAIL_BACKOFF stops the next minute's requests from
     * waiting at all.
     *
     * @return Closure(string): array{body: string, maxAge: int|null}
     */
    public static function curlFetcher(int $timeoutSeconds = 5): Closure
    {
        return static function (string $url) use ($timeoutSeconds): array {
            if (!function_exists('curl_init')) {
                throw new CertificatesUnavailable('The PHP cURL extension is not installed, so certificates cannot be fetched');
            }

            $handle = curl_init($url);

            if ($handle === false) {
                throw new CertificatesUnavailable('cURL is unavailable, so certificates cannot be fetched');
            }

            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER         => true,
                CURLOPT_TIMEOUT        => $timeoutSeconds,
                CURLOPT_CONNECTTIMEOUT => min(3, $timeoutSeconds),
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT      => 'speedat-php/1.0',
            ];

            // A host that forces an HTTP proxy prepends the proxy's own
            // "HTTP/1.1 200 Connection established" block. Asked not to, where
            // this libcurl knows how; maxAgeFrom() copes either way.
            if (defined('CURLOPT_SUPPRESS_CONNECT_HEADERS')) {
                $options[CURLOPT_SUPPRESS_CONNECT_HEADERS] = true;
            }

            curl_setopt_array($handle, $options);

            $raw = curl_exec($handle);

            if (!is_string($raw)) {
                $message = curl_error($handle);
                curl_close($handle);

                throw new CertificatesUnavailable('Could not reach Google to fetch certificates: ' . $message);
            }

            $status     = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
            curl_close($handle);

            if ($status !== 200) {
                throw new CertificatesUnavailable('Google returned HTTP ' . $status . ' for the certificate list');
            }

            return [
                'body'   => substr($raw, $headerSize),
                'maxAge' => self::maxAgeFrom(substr($raw, 0, $headerSize)),
            ];
        };
    }

    /**
     * The max-age of the response, read from the Cache-Control line of the
     * FINAL header block only.
     *
     * WHY THIS IS NOT ONE preg_match ANY MORE (2026-10-26). The old pattern
     * took the first "max-age=" anywhere in the raw headers, and
     * `Strict-Transport-Security: max-age=31536000` comes before Cache-Control
     * in Google's response - so the cache life was whatever HSTS said, clamped
     * to a day, instead of the few hours Google asks for. A proxy's CONNECT
     * block or a 100-continue in front would have been read too. So: split on
     * the blank line between blocks, keep the last block with anything in it,
     * and only believe a Cache-Control line.
     */
    public static function maxAgeFrom(string $rawHeaders): ?int
    {
        $blocks = preg_split('/\r?\n\r?\n/', $rawHeaders) ?: [];
        $final  = '';

        foreach ($blocks as $block) {
            if (trim($block) !== '') {
                $final = $block;
            }
        }

        if (preg_match('/^cache-control:[^\r\n]*\bmax-age\s*=\s*(\d+)/im', $final, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    /**
     * @return array{keys: array<string,string>, fetched_at: int, expires_at: int}|null
     */
    private function readCache(): ?array
    {
        $file = $this->cacheFile();

        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        /** @var mixed $data */
        $data = json_decode($raw, true);

        if (!is_array($data) || !is_array($data['keys'] ?? null)) {
            return null;
        }

        $keys = [];

        foreach ($data['keys'] as $kid => $pem) {
            if (is_string($kid) && is_string($pem)) {
                $keys[$kid] = $pem;
            }
        }

        if ($keys === []) {
            return null;
        }

        return [
            'keys'       => $keys,
            'fetched_at' => is_int($data['fetched_at'] ?? null) ? $data['fetched_at'] : 0,
            'expires_at' => is_int($data['expires_at'] ?? null) ? $data['expires_at'] : 0,
        ];
    }

    /**
     * @param array<string,string> $keys
     *
     * @return bool false when the cache could not be written (2026-10-26: it
     *              used to return nothing, so a full disk or a directory owned
     *              by another user made every sign-in a round trip to Google
     *              and nobody could tell)
     */
    private function writeCache(array $keys, int $now, int $maxAge): bool
    {
        $file = $this->cacheFile();

        if (!$this->ensureDir()) {
            return false; // A cache that cannot be written is slow, not broken.
        }

        $payload = json_encode([
            'fetched_at' => $now,
            'expires_at' => $now + $maxAge,
            'keys'       => $keys,
        ], JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            return false;
        }

        // Write-then-rename: a half-written cache file must never be read back
        // as an empty key set by another request landing mid-write.
        $temp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($temp, $payload, LOCK_EX) === false) {
            return false;
        }

        @chmod($temp, 0o640);

        if (!@rename($temp, $file)) {
            @unlink($temp);

            return false;
        }

        return true;
    }

    private function ensureDir(): bool
    {
        $dir = rtrim($this->cacheDir, '/\\');

        return is_dir($dir) || @mkdir($dir, 0o750, true) || is_dir($dir);
    }

    /** Did a fetch fail within FAIL_BACKOFF? Read from disk, so it holds across requests. */
    private function failedRecently(int $now): bool
    {
        $raw = @file_get_contents($this->failFile());

        if (!is_string($raw) || preg_match('/^[0-9]{1,12}$/', trim($raw)) !== 1) {
            return false;
        }

        $age = $now - (int) trim($raw);

        return $age >= 0 && $age < self::FAIL_BACKOFF;
    }

    private function markFailure(int $now): void
    {
        if ($this->ensureDir()) {
            @file_put_contents($this->failFile(), (string) $now, LOCK_EX);
        }
    }

    private function clearFailure(): void
    {
        if (is_file($this->failFile())) {
            @unlink($this->failFile());
        }
    }

    /** Beside the cache file, named the same way, so a test URL has its own. */
    private function failFile(): string
    {
        return substr($this->cacheFile(), 0, -strlen('.json')) . '.fail';
    }

    private function cacheFile(): string
    {
        // The URL is part of the name so a test URL and the real one cannot
        // share a cache entry.
        return rtrim($this->cacheDir, '/\\') . '/firebase-certs-' . substr(hash('sha256', $this->url), 0, 16) . '.json';
    }

    /**
     * Refuse a cache directory inside the document root. Two checks, because a
     * domain class does not know the web root for certain:
     *
     *   KNOWN ROOTS  the directories the caller says are served, plus the live
     *                DOCUMENT_ROOT. Inside one of those is refused whatever the
     *                folders are called. This is the check that is actually sure.
     *   NAMES        a segment called public, public_html or htdocs is a web root
     *                on every panel this has met, so it is refused even when no
     *                root was passed (the CLI, a test, a misconfigured caller).
     *
     * `www` USED TO BE IN THE SECOND LIST, AND THAT BROKE GOOGLE SIGN-IN ON
     * EVERY /var/www INSTALL - the documented staging layout. There `www` is the
     * conventional PARENT of site trees (/var/www/html, /var/www/speedat), not a
     * served directory, so /var/www/speedat/storage/cache was refused and every
     * Google sign-in said "refused" (review, 2026-10-23). It is still refused by
     * name anywhere else (C:\inetpub\www), and a /var/www that really is the
     * document root is caught by the first check.
     */
    private function refuseWebRoot(string $dir): void
    {
        $normalised = str_replace('\\', '/', $dir);

        if ($normalised === '') {
            throw new CertificatesUnavailable('Configuration error: the certificate cache directory must be given');
        }

        $segments = explode('/', trim($normalised, '/'));

        foreach ($segments as $i => $segment) {
            $name = strtolower($segment);

            if ($name === 'public' || $name === 'public_html' || $name === 'htdocs') {
                throw new CertificatesUnavailable('Configuration error: the certificate cache must not live inside the web root (' . $segment . ')');
            }

            if ($name === 'www' && !($i === 1 && strtolower($segments[0]) === 'var')) {
                throw new CertificatesUnavailable('Configuration error: the certificate cache must not live inside the web root (' . $segment . ')');
            }
        }

        $roots = $this->webRoots;
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';

        if (is_string($docRoot) && $docRoot !== '') {
            $roots[] = $docRoot;
        }

        $here = self::resolved($normalised);

        foreach ($roots as $root) {
            if (!is_string($root) || trim($root) === '') {
                continue;
            }

            $root = self::resolved(str_replace('\\', '/', $root));

            if ($root === '/' || $root === '') {
                // A document root of "/" would make every path "inside" it; that
                // is a server nobody should be running, not a reason to lock the
                // owner out.
                continue;
            }

            if ($here === $root || str_starts_with($here . '/', $root . '/')) {
                throw new CertificatesUnavailable('Configuration error: the certificate cache must not live inside the web root (' . $root . ')');
            }
        }
    }

    /**
     * A path with symlinks resolved as far as it exists, so a cache directory
     * that has not been created yet still compares against the real root. On
     * Windows the comparison ignores case, as the filesystem does.
     */
    private static function resolved(string $path): string
    {
        $path = rtrim($path, '/');
        $tail = '';

        while ($path !== '' && !is_dir($path)) {
            $cut = strrpos($path, '/');

            if ($cut === false) {
                break;
            }

            $tail = substr($path, $cut) . $tail;
            $path = substr($path, 0, $cut);
        }

        $real = $path !== '' ? realpath($path) : false;
        $out  = rtrim(str_replace('\\', '/', $real === false ? $path : $real), '/') . $tail;

        return PHP_OS_FAMILY === 'Windows' ? strtolower($out) : $out;
    }

    private function now(): int
    {
        $clock = $this->clock;

        return $clock === null ? time() : (int) $clock();
    }
}
