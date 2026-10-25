<?php

declare(strict_types=1);

namespace Speedat\Http\Admin;

use Speedat\Core\Clock;

/**
 * A stored DATETIME as the ISO string the admin view models read.
 *
 * WHY THIS ONE-LINE CLASS EXISTS. Every column in this database holds UTC
 * (db/migrations/0001_init.sql, "Every timestamp is DATETIME holding UTC"), and
 * `Pricing\Format::fmtDateTime()` - which every admin view model prints times
 * with - parses a string WITHOUT an offset as local time, exactly as JavaScript
 * does. Hand it `2026-09-27 08:00:00` straight out of MySQL and it prints 08:00
 * where the owner's clock says 13:00: five hours wrong, on every timestamp on
 * every admin screen, and wrong in the direction that makes "published today"
 * read as this morning when it was lunchtime.
 *
 * So nothing in `Http\Admin` passes a raw column to a view model. It goes
 * through here, which reads it as UTC (Clock::fromSql) and prints it with its
 * offset, which is the one form both PHP and the JavaScript-compatible parser
 * agree about.
 */
final class Iso
{
    /** A DATETIME column as ISO 8601 with an offset, or "" when it is not a date. */
    public static function of(mixed $sql): string
    {
        if (!is_string($sql) && !is_int($sql)) {
            return '';
        }

        return Clock::fromSql((string) $sql)?->format('c') ?? '';
    }

    /** Now, in the same shape. */
    public static function now(): string
    {
        return Clock::now()->format('c');
    }
}
