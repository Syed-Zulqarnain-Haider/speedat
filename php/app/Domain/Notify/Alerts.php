<?php

declare(strict_types=1);

namespace Speedat\Domain\Notify;

use Speedat\Core\Clock;
use Speedat\Core\Db;
use Speedat\Core\Log;

/**
 * Operational alerts to the office, each sent at most once per day per key.
 * Port of src/lib/alerts.ts.
 *
 * The state lives in app_settings under the key "alerts": a JSON object of
 * alert key => the date it last went out, as "YYYY-MM-DD" in UTC. The date is
 * UTC because the cron clock on this host is UTC and cannot be changed
 * (docs/hostinger-constraints.md section 6) - an alert window measured in local
 * time would open and close in the middle of the working day.
 *
 * ONE CHANGE FROM THE TYPESCRIPT, DELIBERATE. alerts.ts reads the map, decides,
 * sends, then writes the map back. Two cron runs that overlap therefore both
 * read "not sent", and the office gets the alert twice. Here the claim is a
 * single INSERT ... ON DUPLICATE KEY UPDATE whose affected-row count says
 * whether this caller was the one that took today's slot, so a second caller
 * gets false and sends nothing. The observable behaviour is otherwise
 * identical, including the part where an alert whose SEND fails still counts as
 * today's - retrying a broken mail server every two hours would turn one
 * problem into a hundred messages once it came back.
 *
 * THIS IS THE ONLY ALERTS IMPLEMENTATION. Domain\Ops carried a second port of
 * alerts.ts - Ops\Alerts over Ops\Contract\Notifier over Core\Mail - which
 * wrote the SAME "alerts" row with the same markers. Two writers of one row is
 * two answers to "has the office already been told today", and the pair could
 * not be told apart from the outside: whichever ran first silenced the other
 * for the day. It is deleted, Ops\Cron uses this class, and the `[Speedat] `
 * prefix is applied HERE and nowhere else, so nothing can prefix twice.
 */
final class Alerts
{
    /** The app_settings row every alert date lives in. */
    public const SETTINGS_KEY = 'alerts';

    /** app_settings.k is VARCHAR(64); a longer row name would be truncated into somebody else's row. */
    private const MAX_SETTINGS_KEY = 64;

    /** Prefix on every subject, as in alerts.ts. */
    private const SUBJECT_PREFIX = '[Speedat] ';

    public function __construct(
        private readonly Notifier $notifier,
        /**
         * The app_settings row the sent-today map lives in. Production never
         * passes this - the row is "alerts" and a second one would mean an
         * alert going out twice a day. It exists because a test must be able to
         * prove the day boundary without writing the row the live site
         * deduplicates against, in a test database shared with every other
         * suite in the repository.
         */
        private readonly string $settingsKey = self::SETTINGS_KEY,
    ) {
        if ($settingsKey === '' || strlen($settingsKey) > self::MAX_SETTINGS_KEY) {
            throw new \InvalidArgumentException(
                'An alerts settings row must be 1 to ' . self::MAX_SETTINGS_KEY . ' characters.'
            );
        }
    }

    /**
     * Send once per day per key. Returns whether a message went out this call:
     * false both when today's slot was already taken and when the send failed.
     *
     * `$now` is the instant the DAY is measured from. The cron injects its own
     * so that one run's two checks, its log line and its alerts all agree about
     * which day it is - a run that straddles midnight must not alert as Sunday
     * and record as Monday. Left null it is the wall clock in UTC.
     */
    public function once(string $key, string $subject, string $text, ?\DateTimeImmutable $now = null): bool
    {
        if (!$this->claim($key, $now)) {
            return false;
        }

        Log::warn('alert', ['key' => $key, 'subject' => $subject]);

        return $this->notifier->office(new Notification(self::SUBJECT_PREFIX . $subject, $text, null, 'alert'));
    }

    /** The same, from a prepared message that carries its own key. */
    public function onceMessage(AlertMessage $alert, ?\DateTimeImmutable $now = null): bool
    {
        return $this->once($alert->key, $alert->subject, $alert->text, $now);
    }

    /** Immediate, not deduplicated: an event that each time deserves a message. */
    public function now(string $subject, string $text): bool
    {
        Log::warn('alert', ['subject' => $subject]);

        return $this->notifier->office(new Notification(self::SUBJECT_PREFIX . $subject, $text, null, 'alert'));
    }

    /** Whether this key has already gone out today. Read-only; `once()` is the one that claims. */
    public function sentToday(string $key, ?\DateTimeImmutable $now = null): bool
    {
        $stored = Db::value(
            'SELECT JSON_UNQUOTE(JSON_EXTRACT(v, :path)) FROM app_settings WHERE k = :k',
            [':path' => self::path($key), ':k' => $this->settingsKey],
        );

        return is_string($stored) && $stored === self::today($now);
    }

    /** The UTC date a key last went out, or null. For reporting, never for deciding. */
    public function lastSent(string $key): ?string
    {
        $stored = Db::value(
            'SELECT JSON_UNQUOTE(JSON_EXTRACT(v, :path)) FROM app_settings WHERE k = :k',
            [':path' => self::path($key), ':k' => $this->settingsKey],
        );

        return is_string($stored) && $stored !== '' ? $stored : null;
    }

    /** Forget every alert date. Used by the admin's "send it again" action and by tests. */
    public function reset(): void
    {
        Db::exec('DELETE FROM app_settings WHERE k = ?', [$this->settingsKey]);
    }

    /**
     * Take today's slot for this key, atomically.
     *
     * Returns true when this call was the one that took it. The whole decision
     * is the affected-row count of one statement: MySQL and MariaDB report 1
     * for an insert, 2 for an update that changed something, and 0 when the row
     * was already exactly what the statement would have written - which is
     * precisely the case where the alert has already gone out today. Hence the
     * IF() around updated_at: without it the timestamp would always change, the
     * count would always be 2, and the deduplication would never fire.
     */
    private function claim(string $key, ?\DateTimeImmutable $at = null): bool
    {
        $path  = self::path($key);
        $today = self::today($at);
        $now   = Clock::sql($at);

        $affected = Db::exec(
            'INSERT INTO app_settings (k, v, updated_at, updated_by)
                  VALUES (:k, JSON_OBJECT(:key, :today_insert), :now_insert, :actor)
             ON DUPLICATE KEY UPDATE
                  updated_at = IF(JSON_UNQUOTE(JSON_EXTRACT(v, :path_when)) = :today_when, updated_at, :now_update),
                  v          = IF(JSON_UNQUOTE(JSON_EXTRACT(v, :path_set))  = :today_set,  v,          JSON_SET(v, :path_write, :today_write))',
            [
                ':k'            => $this->settingsKey,
                ':key'          => $key,
                ':today_insert' => $today,
                ':now_insert'   => $now,
                ':actor'        => 'system',
                ':path_when'    => $path,
                ':today_when'   => $today,
                ':now_update'   => $now,
                ':path_set'     => $path,
                ':today_set'    => $today,
                ':path_write'   => $path,
                ':today_write'  => $today,
            ],
        );

        return $affected > 0;
    }

    /** The UTC calendar day, the unit "once per day" is measured in. */
    private static function today(?\DateTimeImmutable $at = null): string
    {
        return ($at ?? Clock::utcNow())->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
    }

    /**
     * The JSON path for one alert key.
     *
     * The key is validated rather than escaped, and validated against a set
     * that contains neither a quote nor a backslash, so the path it produces
     * cannot be anything but a single member lookup. Keys are constants in this
     * codebase; one arriving from a request is a bug and is refused here.
     */
    private static function path(string $key): string
    {
        if (preg_match('/^[a-z0-9][a-z0-9._:-]{0,62}$/', $key) !== 1) {
            throw new \InvalidArgumentException('An alert key must be 1-63 characters of a-z, 0-9, dot, dash, underscore or colon.');
        }

        return '$."' . $key . '"';
    }
}
