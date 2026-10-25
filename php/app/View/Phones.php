<?php

declare(strict_types=1);

namespace Speedat\View;

/**
 * Splits an owner-typed line into plain text and phone-number runs, so a
 * content field such as `phone2` ("Mobile: +92 333 766 7076 · +92 334 77 33
 * 328") can print every number as a `tel:` link while the words around it stay
 * as typed. Port of src/components/site/pages/phones.ts.
 *
 * A run counts as a phone number only with at least seven digits, so "9 am -
 * 7 pm" or "Shop 4" stay text.
 */
final class Phones
{
    private const RUN        = '/[+(]?\d[\d ().-]*\d/';
    private const MIN_DIGITS = 7;
    /** An item separator the owner typed between two facts. */
    private const ITEM_SPLIT = '/\s*(?:[·•,;|]|\r?\n)+\s*/u';
    /** Text between two numbers of one item that only joins them. */
    private const JOINER     = '/^\s*(?:or|and|&|\/|-|\x{2013}|\x{2014})?\s*$/iu';

    /** @return list<PhoneSegment> */
    public static function segments(string $text): array
    {
        $out  = [];
        $last = 0;

        if (preg_match_all(self::RUN, $text, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [new PhoneSegment($text)];
        }

        foreach ($matches[0] as $match) {
            [$run, $at] = [$match[0], $match[1]];
            $digits     = preg_replace('/\D/', '', $run) ?? '';

            if (strlen($digits) < self::MIN_DIGITS) {
                continue;
            }
            if ($at > $last) {
                $out[] = new PhoneSegment(substr($text, $last, $at - $last));
            }

            $out[] = new PhoneSegment($run, 'tel:' . (preg_replace('/[^0-9+]/', '', $run) ?? ''));
            $last  = $at + strlen($run);
        }

        if ($last < strlen($text)) {
            $out[] = new PhoneSegment(substr($text, $last));
        }

        return $out;
    }

    /**
     * True when two spellings name one number: "+92 333 766 7076", "0333 766
     * 7076", "923337667076" and "333 7667076" all end in the same digits. The
     * shorter must be the tail of the longer - a local spelling drops the
     * country code, never the other way.
     */
    public static function same(string $a, string $b): bool
    {
        $x = self::digitKey($a);
        $y = self::digitKey($b);

        if (strlen($x) < self::MIN_DIGITS || strlen($y) < self::MIN_DIGITS) {
            return false;
        }

        return $x === $y || str_ends_with($x, $y) || str_ends_with($y, $x);
    }

    /**
     * `segments()` minus the numbers the page already states elsewhere, so
     * /contact's "Also" row never repeats the WhatsApp number or the landline
     * under a second label.
     *
     * The line is read as items parted by the owner's separators: an item whose
     * every number is already stated goes, label and all; an item with a number
     * to keep drops only the stated one and the word joining it; an item with no
     * number stays as typed. A line that loses every number comes back empty,
     * so the caller can skip its row.
     *
     * @param  list<string>       $known
     * @return list<PhoneSegment>
     */
    public static function segmentsExcept(string $text, array $known): array
    {
        $stated = array_values(array_filter(
            $known,
            static fn (string $k): bool => strlen(self::digitKey($k)) >= self::MIN_DIGITS
        ));

        $isStated = static function (PhoneSegment $seg) use ($stated): bool {
            if (!$seg->isPhone()) {
                return false;
            }
            foreach ($stated as $k) {
                if (self::same($seg->text, $k)) {
                    return true;
                }
            }

            return false;
        };

        // A line with nothing to drop comes back exactly as segments() reads it.
        $all = self::segments($text);
        if (!self::some($all, $isStated)) {
            return $all;
        }

        $items = array_values(array_filter(
            array_map('trim', preg_split(self::ITEM_SPLIT, $text) ?: []),
            static fn (string $s): bool => $s !== ''
        ));

        /** @var list<list<PhoneSegment>> $kept */
        $kept = [];

        foreach ($items as $item) {
            $segs = self::segments($item);
            $nums = array_values(array_filter($segs, static fn (PhoneSegment $s): bool => $s->isPhone()));

            if ($nums !== [] && self::every($nums, $isStated)) {
                continue;
            }
            if (!self::some($nums, $isStated)) {
                $kept[] = $segs;
                continue;
            }

            $out  = [];
            $skip = false;
            $n    = count($segs);

            for ($i = 0; $i < $n; $i++) {
                if ($skip) {
                    $skip = false;
                    continue;
                }

                $seg = $segs[$i];
                if (!$isStated($seg)) {
                    $out[] = $seg;
                    continue;
                }

                $next  = $segs[$i + 1] ?? null;
                $after = $segs[$i + 2] ?? null;

                if ($next !== null && !$next->isPhone() && preg_match(self::JOINER, $next->text) === 1 && $after !== null && $after->isPhone()) {
                    $skip = true;
                } else {
                    $prev = $out === [] ? null : $out[count($out) - 1];
                    if ($prev !== null && !$prev->isPhone() && preg_match(self::JOINER, $prev->text) === 1) {
                        array_pop($out);
                    }
                }
            }

            $clean = [];
            $last  = count($out) - 1;
            foreach ($out as $i => $seg) {
                if ($seg->isPhone()) {
                    $clean[] = $seg;
                    continue;
                }
                $t = $seg->text;
                if ($i === 0) {
                    $t = ltrim($t);
                }
                if ($i === $last) {
                    $t = rtrim($t);
                }
                if ($t !== '') {
                    $clean[] = new PhoneSegment($t);
                }
            }

            if ($clean !== []) {
                $kept[] = $clean;
            }
        }

        $joined = [];
        foreach ($kept as $i => $segs) {
            if ($i > 0) {
                $joined[] = new PhoneSegment(' · ');
            }
            foreach ($segs as $seg) {
                $joined[] = $seg;
            }
        }

        return $joined;
    }

    /** The digits of a number as typed, without a leading 0 or 00, so two spellings compare equal. */
    private static function digitKey(string $s): string
    {
        $digits = preg_replace('/\D/', '', $s) ?? '';

        return ltrim($digits, '0');
    }

    /** @param list<PhoneSegment> $segs */
    private static function every(array $segs, callable $test): bool
    {
        foreach ($segs as $seg) {
            if (!$test($seg)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<PhoneSegment> $segs */
    private static function some(array $segs, callable $test): bool
    {
        foreach ($segs as $seg) {
            if ($test($seg)) {
                return true;
            }
        }

        return false;
    }
}
