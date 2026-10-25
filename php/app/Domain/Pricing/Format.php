<?php

declare(strict_types=1);

namespace Speedat\Domain\Pricing;

use Speedat\Core\Clock;
use Speedat\Core\Money;
use Speedat\Core\Str;

/**
 * Display formatting shared by the site, the WhatsApp message and the admin.
 * Port of src/lib/pricing/format.ts.
 *
 * WHY THE MONTH NAMES ARE A TABLE AND NOT A LOCALE CALL
 * The TypeScript formats dates with `toLocaleDateString("en-GB", ...)`, which
 * asks ICU. ICU says "Sept" for September in en-GB and "Sep" in en-US, and it
 * has changed its mind between CLDR releases. Hostinger's PHP may not have
 * intl at all, and even where it does, its ICU is a different version from the
 * browser's. A shared hosting upgrade must not silently re-word a delivery
 * date, so the eleven strings that matter are written down here, in both the
 * PHP and the JavaScript twin, and pinned by the golden vectors.
 */
final class Format
{
    /** en-GB short month names as ICU spells them. September is "Sept", not "Sep". */
    private const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sept', 'Oct', 'Nov', 'Dec'];

    /** en-GB short weekday names, indexed the way Date#getDay() is. */
    private const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    public static function fmtNum(float $n): string
    {
        return Money::fmtNum($n);
    }

    public static function fmtMoney(float $n, string $currency): string
    {
        return Money::fmtMoney($n, $currency);
    }

    /** "Wed 23 Sept". */
    public static function fmtDay(CivilDate $d): string
    {
        return self::WEEKDAYS[$d->dayOfWeek()] . ' ' . $d->d . ' ' . self::MONTHS[$d->m - 1];
    }

    /** "Wed 23 Sept - Fri 25 Sept", collapsed to one day when the window is one day. */
    public static function fmtRange(DeliveryEstimate $est): string
    {
        return $est->from->equals($est->to)
            ? self::fmtDay($est->from)
            : self::fmtDay($est->from) . " \u{2013} " . self::fmtDay($est->to);
    }

    /** "23 Sept 2026", or "" when the text is not a date. */
    public static function fmtDate(string $iso): string
    {
        $dt = self::parseJsDate($iso);

        return $dt === null ? '' : (int) $dt->format('j') . ' ' . self::MONTHS[(int) $dt->format('n') - 1] . ' ' . $dt->format('Y');
    }

    /** "23 Sept 2026, 14:05", or "" when the text is not a date. */
    public static function fmtDateTime(string $iso): string
    {
        $dt = self::parseJsDate($iso);

        return $dt === null
            ? ''
            : (int) $dt->format('j') . ' ' . self::MONTHS[(int) $dt->format('n') - 1] . ' ' . $dt->format('Y') . ', ' . $dt->format('H:i');
    }

    /** "923157667076" becomes "+92 315 766 7076"; anything else becomes "+<digits>". */
    public static function fmtPhone(?string $n): string
    {
        $s = (string) $n;

        return preg_match('/^92(3\d{2})(\d{3})(\d{4})$/', $s, $m) === 1
            ? '+92 ' . $m[1] . ' ' . $m[2] . ' ' . $m[3]
            : '+' . $s;
    }

    /** 15 becomes "3 pm", 0 becomes "12 am", null becomes "". */
    public static function fmtHour(?float $h): string
    {
        if ($h === null || !is_finite($h)) {
            return '';
        }

        $ap = $h >= 12 ? 'pm' : 'am';
        $x  = fmod($h, 12);

        return ($x == 0.0 ? '12' : Money::numberToString($x)) . ' ' . $ap;
    }

    /** A calendar day as `<input type="date">` wants it. */
    public static function inputDate(CivilDate $d): string
    {
        return $d->key();
    }

    /** "YYYY-MM-DD" becomes a calendar day, or null when malformed. */
    public static function localDateFromInput(?string $v): ?CivilDate
    {
        return CivilDate::fromIsoDate($v);
    }

    /**
     * Quote reference such as SP-260920-K7QX: date-stamped, short enough to
     * read out on the phone.
     *
     * @param float $rand 0..1, the value `Math.random()` would have returned
     */
    public static function quoteId(CivilDate $now, float $rand): string
    {
        $tail = strtoupper(substr(self::toBase36($rand), 2, 4));
        $tail = str_pad($tail, 4, '0', STR_PAD_RIGHT);

        return sprintf('SP-%02d%02d%02d-%s', $now->y % 100, $now->m, $now->d, $tail);
    }

    /**
     * Deterministic quote id for a seed: the same destination, weight and
     * service show the same id across re-renders without any stored state.
     *
     * FNV-1a over UTF-16 code units, exactly as format.ts does it with
     * `charCodeAt` and `Math.imul`.
     */
    public static function quoteIdFor(string $seed, CivilDate $now): string
    {
        $h = 0x811C9DC5;

        foreach (self::utf16Units($seed) as $unit) {
            $h ^= $unit;
            $h = ($h * 0x01000193) & 0xFFFFFFFF;
        }

        $tail = str_pad(strtoupper(base_convert((string) ($h % 1679616), 10, 36)), 4, '0', STR_PAD_LEFT);

        return substr(self::quoteId($now, 0.0), 0, -4) . $tail;
    }

    /** Lower-case, accent-free key used to match destination names across sheets. */
    public static function nameKey(?string $name): string
    {
        return Str::nameKey($name);
    }

    /** URL/ID-safe slug, ASCII only, at most 40 characters. */
    public static function slug(string $name): string
    {
        return Str::slug($name);
    }

    /* ------------------------------------------------------------ internals */

    /**
     * Parse a string the way `new Date(string)` does, then read it in the
     * application's own time zone.
     *
     * The one rule worth spelling out: a bare "YYYY-MM-DD" is UTC midnight in
     * JavaScript, while PHP would read it as local midnight. In Karachi that is
     * a five-hour difference, which is enough to print the wrong day either
     * side of midnight, so the JavaScript rule is implemented explicitly.
     */
    private static function parseJsDate(string $iso): ?\DateTimeImmutable
    {
        $s = trim($iso);
        if ($s === '') {
            return null;
        }

        $zone = Clock::zone();

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1) {
                $dt = new \DateTimeImmutable($s . 'T00:00:00', new \DateTimeZone('UTC'));
            } else {
                // A date-time with no offset is local time in JavaScript too.
                $dt = new \DateTimeImmutable($s, $zone);
            }
        } catch (\Exception) {
            return null;
        }

        // PHP accepts a lot of text JavaScript would reject ("now", "tomorrow").
        // Only ISO-8601-looking input may produce a date here.
        if (preg_match('/^\d{4}-\d{2}-\d{2}([T ].*)?$/', $s) !== 1) {
            return null;
        }

        return $dt->setTimezone($zone);
    }

    /** `Math.random().toString(36)` for a known value: "0.123456" style base-36 text. */
    private static function toBase36(float $rand): string
    {
        if ($rand <= 0.0) {
            return '0';
        }

        $out   = '0.';
        $digits = '0123456789abcdefghijklmnopqrstuvwxyz';
        $x     = $rand;

        for ($i = 0; $i < 12 && $x > 0.0; $i++) {
            $x *= 36;
            $d  = (int) floor($x);
            $out .= $digits[$d] ?? '0';
            $x  -= $d;
        }

        return $out;
    }

    /**
     * UTF-16 code units of a string, which is what `charCodeAt` returns.
     *
     * @return list<int>
     */
    private static function utf16Units(string $s): array
    {
        if ($s === '') {
            return [];
        }

        $bytes = mb_convert_encoding($s, 'UTF-16LE', 'UTF-8');
        $out   = [];

        for ($i = 0, $n = strlen($bytes); $i + 1 < $n; $i += 2) {
            $out[] = ord($bytes[$i]) | (ord($bytes[$i + 1]) << 8);
        }

        return $out;
    }
}
