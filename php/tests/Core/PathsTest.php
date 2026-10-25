<?php

declare(strict_types=1);

namespace Speedat\Tests\Core;

use Speedat\Core\Config;
use Speedat\Core\ConfigError;
use Speedat\Core\Paths;
use Speedat\Tests\TestCase;

/**
 * Where everything lives on disk - and, since audit round 1 finding 19, where
 * it is not allowed to live.
 *
 * Paths's own docblock says "the layout is the security model": `public/` is the
 * only directory a web server points at and every other directory is its
 * sibling. Five of them - storage, cache, logs, tmp, uploads - can be overridden
 * from config, and nothing checked what the override said. One typo
 * (`paths.logs => 'public/logs'`) published the structured log, which carries
 * request paths, hashed IPs, user agents and driver messages, and
 * `storage/.htaccess` does not travel with a path somebody redirected.
 */
final class PathsTest extends TestCase
{
    /** @var array<string,string> the five overridable keys, as configured before this test */
    private array $saved = [];

    private const KEYS = ['paths.storage', 'paths.cache', 'paths.logs', 'paths.tmp', 'paths.uploads'];

    public function setUp(): void
    {
        foreach (self::KEYS as $key) {
            $this->saved[$key] = Config::str($key);
        }
    }

    public function tearDown(): void
    {
        // Put every key back, whatever happened. A test that leaves paths.logs
        // pointed at the web root makes the NEXT test's first log line throw,
        // and the failure names the wrong file.
        foreach ($this->saved as $key => $value) {
            Config::set($key, $value);
        }
    }

    /**
     * The finding. Every web-root name every panel uses, on every overridable
     * key, in the position a real typo puts it.
     */
    public function testAConfiguredPathInsideTheWebRootIsRefused(): void
    {
        $hostile = [
            'public/logs',
            'public_html/logs',
            'htdocs/logs',
            'www/logs',
            '/home/u123456/domains/speedat.com.pk/public_html/storage',
            'PUBLIC_HTML/storage',          // the check is case-insensitive
            '../public/storage',
            'storage/public',               // the last segment counts too
        ];

        foreach (self::KEYS as $key) {
            foreach ($hostile as $path) {
                Config::set($key, $path);

                $error = $this->assertThrows(
                    ConfigError::class,
                    static fn (): string => Paths::logs() . Paths::cache() . Paths::tmp() . Paths::uploads() . Paths::storage(),
                    $key . ' => ' . $path . ' must be refused'
                );

                $this->assertStringContains($key, $error->getMessage(), 'the message names the key');
            }

            Config::set($key, $this->saved[$key]);
        }
    }

    /**
     * Refusing must not become refusing everything. These are the paths a real
     * host uses, including the ones that merely LOOK like the web root - the
     * check is per segment, not str_contains, or a directory called
     * "publications" would be refused for its first nine letters.
     */
    public function testAPathOutsideTheWebRootIsAccepted(): void
    {
        $fine = [
            '/home/u123456/domains/speedat.com.pk/storage',
            '/var/log/speedat',
            'D:/speedat/php/storage',
            '/home/u123456/publications/storage',   // not "public"
            '/home/u123456/wwwroot-archive/logs',   // not "www"
            '/home/u123456/domains/site/public_htmlx/logs',
        ];

        foreach ($fine as $path) {
            Config::set('paths.logs', $path);

            $this->assertSame(
                rtrim(str_replace('\\', '/', $path), '/'),
                Paths::logs(),
                $path . ' is outside the web root and must be honoured'
            );
        }

        // A trailing slash is normalised away, and backslashes become slashes.
        Config::set('paths.logs', 'D:\\speedat\\php\\storage\\logs\\');
        $this->assertSame('D:/speedat/php/storage/logs', Paths::logs());
    }

    /** An empty override is "use the default", not "use the empty string". */
    public function testAnEmptyOverrideFallsBackToTheDefaultLayout(): void
    {
        foreach (self::KEYS as $key) {
            Config::set($key, '');
        }

        $this->assertSame(Paths::root() . '/storage', Paths::storage());
        $this->assertSame(Paths::root() . '/storage/logs', Paths::logs());
        $this->assertSame(Paths::root() . '/storage/cache', Paths::cache());
        $this->assertSame(Paths::root() . '/storage/tmp', Paths::tmp());
        $this->assertSame(Paths::root() . '/storage/uploads', Paths::uploads());
    }

    /**
     * The fixed paths are not overridable at all, so nothing above can move the
     * code, the config file or the schema.
     */
    public function testTheFixedPathsAreStillDerivedFromTheRootAlone(): void
    {
        Config::set('paths.storage', 'public_html/storage');

        $this->assertSame(Paths::root() . '/app', Paths::app());
        $this->assertSame(Paths::root() . '/config', Paths::config());
        $this->assertSame(Paths::root() . '/config/config.php', Paths::configFile());
        $this->assertSame(Paths::root() . '/public', Paths::publicDir());
        $this->assertSame(Paths::root() . '/db/migrations', Paths::migrations());
    }
}
