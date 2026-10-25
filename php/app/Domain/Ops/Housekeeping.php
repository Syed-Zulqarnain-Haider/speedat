<?php

declare(strict_types=1);

namespace Speedat\Domain\Ops;

use Speedat\Core\Clock;
use Speedat\Core\Config;
use Speedat\Core\Log;
use Speedat\Core\Paths;
use Speedat\Core\RateLimit;
use Speedat\Core\Session;

/**
 * The chores the host needs doing on a timer. Nothing here is a feature; all
 * of it is the difference between a site that still works in March and one
 * that has quietly filled its inode quota.
 *
 * Why each one:
 *
 *  - SESSIONS. Session validates expiry on every read, so this is tidiness
 *    rather than security - but an un-swept table grows for ever and the login
 *    lookup grows with it.
 *  - RATE-LIMIT WINDOWS. The limiter sweeps opportunistically, one request in
 *    fifty. On a quiet night there is no fiftieth request, and the rows from
 *    the busy afternoon are still there.
 *  - THE CACHE DIRECTORY. Hostinger counts inodes, not just bytes
 *    (docs/hostinger-constraints.md §4): 400,000 files on Premium, and a cache
 *    that never forgets reaches that long before it reaches the disk quota.
 *  - THE LOGS. The same quota, in bytes: thirty days are kept, the rest go.
 *  - THE ENVIRONMENT LINE. §6 of the same document says CLI PHP may be a
 *    DIFFERENT VERSION from the site's PHP and that this is undocumented, so
 *    the first cron run of each day writes the version and the loaded
 *    extensions to the log. It is the only way to find out, and finding out
 *    after a nightly job has been silently dying is finding out too late.
 */
final class Housekeeping
{
    /** Marker for the once-a-day environment line. */
    public const ENVIRONMENT_MARKER = 'environment';

    /**
     * The extensions worth naming on the environment line, with a yes or a no
     * beside each. A dump of everything loaded is forty names nobody reads;
     * these nine are the ones whose absence changes what the application can
     * do, and `pdo_mysql` is the one whose absence makes every job below fail
     * for a reason no summary line mentions.
     *
     * @var list<string>
     */
    public const WANTED_EXTENSIONS = [
        'pdo_mysql', 'mbstring', 'json', 'openssl', 'zip', 'gd', 'fileinfo', 'intl', 'sodium',
    ];

    /** A cache entry older than this has not been asked for in a week. */
    private const DEFAULT_CACHE_DAYS = 7;

    /** Thirty days of logs: long enough to answer "what happened last month". */
    private const DEFAULT_LOG_DAYS = 30;

    /**
     * Files the sweep will not delete however old they are: they are deployed
     * guards, not cache. index.html defeats directory listing and .htaccess
     * denies the directory outright.
     *
     * @var list<string>
     */
    private const KEEP = ['.gitkeep', 'index.html', '.htaccess'];

    /** Never walk more than this in one run; a runaway loop must not become the outage. */
    private const MAX_SCAN = 20000;

    public function __construct(
        private readonly DailyOnce $once,
        private readonly \DateTimeImmutable $now,
    ) {
    }

    public static function fromConfig(?\DateTimeImmutable $now = null): self
    {
        return new self(new DailyOnce(Cron::SETTINGS_KEY), $now ?? Clock::utcNow());
    }

    /** Expired and idle logins. */
    public function expiredSessions(): int
    {
        return Session::sweep();
    }

    /**
     * Rate-limit windows nobody will ask about again.
     *
     * The sweep is given the same clock the rest of the run uses. RateLimit
     * says why in its own comment: sweeping against the wall clock while the
     * counting used an injected one deletes the rows that were just written.
     */
    public function rateLimitWindows(): int
    {
        return RateLimit::sweep($this->now->getTimestamp() * 1000);
    }

    /** Cache files older than the configured age. Returns how many went. */
    public function cacheFiles(): int
    {
        $days   = max(1, Config::int('ops.cache_days', self::DEFAULT_CACHE_DAYS));
        $cutoff = $this->now->getTimestamp() - $days * 86400;
        $dir    = Paths::cache();

        if (!is_dir($dir)) {
            return 0;
        }

        $removed = 0;
        $seen    = 0;

        foreach (self::walk($dir) as $file) {
            if (++$seen > self::MAX_SCAN) {
                Log::warn('ops_cache_sweep_capped', ['scanned' => $seen - 1]);
                break;
            }

            if (in_array(basename($file), self::KEEP, true)) {
                continue;
            }

            $modified = @filemtime($file);
            if ($modified !== false && $modified < $cutoff && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Log files older than the configured age. Returns how many went.
     *
     * Shared hosting has a disk quota, and a disk that fills up takes the site
     * down long before anyone reads the logs. This used to live in bin/cron.php
     * alone, so an install on the URL cron - the only cron a shell-less plan
     * has - never pruned a log at all (found 2026-10-25). It is here now so both
     * doors run it, and it measures age against the run's clock like the cache
     * sweep does, not against time().
     *
     * Only `app-YYYY-MM-DD.log` files: anything else in the directory was put
     * there by a person, and a person can delete it.
     */
    public function logFiles(): int
    {
        $days    = max(1, Config::int('ops.log_days', self::DEFAULT_LOG_DAYS));
        $cutoff  = $this->now->getTimestamp() - $days * 86400;
        $removed = 0;

        foreach (glob(Paths::logs() . '/app-*.log') ?: [] as $file) {
            if (is_link($file)) {
                continue;
            }

            $modified = @filemtime($file);
            if ($modified !== false && $modified < $cutoff && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * What a `cron_environment` line says - ONE shape, whoever writes it.
     *
     * TWO EMITTERS, ONE EVENT NAME. `bin/cron.php` writes this line before it
     * runs anything (so a run that dies inside a job still leaves the fact that
     * explains which interpreter it died in) and this class writes it once a
     * UTC day (so the log carries it even when nobody is looking). They used to
     * disagree: the CLI one named the nine extensions this application would
     * miss with a yes or a no beside each, and this one dumped every loaded
     * extension into a single comma-joined string and nothing else.
     *
     * The second go-live rehearsal (2026-09-29) found why that matters. On a
     * shared plan with no shell - which is the plan this ships to - the only
     * cron the owner can run is the URL one, `/api/cron?token=...`, and the URL
     * one goes through this class. So the operator most likely to be pointed at
     * the wrong PHP build was the one getting the line that does not say
     * `missing: pdo_mysql`. Same event name, same fields, either way in.
     *
     * @return array<string,mixed>
     */
    public static function environmentFields(): array
    {
        $loaded = [];
        $absent = [];

        foreach (self::WANTED_EXTENSIONS as $extension) {
            if (extension_loaded($extension)) {
                $loaded[] = $extension;
            } else {
                $absent[] = $extension;
            }
        }

        return [
            'php'        => PHP_VERSION,
            'sapi'       => PHP_SAPI,
            'binary'     => PHP_BINARY,
            'loaded'     => $loaded,
            'missing'    => $absent,
            'memory'     => (string) ini_get('memory_limit'),
            'maxExecute' => (string) ini_get('max_execution_time'),
            'timezone'   => date_default_timezone_get(),
        ];
    }

    /**
     * Write the PHP version and the loaded extensions, once per UTC day.
     *
     * @return bool true when this run was the one that wrote it
     */
    public function logEnvironment(): bool
    {
        if (!$this->once->claim(self::ENVIRONMENT_MARKER, $this->now)) {
            return false;
        }

        Log::info('cron_environment', self::environmentFields());

        return true;
    }

    /**
     * Every regular file under a directory, one level of recursion at a time,
     * skipping symlinks.
     *
     * Symlinks are skipped rather than followed because following one is how a
     * sweep of storage/cache becomes a sweep of somewhere else. The host has
     * symlink() disabled, so one being there at all would mean something is
     * already wrong.
     *
     * @return \Generator<int,string>
     */
    private static function walk(string $dir): \Generator
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            if (is_link($path)) {
                continue;
            }

            if (is_dir($path)) {
                yield from self::walk($path);
                continue;
            }

            if (is_file($path)) {
                yield $path;
            }
        }
    }
}
