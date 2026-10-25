<?php

declare(strict_types=1);

namespace Speedat\Tests\Ops;

use Speedat\Core\Clock;
use Speedat\Core\Config;
use Speedat\Core\Db;
use Speedat\Core\Paths;
use Speedat\Domain\Ops\DailyOnce;
use Speedat\Domain\Ops\Housekeeping;

/**
 * The chores, proved by the rows and the files actually disappearing. A sweep
 * that reports a number without deleting anything is the failure mode worth
 * testing for: nothing else in the system would ever notice.
 */
final class HousekeepingTest extends OpsTestCase
{
    private string $cacheDir = '';

    private string $savedCachePath = '';

    public function setUp(): void
    {
        parent::setUp();

        // The sweep is pointed at a directory this test owns. Turning it loose
        // on the real storage/cache would delete whatever else is in there.
        $this->savedCachePath = Config::str('paths.cache');
        $this->cacheDir       = Paths::tmp() . '/' . $this->tag . '-cache';

        if (!Paths::ensureWritable($this->cacheDir)) {
            $this->skip('could not create a temporary cache directory under storage/tmp');
        }

        Config::set('paths.cache', $this->cacheDir);
    }

    public function tearDown(): void
    {
        Config::set('paths.cache', $this->savedCachePath);

        if ($this->cacheDir !== '' && is_dir($this->cacheDir)) {
            foreach (glob($this->cacheDir . '/*') ?: [] as $entry) {
                if (is_file($entry)) {
                    @unlink($entry);
                }
            }
            foreach (glob($this->cacheDir . '/*/*') ?: [] as $entry) {
                if (is_file($entry)) {
                    @unlink($entry);
                }
            }
            foreach (glob($this->cacheDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
                @rmdir($dir);
            }
            @rmdir($this->cacheDir);
        }

        parent::tearDown();
    }

    private function housekeeping(\DateTimeImmutable $now): Housekeeping
    {
        return new Housekeeping(new DailyOnce($this->settingsKey('ops')), $now);
    }

    public function testExpiredSessionsAreDeletedAndValidOnesAreNot(): void
    {
        $now = Clock::utcNow();

        $expired = $this->insertSession($now->modify('-1 hour'), $now->modify('-30 minutes'));
        $valid   = $this->insertSession($now->modify('+2 days'), $now);

        $removed = $this->housekeeping($now)->expiredSessions();

        $this->assertTrue($removed >= 1, 'at least the expired row went');
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM sessions WHERE id = ?', [$expired]), 'the expired session is gone');
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM sessions WHERE id = ?', [$valid]), 'the live session is untouched');
    }

    public function testAnIdleSessionIsSweptEvenBeforeItExpires(): void
    {
        $now  = Clock::utcNow();
        $idle = max(300, Config::int('auth.session_idle', 28800));

        // Not expired - but last seen longer ago than the idle timeout.
        $stale = $this->insertSession($now->modify('+2 days'), $now->modify('-' . ($idle + 600) . ' seconds'));

        $this->housekeeping($now)->expiredSessions();

        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM sessions WHERE id = ?', [$stale]));
    }

    public function testOldRateLimitWindowsAreDeleted(): void
    {
        $now = self::utc('2026-09-27 12:00:00');

        $old    = $this->insertRateLimitWindow($now->modify('-3 days'));
        $recent = $this->insertRateLimitWindow($now->modify('-1 hour'));

        $removed = $this->housekeeping($now)->rateLimitWindows();

        $this->assertTrue($removed >= 1);
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM rate_limits WHERE k = ?', [$old]), 'the three-day-old window is gone');
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM rate_limits WHERE k = ?', [$recent]), 'this hour is still counting');
    }

    public function testTheCacheSweepDeletesOldFilesAndKeepsTheGuards(): void
    {
        $now    = self::utc('now');
        $days   = max(1, Config::int('ops.cache_days', 7));
        $oldMs  = $now->getTimestamp() - ($days + 1) * 86400;

        $stale  = $this->cacheFile('stale.cache', $oldMs);
        $fresh  = $this->cacheFile('fresh.cache', $now->getTimestamp());
        $keep   = $this->cacheFile('index.html', $oldMs);
        $gitkeep = $this->cacheFile('.gitkeep', $oldMs);

        // And one in a subdirectory, to prove the walk recurses.
        @mkdir($this->cacheDir . '/nested', 0o770, true);
        $nested = $this->cacheFile('nested/deep.cache', $oldMs);

        $removed = $this->housekeeping($now)->cacheFiles();

        $this->assertSame(2, $removed, 'the two stale entries and nothing else');
        $this->assertFalse(is_file($stale), 'a stale cache file is deleted');
        $this->assertFalse(is_file($nested), 'and so is one in a subdirectory');
        $this->assertTrue(is_file($fresh), 'a fresh one is kept');
        $this->assertTrue(is_file($keep), 'index.html is a deployed guard, not cache');
        $this->assertTrue(is_file($gitkeep), '.gitkeep keeps the directory in the repository');
    }

    public function testACacheDirectoryThatIsNotThereIsNotAnError(): void
    {
        Config::set('paths.cache', $this->cacheDir . '-does-not-exist');

        $this->assertSame(0, $this->housekeeping(self::utc('now'))->cacheFiles());
    }

    public function testTheEnvironmentIsLoggedOnceADay(): void
    {
        $once   = new DailyOnce($this->settingsKey('ops'));
        $monday = self::utc('2026-09-27 02:00:00');

        $first = (new Housekeeping($once, $monday))->logEnvironment();
        $this->assertTrue($first, 'the first run of the day writes it');

        $second = (new Housekeeping($once, $monday->modify('+6 hours')))->logEnvironment();
        $this->assertFalse($second, 'later runs the same day do not');

        $tomorrow = (new Housekeeping($once, self::utc('2026-09-28 02:00:00')))->logEnvironment();
        $this->assertTrue($tomorrow, 'the next day writes it again');
    }

    public function testTheEnvironmentLineReachesTheLog(): void
    {
        $logFile = Paths::logs() . '/app-' . Clock::utcNow()->format('Y-m-d') . '.log';
        $before  = is_file($logFile) ? (int) filesize($logFile) : 0;

        $this->assertTrue((new Housekeeping(new DailyOnce($this->settingsKey('ops')), Clock::utcNow()))->logEnvironment());

        $written = is_file($logFile) ? (string) file_get_contents($logFile, false, null, $before) : '';

        $this->assertStringContains('cron_environment', $written);
        $this->assertStringContains(PHP_VERSION, $written, 'which PHP the CLI actually ran');
        $this->assertStringContains('pdo_mysql', $written, 'and which extensions it had loaded');
    }

    /**
     * Logs past their age go; today's, a recent one and anything that is not an
     * app-*.log stay. This used to be a function inside bin/cron.php, so the URL
     * cron never ran it (2026-10-25); it is a Housekeeping job now, measured
     * against the run's clock.
     */
    public function testOldLogFilesArePrunedAndRecentOnesKept(): void
    {
        $savedLogs = Config::str('paths.logs');
        $savedDays = Config::int('ops.log_days', 30);
        $dir       = Paths::tmp() . '/' . $this->tag . '-logs';

        if (!Paths::ensureWritable($dir)) {
            $this->skip('could not create a temporary log directory under storage/tmp');
        }

        $now   = self::utc('2026-10-25 12:00:00');
        $files = [
            'old'    => [$dir . '/app-2026-09-01.log', $now->modify('-40 days')->getTimestamp()],
            'recent' => [$dir . '/app-2026-10-20.log', $now->modify('-5 days')->getTimestamp()],
            'other'  => [$dir . '/notes.txt', $now->modify('-400 days')->getTimestamp()],
        ];

        try {
            Config::set('paths.logs', $dir);
            Config::set('ops.log_days', 30);

            foreach ($files as [$path, $modified]) {
                file_put_contents($path, 'x');
                touch($path, $modified);
            }

            $removed = $this->housekeeping($now)->logFiles();

            $this->assertSame(1, $removed, 'the forty-day-old log and nothing else');
            $this->assertFalse(is_file($files['old'][0]), 'a log past thirty days is deleted');
            $this->assertTrue(is_file($files['recent'][0]), 'a five-day-old log is kept');
            $this->assertTrue(is_file($files['other'][0]), 'a file that is not app-*.log is somebody else\'s');
        } finally {
            Config::set('paths.logs', $savedLogs);
            Config::set('ops.log_days', $savedDays);

            foreach (glob($dir . '/*') ?: [] as $entry) {
                if (is_file($entry)) {
                    @unlink($entry);
                }
            }
            @rmdir($dir);
        }
    }

    private function cacheFile(string $name, int $modified): string
    {
        $path = $this->cacheDir . '/' . $name;
        file_put_contents($path, 'x');
        touch($path, $modified);

        return $path;
    }
}
