<?php

declare(strict_types=1);

namespace Speedat\Domain\Pricing;

use Speedat\Core\Money;

/**
 * The pricing engine. A port of src/lib/pricing/engine.ts, minus the document
 * rate.
 *
 * THE RULE THIS FILE EXISTS TO KEEP: the number the browser shows and the
 * number the server stores must be the same number. The browser runs
 * public/assets/js/pricing.js, the server runs this, and both are checked
 * against php/tests/vectors/pricing.json - 900-odd answers taken from the
 * TypeScript engine itself. A disagreement is a bug here, never a reason to
 * change a vector.
 *
 * THE ONE DELIBERATE DIVERGENCE FROM THE TYPESCRIPT. There is no document rate:
 * no flat price, no weight limit, no shipment type. The owner removed it from
 * the product on 2026-09-27 - a quote is a weight and a destination, and papers
 * are priced like any other parcel. The document cases were therefore removed
 * from the vector file rather than being allowed to fail; docs/engine-parity.md
 * records exactly what was dropped and why. No valid WEIGHT price moved: the
 * document branch was the only reader of the shipment type and of the
 * chargeable-versus-billed comparison, so deleting it cannot reach the slab or
 * grid arithmetic.
 *
 * Three things are load-bearing and must not be "tidied up":
 *
 *  1. Weights are integer grams from the first line to the last. Kilograms are
 *     a display unit only.
 *  2. The order is base -> tax -> round. Rounding earlier changes the answer.
 *  3. A grid box that is blank is NOT A PRICE: that service is not offered at
 *     that weight (owner, 2026-09-28). It used to fall upward to the next
 *     priced box, which charged a number nobody had typed.
 *
 * Pure: no database, no HTTP, no clock. Delivery dates live in Dates, text in
 * Format and QuoteText.
 */
final class Engine
{
    /** Whole kilograms the grid prices when no cargo threshold is set. */
    private const DEFAULT_TOP_KG = 30;

    /** Hard ceiling on the number of kilogram boxes, matching engine.ts. */
    private const MAX_TOP_KG = 200;

    /* ------------------------------------------------------------ weights */

    /**
     * Whole-kilogram weights the grid prices, 1 ... cargo threshold.
     *
     * @return list<int>
     */
    public static function gridWeights(Settings $settings): array
    {
        $top = $settings->maxKg > 0
            ? min((int) floor($settings->maxKg), self::MAX_TOP_KG)
            : self::DEFAULT_TOP_KG;

        $top = max($top, 1);

        return range(1, $top);
    }

    /**
     * Slab settings the weight maths should use. Grid mode bills whole
     * kilograms from 1 kg up, whatever the slab step says - anything that
     * explains a rounding to the customer must read these, not stepKg alone.
     *
     * @return array{firstKg: float, stepKg: float}
     */
    public static function effectiveSteps(Settings $settings): array
    {
        return $settings->isGrid()
            ? ['firstKg' => 1.0, 'stepKg' => 1.0]
            : ['firstKg' => $settings->firstKg, 'stepKg' => $settings->stepKg];
    }

    /**
     * Grams for a list of pieces.
     *
     * @param list<Piece> $rows
     */
    public static function computeWeights(Settings $settings, array $rows): Weights
    {
        $eff    = self::effectiveSteps($settings);
        $stepG  = Money::kgToG($eff['stepKg']);
        $firstG = Money::kgToG($eff['firstKg']);

        if ($stepG <= 0) {
            // engine.ts would divide by zero and hand back NaN grams, which
            // travels silently into a price. validateSite refuses to publish a
            // document with a step of zero, so reaching here means the document
            // was never validated - say so instead of quoting nonsense.
            throw new \InvalidArgumentException('The weight step must be greater than 0 kg.');
        }

        $pieces  = 0;
        $actualG = 0;
        $volG    = 0;
        $chargeG = 0;
        $lines   = [];

        foreach ($rows as $row) {
            $kg = $row->kg;

            // Num::isNum() is the TypeScript's own isNum(): a real, FINITE
            // number. The `kg > 0` the TypeScript writes is true for INF too,
            // and `(int) INF` is 0 in PHP - so an infinite weight used to pass
            // this line and then contribute ZERO grams, leaving the shipment at
            // the first slab, which is the cheapest price in the card (audit
            // round 1, finding 6). A weight that is not a number is not a
            // weight, and the row is skipped exactly as an empty one is.
            if (!Num::isNum($kg) || !($kg > 0)) {
                continue;
            }

            $qty = self::quantity($row->qty);

            // Same reasoning for the dimensions: an infinite side would make the
            // product infinite and `(int)` of that 0, i.e. "no volumetric
            // weight" arrived at by accident instead of on purpose.
            $dims  = [$row->L, $row->W, $row->H];
            $sized = true;
            foreach ($dims as $dim) {
                if (!Num::isNum($dim) || !($dim > 0)) {
                    $sized = false;
                    break;
                }
            }

            $v = $sized
                ? (int) Money::jsRound((($dims[0] * $dims[1] * $dims[2]) / $settings->effectiveDivisor()) * 1000)
                : 0;

            $a = Money::kgToG($kg);
            $c = max($a, $v);

            $pieces  += $qty;
            $actualG += $a * $qty;
            $volG    += $v * $qty;
            $chargeG += $c * $qty;

            $lines[] = new WeightLine(
                qty: $qty,
                kg: $kg,
                L: $dims[0],
                W: $dims[1],
                H: $dims[2],
                actualG: $a,
                volG: $v,
                pieceG: $c,
                volumetricWins: $v > $a,
            );
        }

        $billableG = max($firstG, (int) ceil($chargeG / $stepG) * $stepG);

        return new Weights(
            pieces: $pieces,
            actualG: $actualG,
            volG: $volG,
            chargeG: $chargeG,
            billableG: $billableG,
            volumetricWins: $chargeG > $actualG,
            lines: $lines,
        );
    }

    /* ------------------------------------------------------------- pricing */

    /**
     * Grid price for a billable weight: the price entered at THAT kilogram, or
     * nothing.
     *
     * OWNER DECISION, 2026-09-28 - "if I don't want to add a price for some
     * weight it should say rate not available, contact on WhatsApp". A blank
     * box used to fall upward to the next priced kilogram, so leaving 3 kg
     * empty quietly charged the 5 kg price for a 3 kg parcel: a number the
     * owner never typed, shown as if they had, and the only way to stop
     * offering a weight was to price it anyway. A blank box is now simply not
     * a price, the service is not offered at that weight, and the site says so
     * and sends the customer to WhatsApp.
     *
     * Zero is not a price either, and never was: the site does not print
     * "PKR 0". A box holding 0 reads the same as an empty one.
     *
     * Above the cargo threshold there is no box to read, so the answer is null
     * for that reason instead - the same null, a different sentence upstream.
     *
     * docs/engine-parity.md records this as the sixth deliberate divergence
     * from the TypeScript, and the golden vectors were re-derived for it.
     *
     * @param  array<string,float|null>|null $grid
     * @return array{kg: int, price: float}|null
     */
    public static function gridPrice(?array $grid, float $kg, float $maxKg): ?array
    {
        if ($grid === null) {
            return null;
        }

        $top = $maxKg > 0 ? (int) floor($maxKg) : self::MAX_TOP_KG;

        // Grid mode bills whole kilograms, so the box that answers for 1.2 kg
        // is the 2 kg box. That is the rounding, not a fallback: it happens
        // before a box is read, and it is unchanged.
        $k = max(1, (int) ceil($kg));

        if ($k > $top) {
            return null;
        }

        $price = $grid[(string) $k] ?? null;

        return Num::isNum($price) && $price > 0 ? ['kg' => $k, 'price' => $price] : null;
    }

    /**
     * Price one service for one destination.
     *
     * Takes the BILLED grams and nothing else. The TypeScript also takes a
     * shipment type and the chargeable grams, both of which existed only to
     * decide whether the flat document price applied; with the document rate
     * gone (owner, 2026-09-27) they are not inputs to a price any more, and
     * leaving them in the signature would invite a caller to think they were.
     */
    public static function priceService(
        Settings $settings,
        Destination $dest,
        string $serviceId,
        int $billableG,
    ): ?ServicePrice {
        $rate = $dest->rate($serviceId);
        if ($rate === null) {
            return null;
        }

        $eff    = self::effectiveSteps($settings);
        $firstG = Money::kgToG($eff['firstKg']);
        $stepG  = Money::kgToG($eff['stepKg']);

        $steps  = 0;
        $gridKg = null;

        if ($settings->isGrid()) {
            $hit = self::gridPrice($rate->grid, $billableG / 1000, $settings->maxKg);
            if ($hit === null) {
                return null;
            }
            $base   = $hit['price'];
            $gridKg = $hit['kg'];
        } else {
            if (!Num::isNum($rate->first) || !Num::isNum($rate->addl)) {
                return null;
            }
            if ($stepG <= 0) {
                throw new \InvalidArgumentException('The weight step must be greater than 0 kg.');
            }
            $steps = $billableG <= $firstG ? 0 : (int) ceil(($billableG - $firstG) / $stepG);
            $base  = $rate->first + $steps * $rate->addl;
        }

        $taxPct = is_finite($settings->taxPct) ? $settings->taxPct : 0.0;
        $tax    = Money::percent($base, $taxPct);

        return new ServicePrice(
            first: $rate->first,
            addl: $rate->addl,
            steps: $steps,
            gridKg: $gridKg,
            base: $base,
            tax: $tax,
            total: Money::roundTo($base + $tax, $settings->roundTo),
            days: $rate->days,
        );
    }

    /**
     * Price a whole shipment against a rate card.
     *
     * @param list<Piece> $rows
     */
    public static function priceAll(RateCard $card, string $destId, array $rows): PriceResult
    {
        $dest = $card->activeDestination($destId);
        if ($dest === null) {
            return PriceResult::noDestination();
        }

        // The same finiteness test computeWeights() uses, and it has to be the
        // same one: a row whose kg is INF would otherwise pass this filter,
        // count as "the customer entered a weight", and then be skipped by
        // computeWeights - leaving a priced quote of firstKg grams, the cheapest
        // box in the card, for a shipment nobody can weigh. Refusing here gives
        // the customer the "enter a weight" answer instead (finding 6).
        $weighed = array_values(array_filter($rows, static fn (Piece $p): bool => Num::isNum($p->kg) && $p->kg > 0));
        if ($weighed === []) {
            return PriceResult::noWeight();
        }

        $weights = self::computeWeights($card->settings, $weighed);

        $maxG = $card->settings->maxKg > 0 ? Money::kgToG($card->settings->maxKg) : 0;
        if ($maxG > 0 && $weights->billableG > $maxG) {
            return PriceResult::overMax($dest, $weights);
        }

        $prices = [];
        foreach ($card->services as $service) {
            $prices[$service->id] = self::priceService(
                $card->settings,
                $dest,
                $service->id,
                $weights->billableG,
            );
        }

        return PriceResult::priced($dest, $weights, $prices);
    }

    /* --------------------------------------- settings and spreadsheet text */

    /**
     * Non-empty trimmed lines of a multi-line setting.
     *
     * Note the comparison: a line whose whole content is "0" is kept, because
     * JavaScript's filter(Boolean) keeps the string "0". PHP's array_filter()
     * would drop it.
     *
     * @return list<string>
     */
    public static function lines(?string $text): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', (string) $text) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $out[] = $trimmed;
            }
        }

        return $out;
    }

    /**
     * Split an `a | b | c` line into at least `n` trimmed parts.
     *
     * @return list<string>
     */
    public static function parts(string $line, int $n): array
    {
        $out = array_map('trim', explode('|', $line));
        while (count($out) < $n) {
            $out[] = '';
        }

        return array_values($out);
    }

    /**
     * Lenient number parse for spreadsheet cells: "Rs 4,500/-" becomes 4500,
     * "-" and "n/a" become null, junk becomes NAN.
     *
     * The three answers are different on purpose. null is "the cell was left
     * empty", NAN is "somebody typed something that is not a price" - the
     * import refuses the second and skips the first.
     */
    public static function toNumLoose(mixed $v): ?float
    {
        if ($v === null) {
            return null;
        }

        if (is_int($v) || is_float($v)) {
            return is_finite((float) $v) ? (float) $v : NAN;
        }

        // String(true) is "true" in JavaScript, not "1" - a cell holding a
        // boolean has no digits in it and is therefore junk, not the price 1.
        if (is_bool($v)) {
            return NAN;
        }

        $s = trim(is_scalar($v) ? (string) $v : '');

        if ($s === '' || preg_match('/^[-\x{2013}\x{2014}]$/u', $s) === 1 || preg_match('/^n\/?a$/i', $s) === 1) {
            return null;
        }

        $s = str_replace(',', '', $s);

        return preg_match('/-?\d+(\.\d+)?/', $s, $m) === 1 ? (float) $m[0] : NAN;
    }

    /**
     * Optional charges parsed out of the settings text.
     *
     * The id is the index of the line among the NON-EMPTY lines, so a broken
     * line in the middle leaves a gap ("a0", "a2") - the ids are stable as long
     * as the text above them does not change.
     *
     * @return list<Addon>
     */
    public static function addonsList(Settings $settings): array
    {
        $out = [];

        foreach (self::lines($settings->addons) as $i => $line) {
            $p      = self::parts($line, 3);
            $amount = self::toNumLoose($p[1]);

            if ($p[0] === '' || !Num::isNum($amount) || $amount < 0) {
                continue;
            }

            $out[] = new Addon(
                id: 'a' . $i,
                label: $p[0],
                amount: $amount,
                on: preg_match('/^(on|yes|true|1|checked|default)$/i', $p[2]) === 1,
            );
        }

        return $out;
    }

    /**
     * Derive whole-kilogram grid prices from slab prices, so switching a
     * document to grid mode keeps the same numbers until the boxes are edited.
     * Boxes that already hold a number are left alone.
     *
     * @return array<string,float|null>|null null when there is nothing to store
     */
    public static function slabToGrid(Settings $settings, Rate $rate): ?array
    {
        $out = $rate->boxes();

        if (Num::isNum($rate->first) && Num::isNum($rate->addl)) {
            $firstG = Money::kgToG($settings->firstKg);
            $stepG  = Money::kgToG($settings->stepKg);

            if ($stepG <= 0) {
                throw new \InvalidArgumentException('The weight step must be greater than 0 kg.');
            }

            foreach (self::gridWeights($settings) as $kg) {
                if (Num::isNum($out[(string) $kg] ?? null)) {
                    continue;
                }

                $g     = $kg * 1000;
                $steps = $g <= $firstG ? 0 : (int) ceil(($g - $firstG) / $stepG);

                $out[(string) $kg] = Money::roundTo($rate->first + $steps * $rate->addl, $settings->roundTo);
            }
        }

        /** @var array<string,float|null> $ordered */
        $ordered = Num::jsKeyOrder($out);

        return $ordered === [] ? null : $ordered;
    }

    /* ------------------------------------------------------------ internals */

    /**
     * `Math.max(1, Math.floor(Number(r.qty) || 1))`: an absent, zero or
     * unreadable quantity is one piece, a fractional one is rounded down, a
     * negative one is one piece.
     */
    private static function quantity(float $raw): int
    {
        $n = (is_nan($raw) || $raw == 0.0) ? 1.0 : $raw;

        return max(1, (int) floor($n));
    }
}
