<?php

declare(strict_types=1);

namespace Speedat\View;

use Speedat\Domain\Pricing\Format;

/**
 * Small parsers for the free-text settings the admin edits. Port of
 * src/lib/site/text.ts.
 */
final class Text
{
    /**
     * `company.originCities` as a list: comma, semicolon or newline separated,
     * trimmed, blanks dropped.
     *
     * @return list<string>
     */
    public static function originCities(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[,;\n]+/', $raw) ?: [] as $part) {
            $city = trim($part);
            if ($city !== '') {
                $out[] = $city;
            }
        }

        return $out;
    }

    /**
     * The pickup promise Services, About and Contact print from
     * `settings.cutoffHour`, worded to match what the calculator actually does:
     * a booking at or after the cutoff hour moves pickup to the next working
     * day. At 0 that test is true at every hour, so the sentence says next-day
     * instead of promising a same-day pickup nothing ever gives. Null (no
     * cutoff) is no sentence. No trailing full stop - the callers add their own.
     */
    public static function cutoffFact(?float $cutoffHour): string
    {
        if ($cutoffHour === null || !is_finite($cutoffHour)) {
            return '';
        }

        if ($cutoffHour <= 0) {
            return 'No same-day pickup; we collect on the next working day';
        }

        return 'Book before ' . Format::fmtHour($cutoffHour) . ' for same-day pickup';
    }

    /** "Lahore", "Lahore and Karachi" - how every page prints the pickup cities. */
    public static function citiesSentence(string $raw): string
    {
        return implode(' and ', self::originCities($raw));
    }

    /**
     * The Google Maps link for the office: the owner's own `mapUrl` when he
     * set one, else a search for the address, else "". Built with
     * `rawurlencode`, so an address with an ampersand cannot add a parameter.
     */
    public static function mapHref(string $mapUrl, string $address): string
    {
        if ($mapUrl !== '') {
            return $mapUrl;
        }

        if ($address === '') {
            return '';
        }

        return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($address);
    }
}
