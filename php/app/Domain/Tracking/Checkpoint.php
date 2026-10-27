<?php

declare(strict_types=1);

namespace Speedat\Domain\Tracking;

/**
 * One carrier scan, already made safe to show a stranger: a time, a place and
 * one of our own stage words. Nothing the carrier wrote in prose survives into
 * this object except through fingerprint(), which is a one-way hash.
 */
final class Checkpoint
{
    /** The longest city kept. tracking_checkpoints.city is 80; a city is never near it. */
    private const MAX_CITY = 60;

    public function __construct(
        /** UTC. */
        public readonly \DateTimeImmutable $at,
        public readonly string $city,
        /** ISO 3166-1 alpha-2 or ''. */
        public readonly string $country,
        /** One of Stage::ALL. */
        public readonly string $stage,
        /** sha1 hex of what the provider sent, so the same scan is stored once. */
        public readonly string $fingerprint,
    ) {
    }

    /**
     * A place name a customer may read.
     *
     * A carrier's location field is usually "DUBAI - UAE" or "Lahore, PK", but
     * it is free text and has been seen carrying a facility's street address or
     * a phone number. So: anything with an @ in it goes, anything after a
     * digit is dropped (postcodes, house
     * numbers, phones all start with one), only letters, spaces and . , ' - ( )
     * are kept, the result is title-cased, and it is cut to sixty characters.
     * An empty answer is a normal answer: the timeline then shows the country.
     */
    public static function place(string $raw): string
    {
        $raw = (string) preg_replace('/\S*@\S*/u', ' ', $raw);
        $raw = (string) preg_replace('/\d.*$/su', '', $raw);
        $raw = (string) preg_replace("/[^\\p{L}\\s.,'()\\-]/u", ' ', $raw);
        $raw = trim((string) preg_replace('/\s+/u', ' ', $raw), " \t\n\r\0\x0B,.-");

        if ($raw === '') {
            return '';
        }

        $raw = mb_convert_case(mb_strtolower($raw, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');

        return mb_substr($raw, 0, self::MAX_CITY, 'UTF-8');
    }

    /** Two capital letters or ''. */
    public static function country(string $raw): string
    {
        $raw = strtoupper(trim($raw));

        return preg_match('/^[A-Z]{2}$/', $raw) === 1 ? $raw : '';
    }
}
