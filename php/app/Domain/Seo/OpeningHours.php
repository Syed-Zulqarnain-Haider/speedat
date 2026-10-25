<?php

declare(strict_types=1);

namespace Speedat\Domain\Seo;

use Speedat\Domain\Pricing\Engine;

/**
 * `content.hours` - a free-text line the owner types, "Monday to Saturday,
 * 9 am – 7 pm" - turned into schema.org's `openingHoursSpecification`.
 *
 * WHY IT PARSES INSTEAD OF PRINTING THE TEXT
 * schema.org's `openingHours` is typed as Text, so the prose would validate,
 * and Google would read nothing useful out of it. The structured form is what
 * puts opening hours in a knowledge panel.
 *
 * WHY IT REFUSES RATHER THAN GUESSES
 * Every branch that cannot be certain returns null and the property is simply
 * left out. "9 - 7" with no am/pm could be 09:00-19:00 or a typo, and a
 * business that publishes the wrong closing time gets customers at a locked
 * door. An omitted property costs a little rich-result polish; a wrong one
 * costs a visit. `docs/seo.md` says which forms parse, so the owner can type
 * one that does.
 */
final class OpeningHours
{
    /** Full day names in schema.org's spelling, indexed 0 = Monday. */
    private const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    /** What a day token may start with, longest first so "sun" never eats "sunday"'s match. */
    private const PREFIXES = [
        'monday' => 0, 'mon' => 0, 'mo' => 0,
        'tuesday' => 1, 'tues' => 1, 'tue' => 1, 'tu' => 1,
        'wednesday' => 2, 'weds' => 2, 'wed' => 2, 'we' => 2,
        'thursday' => 3, 'thurs' => 3, 'thur' => 3, 'thu' => 3, 'th' => 3,
        'friday' => 4, 'fri' => 4, 'fr' => 4,
        'saturday' => 5, 'sat' => 5, 'sa' => 5,
        'sunday' => 6, 'sun' => 6, 'su' => 6,
    ];

    /**
     * Every line of the hours field that could be understood.
     *
     * @return list<array{dayOfWeek:list<string>,opens:string,closes:string}>
     */
    public static function parse(string $text): array
    {
        $out = [];

        foreach (Engine::lines($text) as $line) {
            $spec = self::parseLine($line);
            if ($spec !== null) {
                $out[] = $spec;
            }
        }

        return $out;
    }

    /** @return array{dayOfWeek:list<string>,opens:string,closes:string}|null */
    public static function parseLine(string $line): ?array
    {
        // Normalise the dashes the owner's keyboard and the site's copy use
        // (hyphen, en dash, em dash, minus) to one separator before matching.
        $normalised = str_replace(["\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2212}"], '-', $line);

        $time = '(\d{1,2})(?::(\d{2}))?\s*(a\.?m\.?|p\.?m\.?)?';
        $gap  = '\s*(?:-|\bto\b|\btill\b|\buntil\b|\bthrough\b)\s*';

        if (preg_match('/' . $time . $gap . $time . '/i', $normalised, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $opens  = self::time($m[1][0], $m[2][0] ?? '', $m[3][0] ?? '');
        $closes = self::time($m[4][0], $m[5][0] ?? '', $m[6][0] ?? '');

        if ($opens === null || $closes === null) {
            return null;
        }

        // Both bare numbers and the second smaller than the first: "9 - 7" is
        // either 09:00-19:00 or a mistake, and this class does not gamble.
        $bare = ($m[3][0] ?? '') === '' && ($m[6][0] ?? '') === '';
        if ($bare && $closes <= $opens) {
            return null;
        }

        $days = self::days(substr($normalised, 0, (int) $m[0][1]));
        if ($days === []) {
            return null;
        }

        return [
            'dayOfWeek' => $days,
            'opens'     => self::hhmm($opens),
            'closes'    => self::hhmm($closes),
        ];
    }

    /**
     * The days named before the time range: a range ("Monday to Saturday"), a
     * list ("Monday, Wednesday and Friday") or one day.
     *
     * @return list<string>
     */
    private static function days(string $before): array
    {
        if (preg_match_all('/[A-Za-z]+/', $before, $words) === false || $words[0] === []) {
            return [];
        }

        /** @var list<int> $found */
        $found      = [];
        $rangeWords = false;

        foreach ($words[0] as $word) {
            $lower = strtolower($word);

            if (in_array($lower, ['to', 'till', 'until', 'through'], true) && $found !== []) {
                $rangeWords = true;
                continue;
            }

            $index = self::dayIndex($lower);
            if ($index !== null) {
                $found[] = $index;
            }
        }

        if ($found === []) {
            return [];
        }

        // "Monday - Saturday" and "Monday to Saturday" are both ranges; the
        // dash was normalised out of $before's words, so a two-day line with no
        // joining word is read as a range too, which is what "Mon-Sat" means.
        $isRange = count($found) === 2 && ($rangeWords || str_contains($before, '-'));

        if ($isRange) {
            $out = [];
            for ($i = $found[0]; ; $i = ($i + 1) % 7) {
                $out[] = self::DAYS[$i];
                if ($i === $found[1]) {
                    break;
                }
                if (count($out) >= 7) {
                    break;
                }
            }

            return $out;
        }

        $out = [];
        foreach ($found as $index) {
            $name = self::DAYS[$index];
            if (!in_array($name, $out, true)) {
                $out[] = $name;
            }
        }

        return $out;
    }

    private static function dayIndex(string $word): ?int
    {
        foreach (self::PREFIXES as $prefix => $index) {
            if ($word === $prefix) {
                return $index;
            }
        }

        return null;
    }

    /** Minutes since midnight, or null when the clock face is impossible. */
    private static function time(string $hour, string $minute, string $meridiem): ?int
    {
        $h = (int) $hour;
        $m = $minute === '' ? 0 : (int) $minute;

        if ($m > 59) {
            return null;
        }

        $mer = strtolower(str_replace('.', '', $meridiem));

        if ($mer === 'am' || $mer === 'pm') {
            if ($h < 1 || $h > 12) {
                return null;
            }
            $h %= 12;
            if ($mer === 'pm') {
                $h += 12;
            }
        } elseif ($h > 23) {
            return null;
        }

        return $h * 60 + $m;
    }

    private static function hhmm(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
