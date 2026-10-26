<?php

declare(strict_types=1);

namespace Speedat\Domain\SiteDoc;

use Speedat\Core\Money;
use Speedat\Core\Str;
use Speedat\Domain\Pricing\Dates;
use Speedat\Domain\Pricing\Engine;
use Speedat\Domain\Pricing\Num;
use Speedat\Domain\Pricing\Rate;
use Speedat\Domain\Pricing\Settings;

/**
 * Whether a draft may be published, and what is worth a second look first.
 * Port of `validateSite` and `warnSite` in src/lib/site/diff.ts.
 *
 * An error blocks publishing. A warning does not - it is the difference
 * between "this document cannot price a parcel" and "this price looks like a
 * typo", and only the owner can tell the second from a deliberate discount.
 *
 * The wording is copied exactly, including the curly quotes, because the admin
 * screen, the audit log and the tests all read these strings.
 */
final class Validate
{
    /**
     * Blocking problems. An empty list means the draft may be published.
     *
     * @return list<string>
     */
    public static function errors(SiteData $site): array
    {
        $errs = [];
        $card = $site->card;
        $st   = $card->settings;

        if (trim($site->company->name) === '') {
            $errs[] = 'Company name is empty.';
        }

        if (preg_match('/^[0-9]{8,15}$/', $site->company->whatsapp) !== 1) {
            $errs[] = "WhatsApp number must be 8\u{2013}15 digits with the country code, e.g. 923001234567.";
        }

        if (!($st->volumetricDivisor >= 1000 && $st->volumetricDivisor <= 10000)) {
            $errs[] = 'Volumetric divisor must be between 1000 and 10000.';
        }
        if (!($st->firstKg > 0)) {
            $errs[] = 'First weight slab must be greater than 0.';
        }
        if (!($st->stepKg > 0)) {
            $errs[] = 'Additional weight step must be greater than 0.';
        }
        if (!($st->taxPct >= 0 && $st->taxPct <= 100)) {
            $errs[] = 'Tax must be between 0 and 100%.';
        }
        if (!($st->roundTo >= 1)) {
            $errs[] = 'Rounding must be 1 or more.';
        }
        if (!($st->maxKg >= 0)) {
            $errs[] = 'Cargo threshold must be 0 or more.';
        }

        foreach (Engine::lines($st->addons) as $line) {
            $p      = Engine::parts($line, 3);
            $amount = Engine::toNumLoose($p[1]);

            if ($p[0] === '' || !Num::isNum($amount) || $amount < 0) {
                $errs[] = 'Optional charge ' . self::quoted($line) . ' must be written as Label | Amount | on/off with a positive amount.';
            }
        }

        if ($st->cutoffHour !== null && !($st->cutoffHour >= 0 && $st->cutoffHour <= 23)) {
            $errs[] = 'Cutoff hour must be between 0 and 23, or blank.';
        }

        if (trim($st->workingDays) !== '' && preg_match('/mon|tue|wed|thu|fri|sat|sun/i', $st->workingDays) !== 1) {
            $errs[] = 'Working days must be day names such as Mon, Tue, Wed.';
        }
        if (trim($st->workingDays) !== '' && Dates::workingSet($st) === []) {
            $errs[] = 'Working days must be day names such as Mon, Tue, Wed.';
        }

        if (trim($st->currency) === '' || mb_strlen($st->currency, 'UTF-8') > 8) {
            $errs[] = "Currency label must be 1\u{2013}8 characters, such as PKR or Rs.";
        }

        foreach (array_slice(Dates::badHolidayLines($st), 0, 5) as $line) {
            $errs[] = 'Holiday ' . self::quoted($line) . ' must be written as YYYY-MM-DD, optionally followed by | a name.';
        }

        if ($card->services === []) {
            $errs[] = 'At least one service is needed.';
        }

        $grid = $st->isGrid();
        if ($grid && !($st->maxKg >= 1)) {
            $errs[] = 'Grid pricing needs a cargo threshold of at least 1 kg (Settings), which sets the last kilogram box.';
        }

        $seen        = [];
        $activeCount = 0;

        foreach ($card->destinations as $dest) {
            $nm = trim($dest->name);

            if ($nm === '') {
                $errs[] = 'A destination has no name.';
                continue;
            }

            $key = Str::nameKey($nm);
            if (isset($seen[$key])) {
                $errs[] = self::quoted($nm) . ' appears twice.';
            }
            $seen[$key] = true;

            if (!$dest->active) {
                continue;
            }

            $activeCount++;
            $priced = 0;

            foreach ($card->services as $service) {
                $rate = $dest->rate($service->id);
                if ($rate === null) {
                    continue;
                }

                $label = $nm . " \u{00b7} " . $service->name;

                if ($grid) {
                    $boxes = array_filter($rate->boxes(), static fn (?float $v): bool => $v !== null);

                    if ($boxes === []) {
                        continue;
                    }

                    foreach ($boxes as $kg => $price) {
                        if (!(Num::isNum($price) && $price > 0)) {
                            $errs[] = $label . ' ' . $kg . ' kg: price must be greater than 0 or left blank.';
                        }
                    }

                    $priced++;
                    continue;
                }

                if ($rate->first === null && $rate->addl === null) {
                    continue;
                }

                if (!(Num::isNum($rate->first) && $rate->first > 0)) {
                    $errs[] = $label . ': first-slab price must be greater than 0.';
                }
                if (!(Num::isNum($rate->addl) && $rate->addl >= 0)) {
                    $errs[] = $label . ': per-step price is missing.';
                }

                $priced++;
            }

            if ($priced === 0) {
                $errs[] = $nm . ' is shown on the site but has no prices.';
            }
        }

        if ($activeCount === 0) {
            $errs[] = 'At least one destination must be shown on the site.';
        }

        return $errs;
    }

    /**
     * Non-blocking oddities worth a second look before publishing.
     *
     * @return list<string>
     */
    public static function warnings(SiteData $site): array
    {
        $w    = [];
        $card = $site->card;
        $cur  = $card->settings->currency;
        $grid = $card->settings->isGrid();

        $express = $card->services[0] ?? null;
        $normal  = $card->services[1] ?? null;

        foreach ($card->destinations as $dest) {
            if (!$dest->active) {
                continue;
            }

            foreach ($card->services as $service) {
                $rate = $dest->rate($service->id);
                if ($rate === null) {
                    continue;
                }

                $label = $dest->name . " \u{00b7} " . $service->name;

                if (!$grid && Num::isNum($rate->first) && Num::isNum($rate->addl) && $rate->addl > $rate->first) {
                    $w[] = $label . ': the per-step price (' . Money::fmtMoney($rate->addl, $cur)
                        . ') is higher than the first slab (' . Money::fmtMoney($rate->first, $cur) . ').';
                }

                if ($grid && $rate->grid !== null) {
                    $keys = self::sortedBoxKeys($rate);

                    // A heavier parcel should not be cheaper than a lighter one.
                    // Measured against the dearest LIGHTER box so far, not just
                    // the one before, and every drop reported: speedat.co's UK
                    // Express Saver had 13 kg 27,300, 14 kg 2,900 (a dropped
                    // zero) and 15 kg 26,500, and stopping at the first drop
                    // hid that 15 kg was cheaper than 13 kg too.
                    $topKey = null;
                    $top    = null;
                    foreach ($keys as $key) {
                        $here = $rate->boxes()[$key] ?? null;
                        if (!Num::isNum($here)) {
                            continue;
                        }
                        if ($top !== null && $here < $top) {
                            $w[] = $label . ': ' . $key . ' kg (' . Money::fmtMoney($here, $cur) . ') is cheaper than '
                                . $topKey . ' kg (' . Money::fmtMoney($top, $cur) . ').';
                        }
                        if ($top === null || $here > $top) {
                            $top    = $here;
                            $topKey = $key;
                        }
                    }

                    // Priced as the engine reads it: a blank box is not a price
                    // (owner, 2026-09-28), below the last priced kg or above it,
                    // and a customer at that weight is sent to WhatsApp. The two
                    // warnings differ only in how the weights are named. This one
                    // used to say a gap was "charged at the next heavier priced
                    // kg" - the rule that went - so an owner could publish a gap
                    // on purpose, believing it was priced.
                    $priced  = [];
                    $missing = [];

                    foreach (Engine::gridWeights($card->settings) as $kg) {
                        $v = $rate->boxes()[(string) $kg] ?? null;
                        if (Num::isNum($v) && $v > 0) {
                            $priced[] = $kg;
                        } else {
                            $missing[] = $kg;
                        }
                    }

                    if ($missing !== [] && $priced !== []) {
                        $top   = $priced[count($priced) - 1];
                        $gaps  = array_values(array_filter($missing, static fn (int $k): bool => $k < $top));
                        $above = array_values(array_filter($missing, static fn (int $k): bool => $k > $top));

                        if ($gaps !== []) {
                            $shown = implode(', ', array_slice($gaps, 0, 6));
                            $w[]   = $label . ': no price for ' . count($gaps) . ' weight' . (count($gaps) === 1 ? '' : 's')
                                . ' (' . $shown . (count($gaps) > 6 ? "\u{2026}" : '') . " kg) - customers at those weights get no price on the site and are sent to WhatsApp.";
                        }

                        if ($above !== []) {
                            $range = count($above) === 1
                                ? $above[0] . ' kg'
                                : $above[0] . "\u{2013}" . $above[count($above) - 1] . ' kg';
                            $w[] = $label . ': no price above ' . $top . " kg - parcels of " . $range
                                . ' get no price on the site and are sent to WhatsApp.';
                        }
                    }
                }
            }

            if ($express !== null && $normal !== null) {
                $a = self::lightestPrice($dest->rate($express->id), $grid);
                $b = self::lightestPrice($dest->rate($normal->id), $grid);

                if ($a !== null && $b !== null && $a < $b) {
                    $w[] = $dest->name . ': ' . $express->name . ' is cheaper than ' . $normal->name
                        . ' (' . Money::fmtMoney($a, $cur) . ' vs ' . Money::fmtMoney($b, $cur) . ').';
                }
            }

            foreach ($card->services as $service) {
                $rate = $dest->rate($service->id);
                if ($rate !== null && self::lightestPrice($rate, $grid) !== null && $rate->days === '') {
                    $w[] = $dest->name . " \u{00b7} " . $service->name . ': no transit days, so no delivery date can be shown.';
                }
            }
        }

        return $w;
    }

    /* ------------------------------------------------------------ internals */

    /**
     * The cheapest weight a rate prices: the first slab, or the lowest grid box.
     * Null when the rate prices nothing.
     */
    private static function lightestPrice(?Rate $rate, bool $grid): ?float
    {
        if ($rate === null) {
            return null;
        }

        if ($grid) {
            $keys = self::sortedBoxKeys($rate);
            if ($keys === []) {
                return null;
            }

            $v = $rate->boxes()[$keys[0]] ?? null;

            return $v;
        }

        return Num::isNum($rate->first) ? $rate->first : null;
    }

    /**
     * Grid box keys in ascending numeric order - the order
     * `Object.keys(grid).sort((a, b) => Number(a) - Number(b))` produces.
     *
     * @return list<int>
     */
    private static function sortedBoxKeys(Rate $rate): array
    {
        $keys = array_map(static fn (int|string $k): int => (int) $k, array_keys($rate->boxes()));
        sort($keys, SORT_NUMERIC);

        /** @var list<int> $keys */
        return $keys;
    }

    /** The curly quotes the TypeScript messages use around a value the owner typed. */
    private static function quoted(string $s): string
    {
        return "\u{201c}" . $s . "\u{201d}";
    }
}
