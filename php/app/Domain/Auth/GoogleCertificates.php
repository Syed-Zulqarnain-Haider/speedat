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
 * Past the grace window it fails closed.
 */
final class GoogleCertificates implements CertificateSource
{
    public const URL = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';

    /** Used when the response says nothing useful about caching. */
    private const FALLBACK_MAX_AGE = 3600;

    /** Never trust a max-age longer than this, however generous the header. */
    private const MAX_MAX_AGE = 86400;

    /** @var array<string,string>|null in-process memo, so two verifies in one request read the disk once */
    private ?array $memo = null;

    /**
     * @param string       $cacheDir  absolute path to a writable directory OUTSIDE the web root
     * @param Closure|null $fetcher   fn(string $url): array{body:string, maxAge:int|null}
     *                                - injected by the tests so they never touch the network
     * @param Closure|null $clock     fn(): int returning unix seconds
     */
    public function __construct(
        private readonly string $cacheDir,
        private readonly ?Closure $fetcher = null,
        private readonly ?Closure $clock = null,
        private readonly string $url = self::URL,
        private readonly int $graceSeconds = 86400,
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
            return $this->memo = $cached['keys'];
        }

        try {
            $fresh = $this->fetch();
        } catch (FirebaseTokenError $e) {
            // Google is unreachable. An old set is better than no sign-in at all,
            // within the grace window.
            if ($cached !== null && $cached['fetched_at'] + $this->graceSeconds > $now) {
                return $this->memo = $cached['keys'];
            }

            throw $e;
        }

        $this->writeCache($fresh['keys'], $now, $fresh['maxAge']);

        return $this->memo = $fresh['keys'];
    }

    /** Forget the in-process memo (used by tests, and after a rotation). */
    public function forget(): void
    {
        $this->memo = null;
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
            throw new FirebaseTokenError('Google returned an empty certificate list');
        }

        /** @var mixed $decoded */
        $decoded = json_decode($body, true);

        if (!is_array($decoded) || $decoded === []) {
            throw new FirebaseTokenError('Google certificate list is not a JSON object');
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
            throw new FirebaseTokenError('Google certificate list held no usable certificates');
        }

        $maxAge = $response['maxAge'] ?? null;
        $maxAge = is_int($maxAge) && $maxAge > 0 ? $maxAge : self::FALLBACK_MAX_AGE;

        return ['keys' => $keys, 'maxAge' => min($maxAge, self::MAX_MAX_AGE)];
    }

    /**
     * The real fetcher. cURL because file_get_contents() over HTTPS is disabled
     * by allow_url_fopen=0 on plenty of shared hosts.
     *
     * @return Closure(string): array{body: string, maxAge: int|null}
     */
    public static function curlFetcher(int $timeoutSeconds = 8): Closure
    {
        return static function (string $url) use ($timeoutSeconds): array {
            $handle = curl_init($url);

            if ($handle === false) {
                throw new FirebaseTokenError('cURL is unavailable, so certificates cannot be fetched');
            }

            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER         => true,
                CURLOPT_TIMEOUT        => $timeoutSeconds,
                CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT      => 'speedat-php/1.0',
            ]);

            $raw = curl_exec($handle);

            if (!is_string($raw)) {
                $message = curl_error($handle);
                curl_close($handle);

                throw new FirebaseTokenError('Could not reach Google to fetch certificates: ' . $message);
            }

            $status     = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
            curl_close($handle);

            if ($status !== 200) {
                throw new FirebaseTokenError('Google returned HTTP ' . $status . ' for the certificate list');
            }

            $headers = substr($raw, 0, $headerSize);
            $body    = substr($raw, $headerSize);
            $maxAge  = null;

            if (preg_match('/max-age\s*=\s*(\d+)/i', $headers, $m) === 1) {
                $maxAge = (int) $m[1];
            }

            return ['body' => $body, 'maxAge' => $maxAge];
        };
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
     */
    private function writeCache(array $keys, int $now, int $maxAge): void
    {
        $file = $this->cacheFile();
        $dir  = dirname($file);

        if (!is_dir($dir) && !@mkdir($dir, 0o750, true) && !is_dir($dir)) {
            return; // A cache that cannot be written is slow, not broken.
        }

        $payload = json_encode([
            'fetched_at' => $now,
            'expires_at' => $now + $maxAge,
            'keys'       => $keys,
        ], JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            return;
        }

        // Write-then-rename: a half-written cache file must never be read back
        // as an empty key set by another request landing mid-write.
        $temp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($temp, $payload, LOCK_EX) === false) {
            return;
        }

        @chmod($temp, 0o640);

        if (!@rename($temp, $file)) {
            @unlink($temp);
        }
    }

    private function cacheFile(): string
    {
        // The URL is part of the name so a test URL and the real one cannot
        // share a cache entry.
        return rtrim($this->cacheDir, '/\\') . '/firebase-certs-' . substr(hash('sha256', $this->url), 0, 16) . '.json';
    }

    /**
     * Refuse a cache directory inside the document root. Best effort by design:
     * a domain class does not know where the web root is, so it rejects the
     * shapes that are always wrong rather than pretending to be certain.
     */
    private function refuseWebRoot(string $dir): void
    {
        $normalised = str_replace('\\', '/', $dir);

        if ($normalised === '') {
            throw new FirebaseTokenError('The certificate cache directory must be given');
        }

        foreach (explode('/', trim($normalised, '/')) as $segment) {
            if ($segment === 'public' || $segment === 'public_html' || $segment === 'htdocs' || $segment === 'www') {
                throw new FirebaseTokenError('The certificate cache must not live inside the web root (' . $segment . ')');
            }
        }
    }

    private function now(): int
    {
        $clock = $this->clock;

        return $clock === null ? time() : (int) $clock();
    }
}
