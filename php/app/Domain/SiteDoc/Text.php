<?php

declare(strict_types=1);

namespace Speedat\Domain\SiteDoc;

use Speedat\Core\Money;

/**
 * The two string conversions the site document needs, in one place.
 *
 * `of()` is how a JSON value becomes a stored text field, and `js()` is
 * JavaScript's `String(v)` - which the diff depends on, because the TypeScript
 * compares `String(a[k] ?? "")` with `String(b[k] ?? "")` and prints the
 * results. `String(true)` is "true", not "1"; `String(5000)` is "5000", not
 * "5000.0"; PHP's own casts agree with neither.
 */
final class Text
{
    /** A stored text field: a scalar becomes its string, anything else becomes "". */
    public static function of(mixed $v): string
    {
        if (is_string($v)) {
            return $v;
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v) || is_float($v)) {
            return Money::numberToString((float) $v);
        }

        return '';
    }

    /** JavaScript's `String(v ?? "")`: null and undefined print as "", everything else as JS prints it. */
    public static function js(mixed $v): string
    {
        if ($v === null) {
            return '';
        }

        return self::of($v);
    }

    /**
     * Exactly the characters JavaScript's `\s` matches - the ECMAScript
     * WhiteSpace and LineTerminator sets - as a PCRE character class.
     *
     * Neither PHP's `\s` nor its UCP-aware `\s` is the same set: plain `\s`
     * misses the non-breaking space that arrives in every value pasted out of
     * Word, and even `\s` under `/u` misses U+FEFF, which JavaScript counts as
     * whitespace and Unicode files under "format character". Spelling the set
     * out is the only way the two halves of one product agree on what a blank
     * message is.
     */
    private const JS_SPACE = '\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}'
        . '\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    /**
     * JavaScript's `String.prototype.trim()`: whitespace at both ends, its
     * definition of whitespace.
     */
    public static function trim(string $s): string
    {
        return preg_replace('/^[' . self::JS_SPACE . ']+|[' . self::JS_SPACE . ']+$/u', '', $s) ?? $s;
    }

    /** JavaScript's `replace(/\s+/g, " ")`. */
    public static function collapseSpaces(string $s): string
    {
        return preg_replace('/[' . self::JS_SPACE . ']+/u', ' ', $s) ?? $s;
    }

    /**
     * JavaScript's `slice(0, $units)`, which counts UTF-16 CODE UNITS - not
     * characters and not bytes.
     *
     * This is not a detail: a JavaScript string is UTF-16, so one emoji is two
     * units, and `"🙂".repeat(200).slice(0, 300)` keeps 150 emoji where
     * `mb_substr($s, 0, 300)` keeps 200. The price-hold message is capped with a
     * plain `slice`, so a message cut here has to be cut to the same place, or
     * the admin preview and the public page would disagree about what the
     * customer is being told.
     *
     * One deliberate difference: where the cut would fall between the two halves
     * of a surrogate pair, JavaScript keeps the dangling half and the result is
     * not well-formed text (`isWellFormed()` is false). We stop one unit earlier
     * instead. A lone surrogate cannot be stored in a utf8mb4 column, cannot be
     * rendered, and is not worth being bug-compatible about - so the cut is at
     * most one character shorter than JavaScript's, and always valid.
     */
    public static function sliceUtf16(string $s, int $units): string
    {
        if ($units <= 0) {
            return '';
        }

        $used = 0;
        $out  = '';

        foreach (self::chars($s) as $char) {
            // A character outside the BMP is encoded in four UTF-8 bytes and
            // takes two UTF-16 code units; everything else takes one.
            $width = strlen($char) === 4 ? 2 : 1;

            if ($used + $width > $units) {
                break;
            }

            $out  .= $char;
            $used += $width;
        }

        return $out;
    }

    /** The length JavaScript reports for a string: UTF-16 code units. */
    public static function lengthUtf16(string $s): int
    {
        $n = 0;
        foreach (self::chars($s) as $char) {
            $n += strlen($char) === 4 ? 2 : 1;
        }

        return $n;
    }

    /**
     * JavaScript's `!!v`.
     *
     * PHP and JavaScript disagree about exactly one everyday value: `(bool)"0"`
     * is false in PHP and true in JavaScript. A stored `"live": "0"` therefore
     * takes the rates live on the Next.js site and would have left them as
     * samples here. The document is the same document; the truthiness has to be
     * the same too.
     */
    public static function truthy(mixed $v): bool
    {
        if (is_string($v)) {
            return $v !== '';
        }
        if (is_float($v)) {
            return $v !== 0.0 && !is_nan($v);
        }

        return (bool) $v;
    }

    /** The characters of a UTF-8 string, so lengths and slices count what JavaScript counts. @return list<string> */
    public static function chars(string $s): array
    {
        $out = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);

        return $out === false ? [] : $out;
    }
}
