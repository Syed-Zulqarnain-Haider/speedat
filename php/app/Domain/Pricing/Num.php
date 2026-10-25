<?php

declare(strict_types=1);

namespace Speedat\Domain\Pricing;

/**
 * JavaScript number semantics, on purpose.
 *
 * The TypeScript engine stores rate-card numbers as `number | null |
 * undefined` and asks `isNum(v)` - `typeof v === "number" && isFinite(v)` -
 * before using one. Three states matter and they are NOT the same:
 *
 *   absent / null  -> "not offered", skipped silently
 *   a real number  -> used
 *   present but not a number ("abc", true, [])  -> NOT usable, but `v != null`
 *                     is still true, so validateSite reports it as a bad price
 *                     instead of pretending the field is empty
 *
 * PHP has no `undefined`, so the third state is carried as NAN: it is not
 * null, every comparison against it is false, and is_finite() rejects it -
 * exactly how a non-numeric value behaves on the TypeScript side. Collapsing
 * it to null would silently turn a typo in a price box into "no price", which
 * is the one outcome the admin's validation exists to prevent.
 */
final class Num
{
    /** The TypeScript `isNum`: a real, finite number. */
    public static function isNum(?float $v): bool
    {
        return $v !== null && is_finite($v);
    }

    /**
     * Read a JSON/array value the way the TypeScript reads a rate field.
     *
     * Absent or null stays null; an int or float is taken as it is; a numeric
     * string is converted the way JavaScript's `Number()` would; anything else
     * becomes NAN ("present, but not a price").
     */
    public static function field(mixed $v): ?float
    {
        if ($v === null) {
            return null;
        }

        return self::jsNumber($v);
    }

    /**
     * Port of JavaScript's `Number(v)`.
     *
     *   Number(null)  === 0        Number(undefined) === NaN
     *   Number("")    === 0        Number(" 12.5 ")  === 12.5
     *   Number(true)  === 1        Number("abc")     === NaN
     */
    public static function jsNumber(mixed $v): float
    {
        if ($v === null) {
            return 0.0;
        }

        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }

        if (is_bool($v)) {
            return $v ? 1.0 : 0.0;
        }

        if (is_string($v)) {
            $s = trim($v);

            if ($s === '') {
                return 0.0;
            }

            // JavaScript accepts a leading sign, decimals and exponents.
            // Anything else - including "12px" - is NaN, which is exactly what
            // is_numeric() rejects too. (The 0x/0o/0b literal forms cannot
            // reach a rate document and are deliberately not supported.)
            return is_numeric($s) ? (float) $s : NAN;
        }

        // Arrays and objects: JavaScript would coerce through valueOf/toString;
        // nothing in a rate document does that, so it is not a number.
        return NAN;
    }

    /**
     * Grid maps are read back in JavaScript's own key order: integer-like keys
     * ascending first, then every other key in insertion order. `slabToGrid`
     * returns such a map and the admin stores it, so the PHP twin has to build
     * it in the same order or two identical rate cards serialise differently.
     *
     * @param  array<string,mixed> $map
     * @return array<string,mixed>
     */
    public static function jsKeyOrder(array $map): array
    {
        $indexes = [];
        $rest    = [];

        foreach ($map as $key => $value) {
            $k = (string) $key;

            // "Array index" in the ECMAScript sense: a canonical decimal
            // integer below 2^32-1. "01" and "1.0" are NOT array indexes.
            if (preg_match('/^(0|[1-9][0-9]*)$/', $k) === 1 && (float) $k < 4294967295.0) {
                $indexes[$k] = $value;
            } else {
                $rest[$k] = $value;
            }
        }

        // PHP silently turns "3" into the integer key 3, so the comparator
        // takes int|string and casts.
        uksort($indexes, static fn (int|string $a, int|string $b): int => (int) $a <=> (int) $b);

        return $indexes + $rest;
    }
}
