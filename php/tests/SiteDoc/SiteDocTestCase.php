<?php

declare(strict_types=1);

namespace Speedat\Tests\SiteDoc;

use Speedat\Core\Config;
use Speedat\Core\Db;
use Speedat\Core\Paths;
use Speedat\Domain\SiteDoc\Live;
use Speedat\Domain\SiteDoc\Migrate;
use Speedat\Domain\SiteDoc\Seed;
use Speedat\Domain\SiteDoc\SiteData;
use Speedat\Tests\TestCase;

/**
 * Shared ground for the site-document tests.
 *
 * Two things every one of them needs: a document it can bend one field at a
 * time, and - for the ones that touch the store - a database and a cache
 * directory of their own. The cache is pointed at a per-run temporary
 * directory, because a test that busts the real storage/cache would quietly
 * change what a developer's browser is being served.
 */
abstract class SiteDocTestCase extends TestCase
{
    private string $cacheDir = '';

    /** Point the cache somewhere disposable and forget anything memoised. */
    protected function useScratchCache(): string
    {
        if ($this->cacheDir === '') {
            $this->cacheDir = Paths::tmp() . '/sitedoc-test-' . bin2hex(random_bytes(4));
        }

        Config::set('paths.cache', $this->cacheDir);
        Paths::ensureWritable($this->cacheDir . '/site');
        Live::forget();

        return $this->cacheDir;
    }

    protected function dropScratchCache(): void
    {
        Live::forget();

        if ($this->cacheDir !== '' && is_dir($this->cacheDir . '/site')) {
            foreach (glob($this->cacheDir . '/site/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->cacheDir . '/site');
            @rmdir($this->cacheDir);
        }

        $this->cacheDir = '';
    }

    /** Skip rather than fail when nobody started MariaDB. */
    protected function needDatabase(): void
    {
        if (!Db::healthy()) {
            $this->skip(
                'the test database is not reachable - start MariaDB (bin/local/start-db.ps1) '
                . 'and run bin/migrate.php --database=speedat_php_test'
            );
        }
    }

    /** Empty the tables this suite owns. */
    protected function emptyStore(): void
    {
        Db::exec('DELETE FROM draft');
        Db::exec('DELETE FROM versions');
        Db::exec('DELETE FROM app_settings WHERE k = ?', ['hold']);
    }

    /** The seed as a mutable array, so a test can bend one field and rebuild the document. @return array<string,mixed> */
    protected function seedArray(): array
    {
        return Seed::data();
    }

    /** @param array<string,mixed> $doc */
    protected function site(array $doc): SiteData
    {
        return Migrate::migrate($doc);
    }
}
