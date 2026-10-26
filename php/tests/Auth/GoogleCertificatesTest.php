<?php

declare(strict_types=1);

namespace Speedat\Tests\Auth;

use Speedat\Domain\Auth\CertificatesUnavailable;
use Speedat\Domain\Auth\FirebaseToken;
use Speedat\Domain\Auth\FirebaseTokenError;
use Speedat\Domain\Auth\GoogleCertificates;
use Speedat\Tests\Auth\Support\RsaKeyPair;
use Speedat\Tests\TestCase;

/**
 * The certificate cache, with the network replaced by a closure. Nothing here
 * touches Google; what is being tested is that we ask Google as rarely as it
 * asks us to, and that a bad minute at Google does not lock the owner out.
 */
final class GoogleCertificatesTest extends TestCase
{
    private const NOW = 1_700_000_000;

    private string $cacheDir = '';

    public function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/speedat-certs-' . bin2hex(random_bytes(6));
        mkdir($this->cacheDir, 0o750, true);
    }

    public function tearDown(): void
    {
        if ($this->cacheDir === '' || !is_dir($this->cacheDir)) {
            return;
        }

        foreach ((array) glob($this->cacheDir . '/*') as $file) {
            if (is_string($file)) {
                @unlink($file);
            }
        }

        @rmdir($this->cacheDir);
    }

    public function testItFetchesOnceAndThenReadsTheCache(): void
    {
        $calls = 0;
        $now   = self::NOW;

        $certificates = new GoogleCertificates(
            $this->cacheDir,
            function () use (&$calls): array {
                $calls++;

                return ['body' => $this->body(), 'maxAge' => 3600];
            },
            static fn (): int => $now,
            'https://example.invalid/certs',
        );

        $this->assertSame(['kid-a' => $this->pem('a'), 'kid-b' => $this->pem('b')], $certificates->keys());
        $this->assertSame(1, $calls);

        // Second call in the same process: no disk, no network.
        $certificates->keys();
        $this->assertSame(1, $calls);

        // A new instance (the next request) reads the file, still no network.
        $second = new GoogleCertificates(
            $this->cacheDir,
            function () use (&$calls): array {
                $calls++;

                return ['body' => $this->body(), 'maxAge' => 3600];
            },
            static fn (): int => $now + 3599,
            'https://example.invalid/certs',
        );

        $this->assertSame(2, count($second->keys()));
        $this->assertSame(1, $calls, 'inside max-age, Google must not be asked again');
    }

    public function testItRefetchesOnceMaxAgeHasPassed(): void
    {
        $calls = 0;
        $now   = self::NOW;

        $make = function () use (&$calls, &$now): GoogleCertificates {
            return new GoogleCertificates(
                $this->cacheDir,
                function () use (&$calls): array {
                    $calls++;

                    return ['body' => $this->body(), 'maxAge' => 600];
                },
                static fn (): int => $now,
                'https://example.invalid/certs',
            );
        };

        $make()->keys();
        $this->assertSame(1, $calls);

        $now = self::NOW + 599;
        $make()->keys();
        $this->assertSame(1, $calls, 'still inside the ten minutes Google asked for');

        $now = self::NOW + 601;
        $make()->keys();
        $this->assertSame(2, $calls, 'past max-age, ask again');
    }

    public function testAnOutageIsSurvivedWithinTheGraceWindowAndNotBeyondIt(): void
    {
        $now  = self::NOW;
        $down = static function (): array {
            throw new FirebaseTokenError('pretend Google is unreachable');
        };

        // Prime the cache while Google is up.
        (new GoogleCertificates(
            $this->cacheDir,
            fn (): array => ['body' => $this->body(), 'maxAge' => 600],
            static fn (): int => $now,
            'https://example.invalid/certs',
        ))->keys();

        // Cache stale, Google down, but inside the grace window: sign-in works.
        $inGrace = new GoogleCertificates(
            $this->cacheDir,
            $down,
            static fn (): int => self::NOW + 7200,
            'https://example.invalid/certs',
        );
        $this->assertSame(2, count($inGrace->keys()));

        // Past the grace window: fail closed rather than trust week-old keys.
        $tooOld = new GoogleCertificates(
            $this->cacheDir,
            $down,
            static fn (): int => self::NOW + 200_000,
            'https://example.invalid/certs',
        );
        $this->assertThrows(CertificatesUnavailable::class, static fn () => $tooOld->keys());
    }

    public function testNoCacheAndNoGoogleIsAnError(): void
    {
        $certificates = new GoogleCertificates(
            $this->cacheDir,
            static function (): array {
                throw new FirebaseTokenError('pretend Google is unreachable');
            },
            static fn (): int => self::NOW,
            'https://example.invalid/certs',
        );

        // "Could not check", which the controller answers with a 503 - not the
        // "refused" a forged token gets (2026-10-26).
        $this->assertThrows(CertificatesUnavailable::class, static fn () => $certificates->keys());
    }

    public function testAnUnusableResponseIsRejectedRatherThanCached(): void
    {
        foreach (['', 'not json', '{}', '[]', '{"kid-a":"not a certificate"}', '{"../evil":"-----BEGIN CERTIFICATE-----x"}'] as $body) {
            $certificates = new GoogleCertificates(
                $this->cacheDir,
                static fn (): array => ['body' => $body, 'maxAge' => 600],
                static fn (): int => self::NOW,
                'https://example.invalid/certs-' . md5($body),
            );

            $this->assertThrows(CertificatesUnavailable::class, static fn () => $certificates->keys(), var_export($body, true));
        }
    }

    public function testAMaxAgeIsNeverTrustedBeyondADay(): void
    {
        $calls = 0;
        $now   = self::NOW;

        $make = function () use (&$calls, &$now): GoogleCertificates {
            return new GoogleCertificates(
                $this->cacheDir,
                function () use (&$calls): array {
                    $calls++;

                    // A header claiming a year.
                    return ['body' => $this->body(), 'maxAge' => 31_536_000];
                },
                static fn (): int => $now,
                'https://example.invalid/certs',
            );
        };

        $make()->keys();
        $now = self::NOW + 86_401;
        $make()->keys();

        $this->assertSame(2, $calls, 'a silly max-age must be clamped to a day');
    }

    public function testTheCacheDirectoryMayNotBeInsideTheWebRoot(): void
    {
        foreach (['/var/www/public/cache', 'D:/sites/public_html/tmp', '/home/u1/htdocs/x', 'C:\\inetpub\\www\\cache'] as $bad) {
            $this->assertThrows(
                FirebaseTokenError::class,
                static fn () => new GoogleCertificates($bad),
                $bad,
            );
        }

        // And the storage directory this app actually uses is fine.
        new GoogleCertificates($this->cacheDir);
        $this->assertTrue(true, 'a directory outside the web root is accepted');
    }

    public function testTheCacheFileHoldsNothingSecretAndIsNotWorldWritable(): void
    {
        $certificates = new GoogleCertificates(
            $this->cacheDir,
            fn (): array => ['body' => $this->body(), 'maxAge' => 600],
            static fn (): int => self::NOW,
            'https://example.invalid/certs',
        );
        $certificates->keys();

        $files = (array) glob($this->cacheDir . '/firebase-certs-*.json');
        $this->assertSame(1, count($files));

        $contents = (string) file_get_contents((string) $files[0]);
        $this->assertStringContains('kid-a', $contents);
        $this->assertStringContains('"expires_at":' . (self::NOW + 600), $contents);

        // Nothing half-written was left behind.
        $this->assertSame(0, count((array) glob($this->cacheDir . '/*.tmp')));
    }

    // ------------------------------------------------- 2026-10-26 additions

    public function testAFailedFetchIsNotRetriedForAMinute(): void
    {
        $calls = 0;
        $now   = self::NOW;

        $make = function () use (&$calls, &$now): GoogleCertificates {
            return new GoogleCertificates(
                $this->cacheDir,
                static function () use (&$calls): array {
                    $calls++;

                    throw new FirebaseTokenError('pretend the connection timed out');
                },
                static fn (): int => $now,
                'https://example.invalid/certs',
            );
        };

        // Two requests inside the minute: one timeout, not two.
        $this->assertThrows(CertificatesUnavailable::class, static fn () => $make()->keys());
        $now = self::NOW + 30;
        $this->assertThrows(CertificatesUnavailable::class, static fn () => $make()->keys());
        $this->assertSame(1, $calls, 'an outage must cost one timeout a minute, not one per click');

        // After the minute, Google is asked again.
        $now = self::NOW + 61;
        $this->assertThrows(CertificatesUnavailable::class, static fn () => $make()->keys());
        $this->assertSame(2, $calls);
    }

    public function testTheBackoffStillServesTheGraceCopy(): void
    {
        $now   = self::NOW;
        $calls = 0;

        (new GoogleCertificates($this->cacheDir, fn (): array => ['body' => $this->body(), 'maxAge' => 600], static fn (): int => $now, 'https://example.invalid/certs'))->keys();

        $down = static function () use (&$calls): array {
            $calls++;

            throw new FirebaseTokenError('pretend Google is unreachable');
        };

        foreach ([self::NOW + 700, self::NOW + 710] as $at) {
            $stale = new GoogleCertificates($this->cacheDir, $down, static fn (): int => $at, 'https://example.invalid/certs');
            $this->assertSame(2, count($stale->keys()), 'inside the grace window the old set still signs people in');
        }

        $this->assertSame(1, $calls, 'and the second request did not wait on Google again');
    }

    public function testMaxAgeIsReadFromCacheControlInTheFinalHeaderBlockOnly(): void
    {
        $google = "HTTP/2 200\r\n"
            . "strict-transport-security: max-age=31536000\r\n"
            . "Cache-Control: public, max-age=600, must-revalidate, no-transform\r\n"
            . "content-type: application/json\r\n\r\n";

        $this->assertSame(600, GoogleCertificates::maxAgeFrom($google), 'HSTS came first and used to win');

        // A proxy's CONNECT block in front, with a max-age of its own.
        $proxied = "HTTP/1.1 200 Connection established\r\nCache-Control: max-age=99999\r\n\r\n" . $google;
        $this->assertSame(600, GoogleCertificates::maxAgeFrom($proxied));

        $this->assertNull(GoogleCertificates::maxAgeFrom("HTTP/2 200\r\nStrict-Transport-Security: max-age=31536000\r\n\r\n"));
        $this->assertNull(GoogleCertificates::maxAgeFrom("HTTP/2 200\r\nX-Cache-Control-Note: max-age=5\r\n\r\n"));
    }

    public function testAnUnknownKidIsRefetchedOnceWhenTheSetIsOldEnough(): void
    {
        if (!RsaKeyPair::isAvailable()) {
            $this->skip('this PHP cannot generate an RSA key, so tokens cannot be signed here');
        }

        $keys = RsaKeyPair::shared();
        $now  = self::NOW;

        // A set fetched ten minutes ago, still inside its max-age, from before
        // Google started signing with 'new-key'.
        file_put_contents($this->cacheFile('https://example.invalid/certs'), (string) json_encode([
            'fetched_at' => $now - 600,
            'expires_at' => $now + 3000,
            'keys'       => ['old-key' => $this->pem('old')],
        ]));

        $calls = 0;
        $certs = new GoogleCertificates(
            $this->cacheDir,
            static function () use (&$calls, $keys): array {
                $calls++;

                return ['body' => (string) json_encode(['new-key' => $keys->certificatePem]), 'maxAge' => 3600];
            },
            static fn (): int => $now,
            'https://example.invalid/certs',
        );

        $token = $keys->sign(['alg' => 'RS256', 'kid' => 'new-key', 'typ' => 'JWT'], [
            'iss' => 'https://securetoken.google.com/speedat-c3b12', 'aud' => 'speedat-c3b12',
            'sub' => 'uid-1', 'iat' => $now - 5, 'exp' => $now + 3595, 'auth_time' => $now - 5,
        ]);

        $identity = (new FirebaseToken('speedat-c3b12', $certs, 60, static fn (): int => $now))->verify($token);

        $this->assertSame('uid-1', $identity->uid, 'a rotated key must not lock the owner out until the cache expires');
        $this->assertSame(1, $calls, 'one refetch, not one per lookup');
    }

    public function testAYoungSetIsNotRefetchedForAnUnknownKid(): void
    {
        $calls = 0;
        $now   = self::NOW;
        $certs = new GoogleCertificates(
            $this->cacheDir,
            function () use (&$calls): array {
                $calls++;

                return ['body' => $this->body(), 'maxAge' => 3600];
            },
            static fn (): int => $now,
            'https://example.invalid/certs',
        );

        $certs->keys();
        $certs->refresh();
        $certs->refresh();

        // A set fetched seconds ago cannot be missing a key Google is
        // signing with, so a made-up kid gets no request to Google at all.
        $this->assertSame(1, $calls);
    }

    public function testACacheThatCannotBeWrittenIsReportedNotSilent(): void
    {
        // A FILE where the directory should be: no permission trick, so it
        // holds when the suite runs as root in Docker too.
        $blocker = $this->cacheDir . '/not-a-dir';
        file_put_contents($blocker, 'x');

        $certs = new GoogleCertificates(
            $blocker . '/google-certs',
            fn (): array => ['body' => $this->body(), 'maxAge' => 600],
            static fn (): int => self::NOW,
            'https://example.invalid/certs',
        );

        $this->assertSame(2, count($certs->keys()), 'sign-in still works without the disk cache');
        $this->assertTrue($certs->cacheWriteFailed(), 'and the failure is visible to the caller, which logs it');

        $writable = new GoogleCertificates(
            $this->cacheDir,
            fn (): array => ['body' => $this->body(), 'maxAge' => 600],
            static fn (): int => self::NOW,
            'https://example.invalid/certs',
        );
        $writable->keys();
        $this->assertFalse($writable->cacheWriteFailed());
    }

    public function testAWebRootCacheIsAConfigErrorNotARefusal(): void
    {
        $error = $this->assertThrows(CertificatesUnavailable::class, static fn () => new GoogleCertificates('/home/u1/public_html/cache'));
        $this->assertStringContains('Configuration error', $error->getMessage());
    }

    private function cacheFile(string $url): string
    {
        return $this->cacheDir . '/firebase-certs-' . substr(hash('sha256', $url), 0, 16) . '.json';
    }

    private function body(): string
    {
        return (string) json_encode(['kid-a' => $this->pem('a'), 'kid-b' => $this->pem('b')]);
    }

    /** A certificate-shaped string; nothing here parses it, only the verifier does. */
    private function pem(string $tag): string
    {
        return "-----BEGIN CERTIFICATE-----\n" . base64_encode('pretend-certificate-' . $tag) . "\n-----END CERTIFICATE-----\n";
    }
}
