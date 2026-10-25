<?php

declare(strict_types=1);

namespace Speedat\Tests\Auth;

use Speedat\Domain\Auth\AuthError;
use Speedat\Domain\Auth\Qr;
use Speedat\Domain\Auth\Totp;
use Speedat\Tests\TestCase;

/**
 * The QR encoder, checked against a known-good one.
 *
 * WHAT THE FIXTURE IS. tests/Auth/fixtures/qr-reference.json holds the matrices
 * the `qrcode` npm package - the encoder the Next.js app shipped, and one that
 * has been scanned by real phones for years - produces for fourteen inputs, at
 * every version this encoder supports and every one of the eight masks. If the
 * PHP output is identical module for module, then whatever a phone does with
 * the Node output it will do with this one.
 *
 * This is stronger than round-tripping through a decoder of my own writing,
 * which would only prove that two halves of the same misunderstanding agree.
 */
final class QrTest extends TestCase
{
    /** @var array{generator:string, cases:list<array{name:string,textBase64:string,masks:list<array{mask:int,version:int,size:int,rows:list<string>}>}>}|null */
    private static ?array $fixture = null;

    public function testEveryMatrixMatchesTheNpmEncoder(): void
    {
        $fixture = $this->fixture();
        $checked = 0;

        foreach ($fixture['cases'] as $case) {
            $text = (string) base64_decode($case['textBase64'], true);

            foreach ($case['masks'] as $expected) {
                $qr = Qr::encode($text, $expected['mask']);

                $this->assertSame($expected['version'], $qr->version, $case['name'] . ' version');
                $this->assertSame($expected['size'], $qr->size(), $case['name'] . ' size');
                $this->assertSame(
                    implode("\n", $expected['rows']),
                    rtrim($qr->toBitRows(), "\n"),
                    $case['name'] . ' mask ' . $expected['mask'],
                );

                $checked++;
            }
        }

        $this->assertSame(112, $checked, '14 inputs x 8 masks');
    }

    public function testTheMaskItPicksOnItsOwnIsOneOfTheEightVerifiedOnes(): void
    {
        // Mask CHOICE is a quality heuristic, not correctness - the spec's
        // penalty rules only decide which valid symbol is easiest to scan. What
        // must hold is that whatever it picks is one of the eight matrices the
        // reference encoder produces.
        foreach ($this->fixture()['cases'] as $case) {
            $text  = (string) base64_decode($case['textBase64'], true);
            $qr    = Qr::encode($text);
            $mine  = rtrim($qr->toBitRows(), "\n");
            $found = false;

            foreach ($case['masks'] as $expected) {
                if ($expected['mask'] === $qr->mask) {
                    $found = implode("\n", $expected['rows']) === $mine;

                    break;
                }
            }

            $this->assertTrue($found, $case['name'] . ': auto-chosen mask ' . $qr->mask . ' must match the reference');
        }
    }

    public function testARealEnrolmentUriEncodes(): void
    {
        $totp   = new Totp();
        $secret = $totp->newSecret();
        $uri    = $totp->uri($secret, 'owner@speedat.pk');
        $qr     = Qr::encode($uri);

        // An ordinary address gives a ~146-byte URI, which is version 8.
        $this->assertSame(8, $qr->version, 'a 146-byte otpauth URI is a version 8 symbol');
        $this->assertSame($qr->version * 4 + 17, $qr->size());

        // A long address still fits, and an absurd one falls back rather than
        // producing something unscannable. 213 bytes is the ceiling, which
        // leaves room for an address of about 80 characters.
        $long = $totp->uri($secret, 'muhammad.zulqarnain.haider@speedatinternational.com.pk');
        $this->assertSame(10, Qr::encode($long)->version);
        $this->assertNull(Qr::tryEncode($totp->uri($secret, str_repeat('a', 120) . '@speedat.pk')));

        // The three finder patterns are where a scanner looks first.
        foreach ([[0, 0], [0, $qr->size() - 7], [$qr->size() - 7, 0]] as [$row, $col]) {
            $this->assertTrue($qr->isDark($row, $col), 'finder corner');
            $this->assertTrue($qr->isDark($row + 3, $col + 3), 'finder centre');
            $this->assertFalse($qr->isDark($row + 1, $col + 3), 'finder ring gap');
        }

        // The module that is always dark, whatever the data.
        $this->assertTrue($qr->isDark($qr->size() - 8, 8));
    }

    public function testTheVersionGrowsWithTheTextAndStopsWhereItSaysItDoes(): void
    {
        $capacities = [1 => 14, 2 => 26, 3 => 42, 4 => 62, 5 => 84, 6 => 106, 7 => 122, 8 => 152, 9 => 180, 10 => 213];

        foreach ($capacities as $version => $capacity) {
            $this->assertSame($version, Qr::encode(str_repeat('x', $capacity))->version, $capacity . ' bytes');

            if ($version > 1) {
                $this->assertSame($version, Qr::encode(str_repeat('x', $capacities[$version - 1] + 1))->version);
            }
        }

        $this->assertSame(213, Qr::capacity());

        $error = $this->assertThrows(AuthError::class, static fn () => Qr::encode(str_repeat('x', 214)));
        $this->assertStringContains('213', $error->getMessage());
        $this->assertNull(Qr::tryEncode(str_repeat('x', 214)), 'tryEncode() lets the caller fall back');
        $this->assertNotNull(Qr::tryEncode(str_repeat('x', 213)));

        $this->assertThrows(AuthError::class, static fn () => Qr::encode(''));
        $this->assertThrows(AuthError::class, static fn () => Qr::encode('x', 8));
        $this->assertThrows(AuthError::class, static fn () => Qr::encode('x', -1));
    }

    public function testTheSvgIsWellFormedAndDrawsTheRightModules(): void
    {
        $qr  = Qr::encode('otpauth://totp/test');
        $svg = $qr->toSvg();

        $this->assertStringContains('<svg xmlns="http://www.w3.org/2000/svg"', $svg);
        $this->assertStringContains('viewBox="0 0 ' . ($qr->size() + 8) . ' ' . ($qr->size() + 8) . '"', $svg);
        $this->assertStringContains('shape-rendering="crispEdges"', $svg);
        $this->assertStringContains('</svg>', $svg);

        // It parses as XML - a malformed SVG is an image that silently does not
        // render, which on an enrolment page looks like a broken account.
        $previous = libxml_use_internal_errors(true);
        $parsed   = simplexml_load_string($svg);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $this->assertNotSame(false, $parsed, 'the SVG must be valid XML');

        // One horizontal run per group of dark modules, and the first run of
        // the top row is the seven-module finder.
        $this->assertStringContains('d="M4 4h7v1h-7z', $svg);

        // A colour that is not a colour cannot escape the attribute.
        $nasty = $qr->toSvg(4, '"><script>alert(1)</script>');
        $this->assertStringNotContains('<script', $nasty);
        $this->assertStringContains('fill="#000000"', $nasty);

        // The quiet zone is clamped, not trusted.
        $this->assertStringContains('viewBox="0 0 ' . $qr->size() . ' ' . $qr->size() . '"', $qr->toSvg(0));
        $this->assertStringContains('viewBox="0 0 ' . ($qr->size() + 32) . ' ', $qr->toSvg(999));
    }

    public function testThePngWhereGdExists(): void
    {
        if (!Qr::hasGd()) {
            $this->skip('this PHP has no GD, so the PNG path cannot be exercised here');
        }

        $qr  = Qr::encode('otpauth://totp/test');
        $uri = $qr->toPngDataUri(4, 4);

        $this->assertNotNull($uri);
        /** @var string $uri */
        $this->assertTrue(str_starts_with($uri, 'data:image/png;base64,'), 'the shape TotpSetup.tsx already renders');

        $png = (string) base64_decode(substr($uri, strlen('data:image/png;base64,')), true);
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($png, 0, 8), 'a real PNG signature');

        $size = getimagesizefromstring($png);
        $this->assertNotSame(false, $size);
        /** @var array{0:int,1:int} $size */
        $expected = ($qr->size() + 8) * 4;
        $this->assertSame($expected, $size[0]);
        $this->assertSame($expected, $size[1]);
    }

    public function testTheTextFallbackIsTheSecretInGroupsOfFour(): void
    {
        $this->assertSame('JBSW Y3DP EHPK 3PXP', Qr::textFallback('JBSWY3DPEHPK3PXP'));
    }

    /**
     * @return array{generator:string, cases:list<array{name:string,textBase64:string,masks:list<array{mask:int,version:int,size:int,rows:list<string>}>}>}
     */
    private function fixture(): array
    {
        if (self::$fixture !== null) {
            return self::$fixture;
        }

        $path = __DIR__ . '/fixtures/qr-reference.json';

        if (!is_file($path)) {
            $this->fail('the reference fixture is missing; regenerate it with fixtures/make-reference.mjs');
        }

        /** @var mixed $decoded */
        $decoded = json_decode((string) file_get_contents($path), true);

        if (!is_array($decoded) || !isset($decoded['cases']) || !is_array($decoded['cases'])) {
            $this->fail('the reference fixture is not readable');
        }

        /** @var array{generator:string, cases:list<array{name:string,textBase64:string,masks:list<array{mask:int,version:int,size:int,rows:list<string>}>}>} $decoded */
        return self::$fixture = $decoded;
    }
}
