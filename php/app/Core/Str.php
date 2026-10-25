<?php

declare(strict_types=1);

namespace Speedat\Core;

/**
 * String helpers. The two that matter are slug() and nameKey(), which are
 * faithful ports of src/lib/pricing/format.ts - they decide whether a
 * destination in an uploaded rate sheet matches a destination already on the
 * site ("Turkiye" = "Türkiye" = "TURKIYE"). A drift here silently creates
 * duplicate destinations, so phase 2 pins both with golden tests against the
 * TypeScript output.
 */
final class Str
{
    /** Set only by withoutIntl(), so the fallback table can be tested on a machine that has intl. */
    private static bool $pretendNoIntl = false;

    /**
     * Run $work with the intl extension treated as absent.
     *
     * The production host may or may not have intl, and the half of stripMarks
     * that runs without it would otherwise never be exercised where anyone can
     * see it fail. The tests wrap the accent cases in this.
     *
     * @template T
     * @param  \Closure(): T $work
     * @return T
     */
    public static function withoutIntl(\Closure $work): mixed
    {
        self::$pretendNoIntl = true;

        try {
            return $work();
        } finally {
            self::$pretendNoIntl = false;
        }
    }

    /** Cryptographically random hex, for session tokens and ids. */
    public static function token(int $bytes = 32): string
    {
        return bin2hex(random_bytes(max(1, $bytes)));
    }

    /** URL-safe base64 without padding, for CSRF nonces and CSP nonces. */
    public static function random(int $bytes = 16): string
    {
        return rtrim(strtr(base64_encode(random_bytes(max(1, $bytes))), '+/', '-_'), '=');
    }

    /**
     * Lower-case, accent-free key used to match destination names across
     * sheets. Port of format.ts nameKey().
     */
    public static function nameKey(?string $name): string
    {
        $s = self::stripMarks((string) $name);
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '';

        return trim($s);
    }

    /**
     * URL/ID-safe slug, ASCII only, max 40 characters. Port of format.ts
     * slug(), including the "dest" fallback for a name with nothing ASCII in it.
     */
    public static function slug(string $name): string
    {
        $s = mb_strtolower($name, 'UTF-8');
        $s = self::stripMarks($s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $s = trim($s, '-');
        $s = substr($s, 0, 40);

        return $s !== '' ? $s : 'dest';
    }

    /**
     * Decompose and drop combining marks - the PHP equivalent of
     * `.normalize("NFD").replace(/[̀-ͯ]/g, "")`.
     *
     * intl is not guaranteed on shared hosting, and without it a precomposed
     * "ü" would survive to become a space: "Türkiye" would key as "t rkiye"
     * and the importer would create a second United Kingdom rather than update
     * the first. So when \Normalizer is absent the table below stands in. It
     * is not a transliteration - it is the real canonical decomposition of
     * every Latin letter that has one, generated from intl itself. Letters
     * with NO canonical decomposition are deliberately absent: "Ø", "Æ", "ß"
     * and "Þ" stay as they are, because that is what the TypeScript does.
     */
    public static function stripMarks(string $s): string
    {
        if (!self::$pretendNoIntl && class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($s, \Normalizer::FORM_D);
            if (is_string($decomposed)) {
                return preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $decomposed;
            }
        }

        return self::foldWithoutIntl($s);
    }

    /**
     * The intl-free half of stripMarks. Public so the tests can exercise it on
     * a machine that does have intl - otherwise the fallback would only ever
     * run on the server, where nobody would notice it drifting.
     */
    public static function foldWithoutIntl(string $s): string
    {
        $folded = strtr($s, self::foldTable());

        return preg_replace('/\p{Mn}+/u', '', $folded) ?? $folded;
    }

    /**
     * Latin letters that have a canonical decomposition, and the base letter
     * each one decomposes to. Two parallel strings rather than 488 array
     * literals; the map is built once per request.
     *
     * @return array<string,string>
     */
    private static function foldTable(): array
    {
        static $map = null;

        if ($map === null) {
            $from = 'ÀÁÂÃÄÅÇÈÉÊËÌÍÎÏÑÒÓÔÕÖÙÚÛÜÝàáâãäåçèéêëìíîïñòóôõöùúûüýÿĀāĂăĄąĆ'
                . 'ćĈĉĊċČčĎďĒēĔĕĖėĘęĚěĜĝĞğĠġĢģĤĥĨĩĪīĬĭĮįİĴĵĶķĹĺĻļĽľŃńŅņŇňŌōŎŏŐő'
                . 'ŔŕŖŗŘřŚśŜŝŞşŠšŢţŤťŨũŪūŬŭŮůŰűŲųŴŵŶŷŸŹźŻżŽžƠơƯưǍǎǏǐǑǒǓǔǕǖǗǘǙǚǛ'
                . 'ǜǞǟǠǡǦǧǨǩǪǫǬǭǰǴǵǸǹǺǻȀȁȂȃȄȅȆȇȈȉȊȋȌȍȎȏȐȑȒȓȔȕȖȗȘșȚțȞȟȦȧȨȩȪȫȬȭȮȯ'
                . 'ȰȱȲȳḀḁḂḃḄḅḆḇḈḉḊḋḌḍḎḏḐḑḒḓḔḕḖḗḘḙḚḛḜḝḞḟḠḡḢḣḤḥḦḧḨḩḪḫḬḭḮḯḰḱḲḳḴḵḶḷ'
                . 'ḸḹḺḻḼḽḾḿṀṁṂṃṄṅṆṇṈṉṊṋṌṍṎṏṐṑṒṓṔṕṖṗṘṙṚṛṜṝṞṟṠṡṢṣṤṥṦṧṨṩṪṫṬṭṮṯṰṱṲṳ'
                . 'ṴṵṶṷṸṹṺṻṼṽṾṿẀẁẂẃẄẅẆẇẈẉẊẋẌẍẎẏẐẑẒẓẔẕẖẗẘẙẠạẢảẤấẦầẨẩẪẫẬậẮắẰằẲẳẴẵ'
                . 'ẶặẸẹẺẻẼẽẾếỀềỂểỄễỆệỈỉỊịỌọỎỏỐốỒồỔổỖỗỘộỚớỜờỞởỠỡỢợỤụỦủỨứỪừỬửỮữỰự'
                . 'ỲỳỴỵỶỷỸỹ';

            $to = 'AAAAAACEEEEIIIINOOOOOUUUUYaaaaaaceeeeiiiinooooouuuuyyAaAaAaC'
                . 'cCcCcCcDdEeEeEeEeEeGgGgGgGgHhIiIiIiIiIJjKkLlLlLlNnNnNnOoOoOo'
                . 'RrRrRrSsSsSsSsTtTtUuUuUuUuUuUuWwYyYZzZzZzOoUuAaIiOoUuUuUuUuU'
                . 'uAaAaGgKkOoOojGgNnAaAaAaEeEeIiIiOoOoRrRrUuUuSsTtHhAaEeOoOoOo'
                . 'OoYyAaBbBbBbCcDdDdDdDdDdEeEeEeEeEeFfGgHhHhHhHhHhIiIiKkKkKkLl'
                . 'LlLlLlMmMmMmNnNnNnNnOoOoOoOoPpPpRrRrRrRrSsSsSsSsSsTtTtTtTtUu'
                . 'UuUuUuUuVvVvWwWwWwWwWwXxXxYyZzZzZzhtwyAaAaAaAaAaAaAaAaAaAaAa'
                . 'AaEeEeEeEeEeEeEeEeIiIiOoOoOoOoOoOoOoOoOoOoOoOoUuUuUuUuUuUuUu'
                . 'YyYyYyYy';

            $letters = mb_str_split($from, 1, 'UTF-8');
            $map     = [];

            foreach ($letters as $i => $letter) {
                $map[$letter] = $to[$i];
            }
        }

        return $map;
    }

    /**
     * Drop control characters, collapse whitespace runs, trim. What every text
     * input gets before storage.
     *
     * WHY THE CONTROL STEP IS HERE. Request::clean() repairs malformed UTF-8,
     * because utf8mb4 plus STRICT_TRANS_TABLES turns a broken byte into a 500.
     * It does NOT touch the C0 block, and neither did this method: `\s` in PCRE
     * matches tab, newline, carriage return, form feed and vertical tab, and
     * nothing else below U+0020. So a NUL, an ESC or any other C0 byte walked
     * through post(), through Caps, through here, and was STORED - a name in
     * `leads.name` reading "Ali\0 Khan" that is one string in the database, a
     * different string in a CSV export, and a truncated string to anything that
     * treats NUL as the end of a C string.
     *
     * They are DELETED, not turned into spaces. A control character is not a
     * word separator, and substituting one would invent spacing the visitor
     * never typed. The five that ARE whitespace (tab, LF, CR, FF, VT) are left
     * for the collapse below, which turns each run into a single space - so a
     * field that was pasted from a spreadsheet still reads as one line.
     *
     * This is squish()'s job and not clean()'s on purpose: clean() also feeds
     * the textareas, where a newline is content, and squish() is precisely the
     * helper that says "this field is one line of text".
     */
    public static function squish(?string $s): string
    {
        // C0 minus the five whitespace ones, plus DEL. Byte-wise, which is safe
        // on UTF-8: no continuation byte is below 0x80.
        $s = preg_replace('/[\x00-\x08\x0E-\x1F\x7F]+/', '', (string) $s) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    }

    /** Hard limit on a stored string, counted in characters, never cutting a multibyte character in half. */
    public static function limit(?string $s, int $max): string
    {
        $s = (string) $s;

        return mb_strlen($s, 'UTF-8') <= $max ? $s : mb_substr($s, 0, $max, 'UTF-8');
    }

    /** Conservative address check. Rejecting a valid address is worse than accepting a bounce, so this stays loose. */
    public static function isEmail(?string $s): bool
    {
        $s = trim((string) $s);

        return $s !== '' && mb_strlen($s, 'UTF-8') <= 254 && filter_var($s, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** Normalised form used as the admins table key: trimmed and lower-cased. */
    public static function email(?string $s): string
    {
        return mb_strtolower(trim((string) $s), 'UTF-8');
    }

    /** "someone@example.com" -> "s*****e@example.com", for logs and audit detail. */
    public static function maskEmail(?string $s): string
    {
        $s  = (string) $s;
        $at = strrpos($s, '@');
        if ($at === false || $at < 1) {
            return $s === '' ? '' : '***';
        }

        $local  = substr($s, 0, $at);
        $domain = substr($s, $at);

        if (mb_strlen($local, 'UTF-8') <= 2) {
            return str_repeat('*', 5) . $domain;
        }

        return mb_substr($local, 0, 1, 'UTF-8') . str_repeat('*', 5) . mb_substr($local, -1, 1, 'UTF-8') . $domain;
    }

    /** Constant-time comparison that tolerates null, for tokens arriving from a request. */
    public static function secureEquals(?string $known, ?string $given): bool
    {
        if ($known === null || $given === null || $known === '') {
            return false;
        }

        return hash_equals($known, $given);
    }
}
