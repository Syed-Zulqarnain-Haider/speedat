<?php

declare(strict_types=1);

namespace Speedat\Tests\Security;

use Speedat\Core\Paths;
use Speedat\Tests\TestCase;

/**
 * The security headers that are set in THREE places, held to one spelling.
 *
 * app/Core/App.php sets them from PHP; public/.htaccess and
 * deploy/htaccess/public_html.htaccess set them again with `Header always set`.
 * On Hostinger the .htaccess copy runs after PHP and WINS - measured
 * 2026-09-30, when the live Permissions-Policy was the 5-token .htaccess string
 * and not App's 7-token one. So the copy a reviewer reads (App.php) was not the
 * copy a browser got, and the three had drifted to three different fifth
 * tokens. App.php's comment pointed at this test as the guard; the test did not
 * exist. Now it does.
 *
 * COOP is pinned to its value as well as to parity, because the wrong value
 * was not cosmetic: plain `same-origin` puts Firebase's Google sign-in popup in
 * a separate browsing context group and every sign-in fails with
 * popup-closed-by-user. Parity alone would have happily kept all three wrong.
 *
 * Source text, not a live request: `php -S` ignores .htaccess, so reading the
 * files is the only check that can run off the server. README.md's `curl -I`
 * list is the one that proves the host.
 */
final class HeaderParityTest extends TestCase
{
    /** The headers every layer sets and must agree on. */
    private const SHARED = ['Cross-Origin-Opener-Policy', 'Permissions-Policy'];

    public function testAppAndBothHtaccessCopiesSendTheSameValues(): void
    {
        $app = $this->fromApp();

        foreach ($this->htaccessFiles() as $label => $path) {
            $copy = $this->fromHtaccess($path);

            foreach (self::SHARED as $header) {
                $this->assertTrue(isset($app[$header]), $header . ' is not set in app/Core/App.php');
                $this->assertTrue(isset($copy[$header]), $header . ' is not set in ' . $label);
                $this->assertSame(
                    $app[$header],
                    $copy[$header],
                    $header . ' in ' . $label . ' differs from app/Core/App.php - on Hostinger the .htaccess value is the live one'
                );
            }
        }
    }

    public function testEachHtaccessSetsEachSharedHeaderExactlyOnce(): void
    {
        // Two `Header always set` lines for one name means the later one wins
        // silently, and the earlier one is what a reviewer reads.
        foreach ($this->htaccessFiles() as $label => $path) {
            $source = $this->read($path);

            foreach (self::SHARED as $header) {
                $count = preg_match_all('/^\s*Header\s+always\s+set\s+' . preg_quote($header, '/') . '\s/mi', $source);
                $this->assertSame(1, $count, $header . ' should be set once in ' . $label);
            }
        }
    }

    public function testCoopAllowsTheSignInPopup(): void
    {
        $this->assertSame(
            'same-origin-allow-popups',
            $this->fromApp()['Cross-Origin-Opener-Policy'] ?? null,
            'plain same-origin breaks the Firebase Google sign-in popup (popup-closed-by-user)'
        );
    }

    public function testPermissionsPolicyDeniesTheUnionOfEveryTokenAnyCopyEverHad(): void
    {
        $policy = $this->fromApp()['Permissions-Policy'] ?? '';

        // The union of the three drifted copies, plus the three
        // docs/quality-bar.md names. Dropping one is a feature nobody denies.
        foreach (['geolocation', 'microphone', 'camera', 'payment', 'usb', 'browsing-topics', 'interest-cohort'] as $feature) {
            $this->assertTrue(
                preg_match('/(^|,\s*)' . preg_quote($feature, '/') . '=\(\)/', $policy) === 1,
                $feature . ' is not denied by Permissions-Policy'
            );
        }
    }

    /** @return array<string,string> label => absolute path */
    private function htaccessFiles(): array
    {
        $root = dirname(Paths::publicDir());

        return [
            'public/.htaccess'                     => Paths::publicDir() . '/.htaccess',
            'deploy/htaccess/public_html.htaccess' => $root . '/deploy/htaccess/public_html.htaccess',
        ];
    }

    /** @return array<string,string> header => value, as App.php's literal calls spell them */
    private function fromApp(): array
    {
        $source = $this->read(Paths::app() . '/Core/App.php');
        $found  = [];

        foreach (self::SHARED as $header) {
            if (preg_match("/->header\\(\\s*'" . preg_quote($header, '/') . "'\\s*,\\s*'([^']*)'\\s*\\)/", $source, $m) === 1) {
                $found[$header] = $m[1];
            }
        }

        return $found;
    }

    /** @return array<string,string> header => value from `Header always set Name "value"` */
    private function fromHtaccess(string $path): array
    {
        $source = $this->read($path);
        $found  = [];

        foreach (self::SHARED as $header) {
            if (preg_match('/^\s*Header\s+always\s+set\s+' . preg_quote($header, '/') . '\s+"([^"]*)"/mi', $source, $m) === 1) {
                $found[$header] = $m[1];
            }
        }

        return $found;
    }

    private function read(string $path): string
    {
        $source = @file_get_contents($path);
        $this->assertTrue(is_string($source), 'cannot read ' . $path);

        return (string) $source;
    }
}
