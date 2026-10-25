<?php

declare(strict_types=1);

namespace Speedat\Tests\Core;

use Speedat\Core\Config;
use Speedat\Core\ConfigError;
use Speedat\Tests\TestCase;

/**
 * Configuration loads, reads and - the part that matters - fails in a way that
 * tells the operator what is wrong without telling anyone else what the values
 * are.
 */
final class ConfigTest extends TestCase
{
    public function testConfigIsLoadedWithTheKeysTheApplicationNeeds(): void
    {
        $this->assertTrue(Config::loaded(), 'bin/test.php boots the application, so config must be loaded');

        foreach (['app.key', 'app.env', 'app.timezone', 'db.host', 'db.name', 'db.user'] as $key) {
            $this->assertNotSame('', Config::str($key), $key . ' must be set in config/config.php');
        }
    }

    public function testDotPathsReachNestedValuesAndFallBackCleanly(): void
    {
        $this->assertSame('utf8mb4', Config::str('db.charset', 'utf8mb4'));
        $this->assertSame('fallback', Config::str('db.definitely_not_here', 'fallback'));
        $this->assertSame(0, Config::int('db.definitely_not_here'));
        $this->assertFalse(Config::bool('app.definitely_not_here'));
        $this->assertSame([], Config::arr('nothing.here.at.all'));

        // A partial path must not return the array it passed through.
        $this->assertSame('', Config::str('db'));
    }

    public function testAMissingFileNamesTheFileAndNothingElse(): void
    {
        $error = $this->assertThrows(
            ConfigError::class,
            static fn () => Config::load(__DIR__ . '/no-such-config.php')
        );

        $this->assertStringContains('config.php', $error->getMessage());
        $this->assertStringContains('config.sample.php', $error->getMessage());

        // The loaded configuration must survive a failed load attempt.
        $this->assertTrue(Config::loaded());
    }

    public function testARequiredValueThatIsMissingNamesTheKeyAndNeverAValue(): void
    {
        $error = $this->assertThrows(
            ConfigError::class,
            static fn () => Config::need('db.this_key_does_not_exist')
        );

        $this->assertStringContains('db.this_key_does_not_exist', $error->getMessage());

        // The message goes in front of whoever is looking at the screen. It
        // must not carry the application key or the database password with it.
        $this->assertStringNotContains(Config::str('app.key'), $error->getMessage());
        $this->assertStringNotContains(Config::str('db.pass'), $error->getMessage());
    }

    public function testTheApplicationKeyIsThirtyTwoBytesAndDerivesDistinctSubkeys(): void
    {
        $this->assertSame(32, strlen(Config::appKey()));

        $csrf = Config::derivedKey('csrf');
        $ip   = Config::derivedKey('ip-hash');

        $this->assertSame(32, strlen($csrf), 'a derived key is raw sha256 output');
        $this->assertNotSame($csrf, $ip, 'different purposes must not share a key');
        $this->assertSame($csrf, Config::derivedKey('csrf'), 'derivation must be stable across calls');
    }

    public function testDebugOutputCannotBeTurnedOnInProduction(): void
    {
        $env   = Config::str('app.env');
        $debug = Config::bool('app.debug');

        try {
            Config::set('app.env', 'production');
            Config::set('app.debug', true);

            $this->assertTrue(Config::isProduction());
            $this->assertFalse(Config::isDebug(), 'production must refuse debug output even when asked for it');

            Config::set('app.env', 'local');
            $this->assertTrue(Config::isDebug(), 'outside production, the flag decides');
        } finally {
            Config::set('app.env', $env);
            Config::set('app.debug', $debug);
        }
    }
}
