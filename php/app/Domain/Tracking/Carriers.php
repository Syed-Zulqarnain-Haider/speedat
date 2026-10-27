<?php

declare(strict_types=1);

namespace Speedat\Domain\Tracking;

use Speedat\Core\Str;

/**
 * The carriers the office books with, and what the tracking service calls them.
 *
 * THE CARRIER COLUMN STAYS FREE TEXT. The admin has always typed "DHL" or
 * "Emirates SkyCargo" into `shipments.carrier`, and rows written that way are
 * not migrated. match() reads whatever was typed and finds the carrier in it,
 * so "dhl", "DHL Express" and "Dhl express (UK)" all track as DHL, and a value
 * it does not recognise simply is not tracked automatically - the office
 * updates it by hand, as before.
 *
 * THE CODES ARE 17TRACK'S, read off its published carrier list
 * (res.17track.net/asset/carrier/info/apicarrier.all.json, 2026-10-27).
 * 0 means "let 17TRACK detect the carrier from the number": DPD is a dozen
 * national companies there and choosing one would be a guess.
 *
 * TEAMGLOBAL IS MANUAL. No tracking service carries TeamGlobal Express
 * (Pakistan); the "Team Global Express" 17TRACK lists is an Australian firm.
 * It is in this list so the admin can name it and the desk can say plainly
 * that it is tracked by hand.
 */
final class Carriers
{
    /**
     * key => [display name, 17TRACK code or null for "manual only", words that identify it].
     *
     * @var array<string,array{0:string,1:?int,2:list<string>}>
     */
    private const LIST = [
        'dhl'        => ['DHL Express', 100001, ['dhl']],
        'fedex'      => ['FedEx', 100003, ['fedex', 'fed ex']],
        'ups'        => ['UPS', 100002, ['ups']],
        'dpd'        => ['DPD', 0, ['dpd']],
        'aramex'     => ['Aramex', 100006, ['aramex']],
        'skynet'     => ['SkyNet', 100730, ['skynet', 'sky net']],
        'tcs'        => ['TCS', 100260, ['tcs']],
        'leopards'   => ['Leopards', 100366, ['leopards', 'leopard', 'lcs']],
        'mp'         => ['M&P', 100374, ['m&p', 'm & p', 'm and p', 'muller']],
        'trax'       => ['Trax', 100505, ['trax']],
        'teamglobal' => ['TeamGlobal', null, ['teamglobal', 'team global', 'tge']],
    ];

    /** The carrier a typed name refers to, or null when it is not one we know. */
    public static function match(string $typed): ?string
    {
        // Lower case, every run of punctuation but "&" turned into one space,
        // padded so " ups " cannot be found inside "groups".
        $text = ' ' . trim((string) preg_replace('/[^a-z0-9&]+/', ' ', strtolower(Str::squish($typed)))) . ' ';
        $text = str_replace('&', ' & ', $text);
        $text = (string) preg_replace('/\s+/', ' ', $text);

        if (trim($text) === '') {
            return null;
        }

        foreach (self::LIST as $key => [, , $words]) {
            foreach ($words as $word) {
                $needle = ' ' . (string) preg_replace('/\s+/', ' ', str_replace('&', ' & ', $word)) . ' ';
                if (str_contains($text, $needle)) {
                    return $key;
                }
            }
        }

        return null;
    }

    /** The name the customer reads, or the typed text when it is not a carrier we know. */
    public static function displayName(string $typed): string
    {
        $key = self::match($typed);

        return $key !== null ? self::LIST[$key][0] : Str::squish($typed);
    }

    /**
     * The 17TRACK code for a typed carrier: an int (0 = auto-detect) when it
     * can be tracked automatically, null when it cannot.
     */
    public static function trackCode(string $typed): ?int
    {
        $key = self::match($typed);

        return $key !== null ? self::LIST[$key][1] : null;
    }

    /** @return list<string> every display name, for the admin's suggestion list */
    public static function names(): array
    {
        return array_values(array_map(static fn (array $c): string => $c[0], self::LIST));
    }

    /** @return list<string> the display names that are tracked automatically */
    public static function trackedNames(): array
    {
        $out = [];
        foreach (self::LIST as [$name, $code]) {
            if ($code !== null) {
                $out[] = $name;
            }
        }

        return $out;
    }
}
