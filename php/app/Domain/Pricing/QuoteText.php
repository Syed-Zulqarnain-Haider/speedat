<?php

declare(strict_types=1);

namespace Speedat\Domain\Pricing;

use Speedat\Core\Money;

/**
 * Every sentence the customer or the owner reads about a price. Port of
 * src/lib/pricing/quote.ts, plus the hold form's message from
 * src/components/site/HoldPanel.tsx.
 *
 * These are pure string builders on purpose: the same text has to come out of
 * the browser (so the WhatsApp link is right before anything is submitted),
 * out of the server (so the stored lead says the same thing) and out of the
 * tests. Nothing here reads a clock, a session or a database.
 */
final class QuoteText
{
    public const UNITS_METRIC   = 'metric';
    public const UNITS_IMPERIAL = 'imperial';

    /** Kilograms per pound, and centimetres per inch - the two conversions the site offers. */
    public const LB = 0.45359237;
    public const IN = 2.54;

    /**
     * The longest add-on label a booking carries, in characters. QuoteInput
     * cuts every posted label to this, so resolveAddons() cuts the document's
     * labels to it too before comparing - the Schema allows an add-on line of
     * up to 2,000 characters, and an 81-character label used to be dropped.
     */
    public const ADDON_LABEL_MAX = 80;

    /**
     * Whitespace a label key collapses: JavaScript's \s (which has the BOM and
     * not NEL) together with PHP's /u \s (which has NEL and U+180E and not the
     * BOM), so a label squished by Str::squish() on the way in and the same
     * label straight from the document come out as the same key.
     */
    private const LABEL_WS = '[\t\n\x{0B}\f\r \x{0085}\x{00A0}\x{1680}\x{180E}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';

    /**
     * "2 x 1 kg (30x20x10 cm); 1 x 0.5 kg" in the customer's units.
     *
     * Dimensions are printed only for a piece that actually has a volumetric
     * weight, which is the same test the engine used to charge for it.
     */
    public static function piecesText(Weights $w, string $units): string
    {
        $imperial = $units === self::UNITS_IMPERIAL;
        $out      = [];

        foreach ($w->lines as $line) {
            $kg = $imperial
                ? self::f2($line->kg / self::LB) . ' lb'
                : self::f2($line->kg) . ' kg';

            $dims = '';
            if ($line->volG !== 0) {
                $dims = $imperial
                    ? ' (' . self::f2($line->L / self::IN) . "\u{00d7}" . self::f2($line->W / self::IN) . "\u{00d7}" . self::f2($line->H / self::IN) . ' in)'
                    : ' (' . self::f2($line->L) . "\u{00d7}" . self::f2($line->W) . "\u{00d7}" . self::f2($line->H) . ' cm)';
            }

            $out[] = $line->qty . " \u{00d7} " . $kg . $dims;
        }

        return implode('; ', $out);
    }

    /**
     * One sentence explaining what weight the price is charged on.
     *
     * The rounding it names is the one the engine did: grid mode bills whole
     * kilograms whatever the slab step says, and a weight under the first slab
     * is billed as that slab - not "rounded up to the next step".
     */
    public static function weightSentence(Weights $w, Settings $settings): string
    {
        $txt = 'Charged on ' . Money::gToKg($w->billableG) . ' kg'
            . ($w->pieces > 1 ? ' for ' . $w->pieces . ' pieces' : '');

        // Mixed pieces: some charged on their volume, some on their scale weight.
        // The sum is then neither the volumetric weight nor the actual one, and
        // calling it "volumetric weight" was false - 10 kg in a small box plus
        // 1 kg in a big one is 11 kg actual, 10.2 kg volumetric and 20 kg
        // charged. Say what the engine actually did, per piece.
        if ($w->volumetricWins && self::mixedLines($w)) {
            return $txt . ' - each piece is charged at the greater of its actual and volumetric weight ('
                . Money::gToKg($w->actualG) . ' kg actual in total).';
        }

        if ($w->volumetricWins) {
            return $txt . " - volumetric weight (" . Money::gToKg($w->chargeG) . ' kg) is higher than actual ('
                . Money::gToKg($w->actualG) . ' kg).';
        }

        if ($w->billableG !== $w->chargeG) {
            // Anchored the way Engine::computeWeights() now bills: a weight is
            // "minimum" when it was lifted to the first slab and plain step
            // rounding would not have landed there. With a 1 kg first slab and
            // 0.5 kg steps, 0.7 kg -> 1 kg is still "rounded up to the next
            // 0.5 kg"; with 0.5 kg / 1 kg, 0.3 kg -> 0.5 kg is the minimum, not
            // a round-up to a 1 kg step that was never charged. Whenever the
            // first slab is a multiple of the step this is the old test exactly.
            $eff       = Engine::effectiveSteps($settings);
            $stepG     = Money::kgToG($eff['stepKg']);
            $firstG    = Money::kgToG($eff['firstKg']);
            $byMinimum = $stepG > 0
                && $w->chargeG < $firstG
                && (int) ceil($w->chargeG / $stepG) * $stepG !== $firstG;

            return $txt . ' (' . Money::gToKg($w->actualG) . ' kg, '
                . ($byMinimum
                    ? 'minimum ' . Money::numberToString($eff['firstKg']) . ' kg'
                    : 'rounded up to the next ' . Money::numberToString($eff['stepKg']) . ' kg')
                . ').';
        }

        return $txt . '.';
    }

    /**
     * "Pickup and service charges" becomes "pickup and service charges", so an
     * add-on's label reads on inside "Includes PKR 500 ...". An acronym such as
     * "COD fee" keeps its capitals.
     */
    public static function midSentence(string $s): string
    {
        return preg_replace_callback(
            '/^[A-Z](?=[a-z])/',
            static fn (array $m): string => strtolower($m[0]),
            $s,
        ) ?? $s;
    }

    /**
     * The one number the customer booked: shipping plus every add-on that was on.
     *
     * @param list<Addon> $addons
     */
    public static function bookedTotal(float $shipping, array $addons): float
    {
        $total = $shipping;
        foreach ($addons as $addon) {
            $total += $addon->amount;
        }

        return $total;
    }

    /**
     * The add-ons a booking named, resolved against the settings the price was
     * computed from, so their amounts are the document's and never the
     * browser's. Labels the document no longer has are dropped; the order
     * follows the settings, not the booking.
     *
     * @param  list<string>|null $labels
     * @return list<Addon>
     */
    public static function resolveAddons(Settings $settings, ?array $labels): array
    {
        if ($labels === null || $labels === []) {
            return [];
        }

        // Compared as KEYS, not as the raw strings. QuoteInput squishes and
        // cuts every posted label, so "Pickup  and packing" (two spaces, as
        // typed in the settings) arrived as "Pickup and packing" and never
        // matched its own line: the stored total and the inbox lead were PKR
        // 500 short of the customer's WhatsApp total. Keying both sides the same
        // way fixes that, and an over-long label too. The amount and the label
        // returned are still the document's.
        $want = [];
        foreach ($labels as $label) {
            $want[self::labelKey((string) $label)] = true;
        }

        return array_values(array_filter(
            Engine::addonsList($settings),
            static fn (Addon $a): bool => isset($want[self::labelKey($a->label)]),
        ));
    }

    /**
     * What two add-on labels are compared by: control characters gone, every
     * whitespace run one space, trimmed, cut to ADDON_LABEL_MAX characters and
     * trimmed again. The second trim is not tidiness: QuoteInput cuts a long
     * label wherever the 80th character falls, and when that is a space the
     * posted label ends one character short of the document's cut.
     * pricing.js `labelKey()` is the same function.
     */
    public static function labelKey(string $label): string
    {
        $s = preg_replace('/[\x00-\x08\x0E-\x1F\x7F]+/', '', $label) ?? '';
        $s = preg_replace('/' . self::LABEL_WS . '+/u', ' ', $s) ?? $s;
        $s = trim($s, ' ');

        return mb_strlen($s, 'UTF-8') <= self::ADDON_LABEL_MAX
            ? $s
            : rtrim(mb_substr($s, 0, self::ADDON_LABEL_MAX, 'UTF-8'), ' ');
    }

    /** True when some lines were charged on volume and some were not. */
    private static function mixedLines(Weights $w): bool
    {
        $byVolume = false;
        $byScale  = false;

        foreach ($w->lines as $line) {
            if ($line->volumetricWins) {
                $byVolume = true;
            } else {
                $byScale = true;
            }
        }

        return $byVolume && $byScale;
    }

    /**
     * The WhatsApp message that books a shipment.
     *
     * @param list<Addon> $addons the add-ons that were ticked
     */
    public static function quoteText(
        Quote $q,
        string $companyName,
        string $currency,
        array $addons,
        ?string $contents = null,
    ): string {
        $out = ['Hi ' . $companyName . ', I want to book a shipment.', 'Quote: ' . $q->id];

        if ($q->from !== '') {
            $out[] = 'From: ' . $q->from;
        }

        $out[] = 'To: ' . $q->dest;
        $out[] = 'Service: ' . $q->service . ($q->days !== '' ? ' (' . $q->days . ' working days)' : '');

        if ($q->pickup !== '') {
            $out[] = 'Pickup: ' . $q->pickup;
        }
        if ($q->eta !== '') {
            $out[] = 'Estimated delivery: ' . $q->eta;
        }

        // One label, because there is one kind of shipment: the document rate
        // left the product on 2026-09-27 and papers are quoted as a parcel.
        $out[] = 'Packages: ' . $q->piecesText;
        $out[] = 'Charged on: ' . Money::gToKg($q->billableG) . ' kg';

        // "Courier", the word the on-site breakdown uses ("Courier PKR 14,400 +
        // Delivery PKR 1,000", owner, 2026-10-05) - "Shipping" read as a third
        // thing. An add-on left on at 0 is not a charge and prints no line; if
        // nothing with an amount is left, the message is a single "Price".
        $paid = array_values(array_filter($addons, static fn (Addon $a): bool => $a->amount > 0));

        if ($paid !== []) {
            $out[] = 'Courier: ' . Money::fmtMoney($q->total, $currency);
            foreach ($paid as $addon) {
                $out[] = $addon->label . ': ' . Money::fmtMoney($addon->amount, $currency);
            }
            $out[] = 'Total: ' . Money::fmtMoney(self::bookedTotal($q->total, $paid), $currency);
        } else {
            $out[] = 'Price: ' . Money::fmtMoney($q->total, $currency);
        }

        $c = trim((string) $contents);
        if ($c !== '') {
            $out[] = 'Contents: ' . $c;
        }

        return implode("\n", $out);
    }

    /**
     * The inbox line for a booked quote. Its price is the Total the customer's
     * WhatsApp message carries, with what is inside it named the way the site
     * names it, so the owner and the customer never read two different numbers.
     *
     * @param list<Addon> $addons
     */
    public static function leadSummary(
        string $service,
        string $dest,
        int $billableG,
        float $shipping,
        array $addons,
        string $currency,
        string $piecesText = '',
    ): string {
        $inside = '';
        if ($addons !== []) {
            $bits = [];
            foreach ($addons as $addon) {
                $bits[] = Money::fmtMoney($addon->amount, $currency) . ' ' . self::midSentence($addon->label);
            }
            $inside = ' (includes ' . implode(', ', $bits) . ')';
        }

        $s = $service . ' to ' . $dest . " \u{00b7} " . Money::gToKg($billableG) . " kg \u{00b7} "
            . Money::fmtMoney(self::bookedTotal($shipping, $addons), $currency) . $inside;

        if ($piecesText !== '') {
            $s .= " \u{00b7} " . $piecesText;
        }

        return $s;
    }

    /**
     * A wa.me link carrying a prepared message.
     *
     * `rawurlencode()` follows RFC 3986 and escapes ! * ' ( ), which
     * `encodeURIComponent()` leaves alone. The five are put back so the URL is
     * byte-identical to the one the browser builds - otherwise the same message
     * has two different links depending on who wrote it.
     */
    public static function waLink(string $whatsapp, string $text): string
    {
        return 'https://wa.me/' . $whatsapp . '?text=' . self::encodeUriComponent($text);
    }

    /** Port of JavaScript's encodeURIComponent(). */
    public static function encodeUriComponent(string $text): string
    {
        return str_replace(
            ['%21', '%2A', '%27', '%28', '%29'],
            ['!', '*', "'", '(', ')'],
            rawurlencode($text),
        );
    }

    /** The message for a shipment above the cargo threshold. */
    public static function cargoText(string $companyName, string $from, string $dest, Weights $w, string $units): string
    {
        return 'Hi ' . $companyName . ', I need a cargo rate.'
            . ($from !== '' ? "\nFrom: " . $from : '')
            . "\nTo: " . $dest
            . "\nPieces: " . self::piecesText($w, $units)
            . "\nChargeable: about " . Money::gToKg($w->billableG) . ' kg';
    }

    /**
     * The message the hold form sends while prices are off the site. Port of
     * `holdText` in src/components/site/HoldPanel.tsx - the one message builder
     * that lives in a component rather than in lib/.
     */
    public static function holdText(string $companyName, string $dest, string $kg, string $contents): string
    {
        $out = ['Hi ' . $companyName . ', please price a parcel for me.'];

        if ($dest !== '') {
            $out[] = 'To: ' . $dest;
        }
        if ($kg !== '') {
            $out[] = 'Weight: about ' . $kg . ' kg';
        }

        $c = trim($contents);
        if ($c !== '') {
            $out[] = 'Contents: ' . $c;
        }

        return implode("\n", $out);
    }

    /** Port of quote.ts `f2`: at most two decimals, printed the way JavaScript prints a number. */
    private static function f2(float $v): string
    {
        return Money::numberToString(Money::jsRound($v * 100) / 100);
    }
}
