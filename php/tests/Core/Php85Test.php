<?php

declare(strict_types=1);

namespace Speedat\Tests\Core;

use Speedat\Core\Money;
use Speedat\Core\Paths;
use Speedat\Domain\Pricing\Engine;
use Speedat\Domain\Pricing\Settings;
use Speedat\Domain\SiteDoc\ImportProfile;
use Speedat\Tests\TestCase;

/**
 * PHP 8.5 regressions. The error handler turns every deprecation and warning
 * into an exception, so on 8.5 a retired function or an unrepresentable cast
 * is a 500, not a log line. Found 2026-10-27 moving the site to 8.5.12:
 * curl_close() broke every outbound request (Google sign-in) and a
 * typed "1e300" broke the rates screens. The suite never made a real HTTP
 * call, so it took an end-to-end run to see the first one.
 */
final class Php85Test extends TestCase
{
    /** Functions that have done nothing since PHP 8.0 and are deprecated in 8.5. */
    private const RETIRED = ['curl_close', 'curl_share_close', 'finfo_close', 'imagedestroy', 'xml_parser_free'];

    public function testNoRetiredNoOpFunctionIsCalledAnywhere(): void
    {
        $hits = [];

        foreach (['app', 'bin', 'public'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(Paths::root() . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                foreach (token_get_all((string) file_get_contents($file->getPathname())) as $i => $token) {
                    if (is_array($token) && $token[0] === T_STRING && in_array(strtolower($token[1]), self::RETIRED, true)) {
                        $hits[] = substr($file->getPathname(), strlen(Paths::root()) + 1) . ':' . $token[2] . ' ' . $token[1];
                    }
                }
            }
        }

        $this->assertSame([], $hits, 'deprecated on PHP 8.5, an exception under this app: ' . implode(', ', $hits));
    }

    public function testAHugeCargoThresholdStillDrawsTheGrid(): void
    {
        $grid = Engine::gridWeights(Settings::fromArray(['maxKg' => 1e300]));

        $this->assertTrue($grid !== [], 'a typed 1e300 caps at the largest grid, not a 500');
    }

    public function testNonFiniteAndHugeWeightsAreZeroGrams(): void
    {
        foreach ([INF, -INF, NAN, 1e300, -1e300] as $kg) {
            $this->assertSame(0, Money::kgToG($kg), var_export($kg, true));
        }
        $this->assertSame(2500, Money::kgToG(2.5));
    }

    public function testADamagedImportMapReadsAsZero(): void
    {
        $profile = ImportProfile::fromArray(['map' => ['kg' => 'not a number', 'price' => 1e300, 'zone' => 3]]);

        $this->assertSame(['kg' => 0, 'price' => 0, 'zone' => 3], $profile->map);
    }
}
