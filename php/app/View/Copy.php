<?php

declare(strict_types=1);

namespace Speedat\View;

use Speedat\Domain\Pricing\Dates;
use Speedat\Domain\Pricing\Destination;
use Speedat\Domain\Pricing\Engine;
use Speedat\Domain\Pricing\Format;
use Speedat\Domain\Pricing\Piece;

/**
 * Sentences the site generates from the rate document itself: the home
 * headline, the line under it, the rates board's caption, and the from-price
 * on a country tile. Port of src/lib/site/copy.ts.
 *
 * Every number here comes from `settings`, `company` or a rate - never from a
 * content string - so the page can never say something the rate document does
 * not. A content field, when set, overrides the generated sentence; blank
 * means automatic.
 *
 * The prices come from `Engine::priceAll` with exactly the call the
 * calculator's third tap makes (one piece, 1 kg), so a figure on a tile, in the
 * headline and on the board is the figure the calculator prints.
 *
 * `docPrice()` and `hasDocRate()` used to live here and are gone with the
 * document rate itself (owner, 2026-09-27): the caption no longer names a
 * document limit, because there is no longer a price that limit applies to.
 */
final class Copy
{
    /** A number and its unit stay on one line: "PKR 500" and "0.5 kg" never break across two. */
    private const NBSP = "\u{00A0}";

    /**
     * "Canada" | "Canada and Germany" | "Australia, Canada, France and Germany"
     * | "A, B, C and 4 more countries".
     *
     * @param list<string> $names
     */
    public static function countryList(array $names): string
    {
        $names = array_values($names);
        $n     = count($names);

        if ($n === 0) {
            return '';
        }
        if ($n === 1) {
            return $names[0];
        }
        if ($n <= 4) {
            return implode(', ', array_slice($names, 0, -1)) . ' and ' . $names[$n - 1];
        }

        $rest = $n - 3;

        return implode(', ', array_slice($names, 0, 3)) . ' and ' . $rest . ' more ' . ($rest === 1 ? 'country' : 'countries');
    }

    /** "3-5" from "3-5", "3 to 5 days" or "3 - 5"; "4" from "4"; "" when the rate carries no number. */
    public static function daysText(?string $raw): string
    {
        $r = Dates::parseDaysRange($raw);
        if ($r === null) {
            return '';
        }

        return $r[0] === $r[1] ? (string) $r[0] : $r[0] . "\u{2013}" . $r[1];
    }

    /** The add-ons ticked by default, summed: what the calculator adds before anyone touches it. */
    public static function addonsOnTotal(Doc $doc): float
    {
        $total = 0.0;
        foreach (Engine::addonsList($doc->settings()) as $addon) {
            if ($addon->on) {
                $total += $addon->amount;
            }
        }

        return $total;
    }

    /**
     * The 1 kg parcel price on a country tile and in the headline: the
     * calculator's quick-rate call for 1 kg, the cheapest priced service, plus
     * the add-ons that are on by default. Null when the destination is not
     * priced (inactive, no service, over the limit).
     */
    public static function fromPrice(Doc $doc, string $destId): ?FromPrice
    {
        $result = Engine::priceAll($doc->card(), $destId, [new Piece(kg: 1.0, qty: 1.0)]);
        if (!$result->ok) {
            return null;
        }

        $best = null;
        foreach ($doc->services() as $service) {
            $price = $result->price($service->id);
            if ($price === null) {
                continue;
            }
            if ($best === null || $price->total < $best->total) {
                $best = new FromPrice($price->total, self::daysText($price->days), $service->id);
            }
        }

        if ($best === null) {
            return null;
        }

        return new FromPrice($best->total + self::addonsOnTotal($doc), $best->days, $best->serviceId);
    }

    /**
     * The shortest lower bound and the longest upper bound of transit days
     * over every active destination and service, or null when no rate carries
     * a range.
     *
     * @return array{0:int,1:int}|null
     */
    public static function daySpan(Doc $doc): ?array
    {
        $lo = null;
        $hi = null;

        foreach ($doc->active() as $dest) {
            foreach ($doc->services() as $service) {
                $range = Dates::parseDaysRange($dest->rate($service->id)?->days);
                if ($range === null) {
                    continue;
                }
                $lo = $lo === null ? $range[0] : min($lo, $range[0]);
                $hi = $hi === null ? $range[1] : max($hi, $range[1]);
            }
        }

        return $lo === null || $hi === null ? null : [$lo, $hi];
    }

    /** "Lahore to Australia, Canada, France and Germany." - the first pickup city (or the origin) and the live destinations. */
    public static function heroTitleAuto(Doc $doc): string
    {
        $cities = Text::originCities($doc->company()->originCities);
        $from   = $cities[0] ?? $doc->company()->origin;
        $list   = self::countryList(array_map(static fn (Destination $d): string => $d->name, $doc->active()));

        if ($list === '') {
            return $doc->company()->name;
        }

        return $from . ' to ' . $list . '.';
    }

    /**
     * "1 kg from PKR 5,000 · 3 to 10 days" - the lowest 1 kg price on the site
     * and the day span, in the idiom of the country tiles under it; on hold or
     * without a price, "Pickup in Lahore · 3 to 10 days". Without days the
     * first part stands alone.
     */
    public static function heroSubAuto(Doc $doc): string
    {
        $span = self::daySpan($doc);
        $when = '';
        if ($span !== null) {
            $when = $span[0] === $span[1]
                ? ' · ' . $span[0] . ' ' . ($span[0] === 1 ? 'day' : 'days')
                : ' · ' . $span[0] . ' to ' . $span[1] . ' days';
        }

        $min = null;
        if ($doc->pricesVisible()) {
            foreach ($doc->active() as $dest) {
                $from = self::fromPrice($doc, $dest->id);
                if ($from !== null && ($min === null || $from->total < $min)) {
                    $min = $from->total;
                }
            }
        }

        if ($min !== null) {
            return '1 kg from ' . Format::fmtMoney($min, $doc->currency()) . $when;
        }

        $cities = Text::originCities($doc->company()->originCities);
        $first  = $cities !== []
            ? 'Pickup in ' . implode(' and ', $cities)
            : 'From ' . $doc->company()->origin;

        return $first . $when;
    }

    /**
     * "1 kg parcel · pickup PKR 500 included" - the second line of the board's
     * caption. On hold the board prints only the days, so the caption says that
     * instead: the parcel and the pickup charge both describe prices that are
     * not there.
     *
     * The TypeScript adds "· documents up to 0.5 kg" here. It is gone with the
     * document rate (owner, 2026-09-27): the board has no Documents column for
     * the clause to explain.
     */
    public static function boardNote(Doc $doc): string
    {
        if ($doc->holdOn()) {
            return 'Working days per service · prices are being updated';
        }

        $settings = $doc->settings();
        $out      = '1' . self::NBSP . 'kg parcel';

        $addons = self::addonsOnTotal($doc);
        if ($addons > 0) {
            $out .= ' · pickup ' . self::hard(Format::fmtMoney($addons, $settings->currency)) . ' included';
        }

        return $out;
    }

    private static function hard(string $s): string
    {
        return str_replace(' ', self::NBSP, $s);
    }
}
