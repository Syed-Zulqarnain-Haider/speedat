<?php

declare(strict_types=1);

namespace Speedat\Core;

/**
 * Money and the rounding it depends on.
 *
 * The PHP quote must equal the TypeScript quote to the rupee, so the rounding
 * primitives are ported operation for operation rather than rewritten in a
 * more PHP-idiomatic way:
 *
 *   JavaScript Math.round() rounds a half UP, towards +Infinity:
 *       Math.round(2.5) === 3      Math.round(-2.5) === -2
 *   PHP round() rounds a half AWAY FROM ZERO:
 *       round(2.5) === 3.0         round(-2.5) === -3.0
 *
 * The two disagree on negative halves. Prices are never negative, but a
 * difference nobody wrote down is a difference that turns up later in a
 * discount or a credit, so jsRound() below implements the JavaScript rule
 * exactly and every port uses it.
 *
 * PHP floats and JavaScript numbers are both IEEE-754 doubles, so the same
 * operations in the same order give bit-identical results.
 */
final class Money
{
    /** JavaScript's Math.round: half rounds towards +Infinity. */
    public static function jsRound(float $n): float
    {
        if (!is_finite($n)) {
            return $n;
        }

        return floor($n + 0.5);
    }

    /**
     * Port of engine.ts roundTo(): round `n` to the nearest multiple of `r`.
     * A zero or non-numeric step means "to the nearest 1", matching
     * `Number(r) || 1`.
     */
    public static function roundTo(float $n, float $r): float
    {
        $step = is_finite($r) && $r != 0.0 ? $r : 1.0;

        return self::jsRound($n / $step) * $step;
    }

    /** Port of engine.ts kgToG(). Weights are integer grams everywhere inside the engine. */
    public static function kgToG(float $kg): int
    {
        return (int) self::jsRound($kg * 1000);
    }

    /** Port of engine.ts gToKg(): grams to a short kilogram string ("2.5", "12"). */
    public static function gToKg(int $g): string
    {
        $kg = self::jsRound(($g / 1000) * 100) / 100;

        // JavaScript prints 12 as "12" and 2.5 as "2.5"; PHP would print
        // "12" and "2.5" too, but only if the trailing zeros are trimmed.
        $s = rtrim(rtrim(number_format($kg, 3, '.', ''), '0'), '.');

        return $s === '' || $s === '-' ? '0' : $s;
    }

    /** Port of format.ts fmtNum(): thousands separators, rounded to a whole unit. */
    public static function fmtNum(float $n): string
    {
        return number_format(self::jsRound($n), 0, '.', ',');
    }

    /** Port of format.ts fmtMoney(): "PKR 12,500". */
    public static function fmtMoney(float $n, string $currency): string
    {
        return $currency . ' ' . self::fmtNum($n);
    }

    /**
     * A number printed the way JavaScript's String(n) prints it.
     *
     * Every quote line that puts a number inside a sentence - "Charged on 2.5
     * kg", "1 x 0.5 kg", "rounded up to the next 0.5 kg" - goes through a
     * template literal on the TypeScript side, so PHP has to agree about the
     * text as well as the value. PHP's own float-to-string uses the `precision`
     * ini setting and would print 0.1 as "0.1" on one host and "0.100000000000"
     * on another; json_encode() uses the shortest round-trip form, which is
     * what JavaScript does.
     */
    public static function numberToString(float $n): string
    {
        if (is_nan($n)) {
            return 'NaN';
        }

        if (is_infinite($n)) {
            return $n > 0 ? 'Infinity' : '-Infinity';
        }

        // JavaScript prints an integral value without a decimal point.
        if ($n == floor($n) && abs($n) < 1.0e15) {
            return (string) (int) $n;
        }

        $s = json_encode($n);

        return is_string($s) ? $s : (string) $n;
    }

    /**
     * Percentage of an amount, without the float surprise of (n * pct / 100)
     * being evaluated in a different order than the TypeScript does it.
     * engine.ts computes `(base * taxPct) / 100`, so this does too.
     */
    public static function percent(float $amount, float $pct): float
    {
        return ($amount * $pct) / 100;
    }
}
