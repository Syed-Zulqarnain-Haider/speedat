<?php

declare(strict_types=1);

namespace Speedat\Domain\Ops;

use Speedat\Core\Clock;
use Speedat\Core\Db;

/**
 * "Has this already happened today?" - kept in the database, so two runners
 * agree.
 *
 * State lives in one app_settings row holding a map of marker => YYYY-MM-DD.
 *
 * WHAT IS LEFT FOR IT, AND WHAT IS NOT. One caller: Housekeeping's once-a-day
 * environment line, in the `ops` row. THE ALERTS NO LONGER COME THROUGH HERE.
 * Domain\Notify\Alerts is the single alerts implementation and claims its own
 * day, in the `alerts` row, in one statement - because that class also does the
 * sending, and a slot taken in one class while another decides whether to send
 * is an alert that goes out twice or not at all. That was a real duplicate
 * (Ops\Alerts, now deleted) and not a hypothetical one.
 *
 * The stored shape is deliberately identical in both rows, and identical to what
 * the TypeScript wrote - one row, marker => UTC date - so nobody reading
 * app_settings has to learn two formats. The rows themselves are NOT migrated:
 * bin/export-from-nextjs.php carries the price hold and nothing else, so the
 * first cron run on the new host has every slot free. That is worth at most one
 * repeated alert on the first night, about a problem that is still true.
 *
 * THE DAY IS THE UTC DAY. Hostinger's cron clock is UTC and cannot be changed
 * (docs/hostinger-constraints.md §6), so a "once a day" that counted in
 * Asia/Karachi would fire twice on the day the two dates disagree - which is
 * every day, for five hours of it.
 *
 * claim() is the method that matters: it decides and records in ONE
 * transaction, taking a row lock first. The obvious read-then-write version
 * runs the job twice whenever the hourly cron overlaps with a manual run, which
 * is exactly when somebody is already looking at the problem.
 */
final class DailyOnce
{
    /** app_settings.k is VARCHAR(64); a longer key would be a truncation, or on a strict connection an error. */
    private const MAX_KEY = 64;

    public function __construct(private readonly string $settingsKey)
    {
        // The key always comes from this codebase, never from a request, so
        // this is a bug-catcher rather than a validator - and it throws instead
        // of trimming, because a silently shortened key is two markers sharing
        // one row and an alert that stops firing for no visible reason.
        if ($settingsKey === '' || strlen($settingsKey) > self::MAX_KEY) {
            throw new \InvalidArgumentException('A DailyOnce settings key must be 1 to ' . self::MAX_KEY . ' characters.');
        }
    }

    /**
     * Take today's slot for this marker. True exactly once per UTC day, for
     * the caller that got there first; every later call today gets false.
     */
    public function claim(string $marker, ?\DateTimeImmutable $now = null): bool
    {
        $today = self::day($now);

        // The row has to exist before it can be locked: SELECT ... FOR UPDATE
        // locks nothing when there is nothing to lock, and two first-ever runs
        // would both insert.
        Db::run(
            'INSERT INTO app_settings (k, v, updated_at, updated_by)
                  VALUES (:k, :v, :at, :by)
             ON DUPLICATE KEY UPDATE k = k',
            [':k' => $this->settingsKey, ':v' => '{}', ':at' => Clock::sql(), ':by' => 'system']
        );

        return Db::transaction(function () use ($marker, $today): bool {
            $raw = Db::value('SELECT v FROM app_settings WHERE k = ? FOR UPDATE', [$this->settingsKey]);
            $map = Db::unjson($raw);

            if (($map[$marker] ?? null) === $today) {
                return false;
            }

            $map[$marker] = $today;

            Db::exec(
                'UPDATE app_settings SET v = :v, updated_at = :at, updated_by = :by WHERE k = :k',
                [':v' => Db::json($map), ':at' => Clock::sql(), ':by' => 'system', ':k' => $this->settingsKey]
            );

            return true;
        });
    }

    /** A read-only peek, for reporting and for tests. Never use it to decide; use claim(). */
    public function doneToday(string $marker, ?\DateTimeImmutable $now = null): bool
    {
        $raw = Db::value('SELECT v FROM app_settings WHERE k = ?', [$this->settingsKey]);
        $map = Db::unjson($raw);

        return ($map[$marker] ?? null) === self::day($now);
    }

    /** The UTC date a marker was last claimed, or null. */
    public function lastClaimed(string $marker): ?string
    {
        $raw = Db::value('SELECT v FROM app_settings WHERE k = ?', [$this->settingsKey]);
        $map = Db::unjson($raw);
        $day = $map[$marker] ?? null;

        return is_string($day) && $day !== '' ? $day : null;
    }

    private static function day(?\DateTimeImmutable $now): string
    {
        return ($now ?? Clock::utcNow())->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
    }
}
