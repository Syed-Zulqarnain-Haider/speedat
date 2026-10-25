<?php

declare(strict_types=1);

namespace Speedat\Domain\Pricing;

/**
 * Transit times and delivery estimates. Port of src/lib/pricing/dates.ts.
 *
 * Pure: `now` is always passed in, never read from the clock here, so the
 * server, the browser and the tests all agree about what day it is.
 */
final class Dates
{
    /** JavaScript's Date#getDay() order. */
    private const DAY_NAMES = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    /** Guards copied from dates.ts so a pathological document cannot spin forever. */
    private const PICKUP_GUARD  = 60;
    private const TRANSIT_GUARD = 400;

    /**
     * "3-5", "3 to 5 days", "4" become [min, max]; nothing numeric becomes null.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function parseDaysRange(?string $str): ?array
    {
        if (preg_match_all('/\d+/', (string) $str, $m) === 0 || $m[0] === []) {
            return null;
        }

        $a = (int) $m[0][0];
        $b = isset($m[0][1]) ? (int) $m[0][1] : $a;

        return [min($a, $b), max($a, $b)];
    }

    /** Normalise a typed transit time: "3 to 5 working days" becomes "3-5" (with an en dash). */
    public static function parseDays(mixed $v): string
    {
        $s = trim(is_scalar($v) ? (string) $v : '');
        if ($s === '') {
            return '';
        }

        $s = preg_replace('/\s*(working|business)?\s*days?\s*$/iu', '', $s) ?? $s;
        $s = preg_replace('/\s*(-|to|\x{2013}|\x{2014})\s*/u', "\u{2013}", $s) ?? $s;

        return trim($s);
    }

    /**
     * Working days as a set of JavaScript day indexes (0 = Sunday). Ranges
     * such as "mon-sat" and "Monday to Friday" are expanded first; nothing
     * recognisable falls back to Monday-Friday.
     *
     * @return list<int> sorted ascending
     */
    public static function workingSet(Settings|string $settings): array
    {
        $txt = mb_strtolower(is_string($settings) ? $settings : $settings->workingDays, 'UTF-8');

        $txt = preg_replace_callback(
            '/(sun|mon|tue|wed|thu|fri|sat)[a-z]*\s*(?:-|\x{2013}|\x{2014}|to|through|till|until)\s*(sun|mon|tue|wed|thu|fri|sat)[a-z]*/u',
            static function (array $m): string {
                $i   = array_search($m[1], self::DAY_NAMES, true);
                $j   = array_search($m[2], self::DAY_NAMES, true);
                $out = [];

                if ($i === false || $j === false) {
                    return $m[0];
                }

                $guard = 0;
                while ($guard++ < 8) {
                    $out[] = self::DAY_NAMES[$i];
                    if ($i === $j) {
                        break;
                    }
                    $i = ($i + 1) % 7;
                }

                return implode(' ', $out);
            },
            $txt,
        ) ?? $txt;

        $set = [];
        foreach (preg_split('/[^a-z]+/', $txt) ?: [] as $token) {
            $i = array_search(substr($token, 0, 3), self::DAY_NAMES, true);
            if ($i !== false) {
                $set[$i] = true;
            }
        }

        if ($set === []) {
            $set = [1 => true, 2 => true, 3 => true, 4 => true, 5 => true];
        }

        $out = array_keys($set);
        sort($out);

        /** @var list<int> $out */
        return $out;
    }

    /**
     * Holidays as "YYYY-MM-DD" keys. Lines that do not start with a date are
     * ignored - `badHolidayLines()` is what reports them.
     *
     * @return array<string,true>
     */
    public static function holidaySet(Settings|string $settings): array
    {
        $text = is_string($settings) ? $settings : $settings->holidays;
        $out  = [];

        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim($line), $m) === 1) {
                $out[$m[1] . '-' . $m[2] . '-' . $m[3]] = true;
            }
        }

        return $out;
    }

    /**
     * Holiday lines that are not `YYYY-MM-DD` (optionally followed by a name).
     *
     * @return list<string>
     */
    public static function badHolidayLines(Settings|string $settings): array
    {
        $text = is_string($settings) ? $settings : $settings->holidays;
        $out  = [];

        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '' && preg_match('/^\d{4}-\d{2}-\d{2}(\s*\|.*)?$/', $trimmed) !== 1) {
                $out[] = $trimmed;
            }
        }

        return $out;
    }

    /**
     * When the parcel can be collected and when it should arrive.
     *
     * @param string|null    $daysStr    transit time as typed, e.g. "3-5"
     * @param CivilDate|null $shipDate   the day the customer asked for; never earlier than today
     * @param CivilDate      $today      the calendar day of `now`
     * @param int            $nowHour    the hour of `now`, 0-23, for the cutoff
     * @param float|null     $cutoffHour same-day cutoff; null = none
     */
    public static function estimateDelivery(
        ?string $daysStr,
        Settings|string $workingDays,
        ?float $cutoffHour,
        string $holidays,
        ?CivilDate $shipDate,
        CivilDate $today,
        int $nowHour,
    ): ?DeliveryEstimate {
        $range = self::parseDaysRange($daysStr);
        if ($range === null) {
            return null;
        }

        $ws  = array_flip(self::workingSet($workingDays));
        $hol = self::holidaySet($holidays);

        $working = static fn (CivilDate $d): bool => isset($ws[$d->dayOfWeek()]) && !isset($hol[$d->key()]);

        $start = $shipDate ?? $today;
        if ($start->isBefore($today)) {
            $start = $today;
        }

        $moved = false;

        if ($start->equals($today) && $cutoffHour !== null && is_finite($cutoffHour) && $nowHour >= $cutoffHour) {
            $start = $start->addDays(1);
            $moved = true;
        }

        $guard = 0;
        while (!$working($start) && $guard++ < self::PICKUP_GUARD) {
            $start = $start->addDays(1);
            $moved = true;
        }

        return new DeliveryEstimate(
            pickup: $start,
            from: self::addWorkingDays($start, $range[0], $working),
            to: self::addWorkingDays($start, $range[1], $working),
            moved: $moved,
            minDays: $range[0],
            maxDays: $range[1],
        );
    }

    /** The same estimate, read straight off a settings object - what the site actually calls. */
    public static function estimateFor(
        Settings $settings,
        ?string $daysStr,
        ?CivilDate $shipDate,
        CivilDate $today,
        int $nowHour,
    ): ?DeliveryEstimate {
        return self::estimateDelivery(
            $daysStr,
            $settings->workingDays,
            $settings->cutoffHour,
            $settings->holidays,
            $shipDate,
            $today,
            $nowHour,
        );
    }

    /** @param \Closure(CivilDate): bool $working */
    private static function addWorkingDays(CivilDate $from, int $n, \Closure $working): CivilDate
    {
        $x      = $from;
        $count  = 0;
        $guard  = 0;

        while ($count < $n && $guard++ < self::TRANSIT_GUARD) {
            $x = $x->addDays(1);
            if ($working($x)) {
                $count++;
            }
        }

        return $x;
    }
}
