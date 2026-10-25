<?php

declare(strict_types=1);

namespace Speedat\Core;

/**
 * Time, in one place, so tests can move it and nothing else has to know.
 *
 * TWO TIME ZONES, DELIBERATELY:
 *   - PHP runs in the display zone (Asia/Karachi). Delivery estimates, cut-off
 *     times and anything a customer reads are computed there.
 *   - The database runs in UTC and every stored timestamp is UTC. Db pins the
 *     connection to +00:00 on connect, so CURRENT_TIMESTAMP is UTC too.
 *
 * Mixing the two is how you get off-by-one delivery dates, so the conversion
 * happens here and only here: sql() writes UTC, fromSql() reads UTC back and
 * hands it over in the display zone.
 */
final class Clock
{
    public const SQL_FORMAT = 'Y-m-d H:i:s';

    /** Frozen time for tests; null means "ask the operating system". */
    private static ?\DateTimeImmutable $frozen = null;

    /** Now, in the application's display time zone. */
    public static function now(): \DateTimeImmutable
    {
        return (self::$frozen ?? new \DateTimeImmutable('now'))->setTimezone(self::zone());
    }

    /** Now, in UTC. Everything that touches the database uses this. */
    public static function utcNow(): \DateTimeImmutable
    {
        return (self::$frozen ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('UTC'));
    }

    /** Unix seconds. */
    public static function timestamp(): int
    {
        return self::utcNow()->getTimestamp();
    }

    /** Unix milliseconds - the unit the rate limiter and the form tokens use. */
    public static function millis(): int
    {
        $t = self::$frozen ?? new \DateTimeImmutable('now');

        return (int) $t->format('U') * 1000 + (int) floor(((int) $t->format('u')) / 1000);
    }

    /** The application display zone, from config, defaulting to Asia/Karachi. */
    public static function zone(): \DateTimeZone
    {
        $name = Config::loaded() ? Config::str('app.timezone', 'Asia/Karachi') : 'Asia/Karachi';

        try {
            return new \DateTimeZone($name !== '' ? $name : 'Asia/Karachi');
        } catch (\Exception) {
            return new \DateTimeZone('Asia/Karachi');
        }
    }

    /** Format for a DATETIME column: always UTC. */
    public static function sql(?\DateTimeInterface $at = null): string
    {
        $t = $at === null
            ? self::utcNow()
            : \DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone('UTC'));

        return $t->format(self::SQL_FORMAT);
    }

    /** Read a DATETIME column back. The value in the column is UTC; the result is display-zone. */
    public static function fromSql(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $t = \DateTimeImmutable::createFromFormat(self::SQL_FORMAT, substr(trim($value), 0, 19), new \DateTimeZone('UTC'));

        return $t === false ? null : $t->setTimezone(self::zone());
    }

    /** Stop time for a test. Pass null to start it again. */
    public static function freeze(\DateTimeImmutable|string|null $at): void
    {
        if ($at === null) {
            self::$frozen = null;

            return;
        }

        self::$frozen = is_string($at) ? new \DateTimeImmutable($at) : $at;
    }

    public static function isFrozen(): bool
    {
        return self::$frozen !== null;
    }
}
