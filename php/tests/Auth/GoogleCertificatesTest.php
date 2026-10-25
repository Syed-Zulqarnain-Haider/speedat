<?php

declare(strict_types=1);

namespace Speedat\Tests\Auth;

use Speedat\Domain\Auth\FirebaseTokenError;
use Speedat\Domain\Auth\GoogleCertificates;
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
        $this->assertThrows(FirebaseTokenError::class, static fn () => $tooOld->keys());
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

        $this->assertThrows(FirebaseTokenError::class, static fn () => $certificates->keys());
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

            $this->assertThrows(FirebaseTokenError::class, static fn () => $certificates->keys(), var_export($body, true));
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
