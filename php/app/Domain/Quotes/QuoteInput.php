<?php

declare(strict_types=1);

namespace Speedat\Domain\Quotes;

use Speedat\Core\Str;
use Speedat\Domain\Pricing\Engine;
use Speedat\Domain\Pricing\Piece;
use Speedat\Domain\Pricing\QuoteText;

/**
 * What the browser posted when a customer acted on a price - validated, capped,
 * and stripped of every number the server is going to work out for itself.
 *
 * READ THE FIELD NAMES CAREFULLY. `chargeG` is an input. `claimedBillableG` and
 * `claimedTotal` are not: they are what the browser SAID, kept only so that a
 * disagreement can be recorded next to the stored quote. QuoteService never
 * prices from either of them. The naming is deliberate - the Next.js route
 * called the billed weight `billableG` and then priced with it, and a field
 * called `billableG` sitting in a request body is an invitation to do that
 * again.
 *
 * WHAT MAY BE ASSERTED AND WHAT MAY NOT
 *   may:     which destination, which service, the chargeable weight (or the
 *            pieces it comes from), which add-ons were ticked, and the
 *            descriptive text the customer sees
 *   may not: the billed weight, the shipping price, an add-on's amount, the
 *            total
 *
 * A SHIPMENT TYPE IS NO LONGER ONE OF THE INPUTS. The route used to take "pkg"
 * or "doc", and the flat document price hung off it; the owner removed the
 * document rate on 2026-09-27, so there is nothing left for the field to
 * select. A body that still carries `type` - a tab opened before the change -
 * is accepted and the key ignored, because refusing it would lose a real
 * booking over a word that can no longer change a number.
 *
 * Every cap is the narrower of the column width in db/migrations/0001_init.sql
 * and the limit the TypeScript route enforced, so nothing that used to be
 * accepted is refused and nothing that used to be refused is accepted.
 */
final class QuoteInput
{
    /**
     * billable_g / the chargeable weight are INT UNSIGNED; a tonne is already cargo.
     *
     * This is the BUSINESS cap, deliberately far below what the column holds
     * (QuoteRepository::MAX_BILLABLE_G, 4,294,967,295 g): a courier who is asked
     * about 1,001 kg is being asked about freight, not about a parcel. The column
     * is guarded separately, in the repository, so a caller that bypasses this
     * class still cannot reach an out-of-range INSERT (audit round 1, finding
     * 13). tests/Pricing/QuoteLimitsTest.php pins the order of the two.
     *
     * It is the engine's own constant, because the engine has to know it too:
     * with the cargo threshold at 0 ("no limit") the calculator used to price a
     * 1,200 kg shipment that this cap then refused to log. Engine::priceAll()
     * now answers "cargo" above the same tonne, so the page never offers a Book
     * link for a quote the server will not store.
     */
    public const MAX_GRAMS = Engine::NO_LIMIT_MAX_G;

    /** quotes.total is INT; the TypeScript route capped the claim here too. */
    public const MAX_TOTAL = 100_000_000;

    /** versions.version is INT UNSIGNED. */
    public const MAX_VERSION = 4_294_967_295;

    private const MAX_DEST_ID     = 60;
    private const MAX_SERVICE_ID  = 40;
    private const MAX_PIECES_TEXT = 600;
    private const MAX_ETA         = 80;
    private const MAX_PICKUP      = 40;
    private const MAX_FROM        = 80;
    private const MAX_CONTENTS    = 120;
    private const MAX_ADDONS      = 20;
    /**
     * QuoteText's, not a number of our own: resolveAddons() compares the
     * document's labels cut to this same length, so a label longer than the
     * cap still matches the one the browser posted (which this class cuts).
     */
    private const MAX_ADDON_LABEL = QuoteText::ADDON_LABEL_MAX;

    /** The detailed form has ten rows; twenty is generous and bounded. */
    private const MAX_PIECE_ROWS = 20;

    /**
     * @param list<Piece>  $pieces     the weighed lines, when the browser sent
     *                                 them; preferred over $chargeG because the
     *                                 volumetric weight can then be recomputed
     *                                 too rather than trusted
     * @param list<string> $addonLabels labels only - amounts come from the
     *                                 published settings
     */
    private function __construct(
        public readonly string $id,
        public readonly string $destId,
        public readonly string $serviceId,
        /** Chargeable grams before step rounding, or null when pieces were sent. */
        public readonly ?int $chargeG,
        public readonly array $pieces,
        public readonly int $version,
        public readonly bool $booked,
        public readonly array $addonLabels,
        public readonly string $piecesText,
        public readonly string $eta,
        public readonly string $pickup,
        public readonly string $from,
        public readonly string $mode,
        public readonly string $contents,
        /** What the browser said the billed weight was. Advisory. Never priced from. */
        public readonly ?int $claimedBillableG,
        /** What the browser said the total was. Advisory. Never stored as the price. */
        public readonly ?float $claimedTotal,
    ) {
    }

    /**
     * Parse a decoded request body.
     *
     * @param  array<string,mixed> $body
     * @throws QuoteRejected on anything that is not a quote this site could
     *         have issued - always with the same flat "Invalid quote" code, so
     *         probing the endpoint teaches nothing about which field was wrong
     */
    public static function fromArray(array $body): self
    {
        $id        = QuoteId::parse(self::string($body['id'] ?? '', QuoteId::MAX_LENGTH));
        $destId    = self::required($body['destId'] ?? '', self::MAX_DEST_ID);
        $serviceId = self::required($body['serviceId'] ?? '', self::MAX_SERVICE_ID);

        $pieces  = self::pieces($body['pieces'] ?? null);
        $chargeG = self::grams($body['chargeG'] ?? null);

        // One of the two has to be there. Without either, there is nothing to
        // price from and nothing to check the browser's claim against - and
        // falling back to the browser's billed weight is exactly the hole this
        // class exists to close.
        if ($pieces === [] && $chargeG === null) {
            throw QuoteRejected::badRequest();
        }

        $mode = self::string($body['mode'] ?? '', 10);
        if ($mode !== '' && $mode !== 'quick' && $mode !== 'detail') {
            throw QuoteRejected::badRequest();
        }

        return new self(
            id: $id,
            destId: $destId,
            serviceId: $serviceId,
            chargeG: $chargeG,
            pieces: $pieces,
            version: self::version($body['version'] ?? null),
            booked: (bool) ($body['booked'] ?? false),
            addonLabels: self::addonLabels($body['addons'] ?? null),
            piecesText: self::string($body['piecesText'] ?? '', self::MAX_PIECES_TEXT),
            eta: self::string($body['eta'] ?? '', self::MAX_ETA),
            pickup: self::string($body['pickup'] ?? '', self::MAX_PICKUP),
            from: self::string($body['from'] ?? '', self::MAX_FROM),
            mode: $mode,
            contents: self::string($body['contents'] ?? '', self::MAX_CONTENTS),
            claimedBillableG: self::grams($body['billableG'] ?? null),
            claimedTotal: self::total($body['total'] ?? null),
        );
    }

    /* ---------------------------------------------------------------- parts */

    /**
     * Grams: a whole number in range, or null.
     *
     * A value out of range is refused rather than clamped. Clamping a forged
     * 10,000 kg down to the maximum would quietly price it, and the customer
     * would have a stored quote nobody meant to give them.
     */
    private static function grams(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_int($v) && !(is_float($v) && $v == floor($v)) && !(is_string($v) && preg_match('/^\d{1,9}$/', $v) === 1)) {
            throw QuoteRejected::badRequest();
        }

        $g = (int) $v;

        if ($g < 1 || $g > self::MAX_GRAMS) {
            throw QuoteRejected::badRequest();
        }

        return $g;
    }

    private static function total(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_int($v) && !is_float($v) && !(is_string($v) && is_numeric($v))) {
            throw QuoteRejected::badRequest();
        }

        $n = (float) $v;

        if (!is_finite($n) || $n < 0 || $n > self::MAX_TOTAL) {
            throw QuoteRejected::badRequest();
        }

        return $n;
    }

    private static function version(mixed $v): int
    {
        if ($v === null || $v === '') {
            return 0;
        }
        if (!is_int($v) && !(is_string($v) && preg_match('/^\d{1,10}$/', $v) === 1)) {
            throw QuoteRejected::badRequest();
        }

        $n = (int) $v;

        if ($n < 0 || $n > self::MAX_VERSION) {
            throw QuoteRejected::badRequest();
        }

        return $n;
    }

    /** @return list<Piece> */
    private static function pieces(mixed $rows): array
    {
        if ($rows === null) {
            return [];
        }
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > self::MAX_PIECE_ROWS) {
            throw QuoteRejected::badRequest();
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw QuoteRejected::badRequest();
            }
            $piece = Piece::fromArray($row);

            // A line with no weight is one the engine skips anyway; a line with
            // an absurd one is a payload designed to make ceil() hurt.
            if (is_finite($piece->kg) && ($piece->kg < 0 || $piece->kg > 10_000)) {
                throw QuoteRejected::badRequest();
            }
            if (is_finite($piece->qty) && ($piece->qty < 0 || $piece->qty > 1_000)) {
                throw QuoteRejected::badRequest();
            }
            foreach ([$piece->L, $piece->W, $piece->H] as $dim) {
                if (is_finite($dim) && ($dim < 0 || $dim > 10_000)) {
                    throw QuoteRejected::badRequest();
                }
            }

            $out[] = $piece;
        }

        return $out;
    }

    /** @return list<string> */
    private static function addonLabels(mixed $labels): array
    {
        if ($labels === null) {
            return [];
        }
        if (!is_array($labels) || !array_is_list($labels) || count($labels) > self::MAX_ADDONS) {
            throw QuoteRejected::badRequest();
        }

        $out = [];
        foreach ($labels as $label) {
            if (!is_scalar($label)) {
                throw QuoteRejected::badRequest();
            }
            $clean = Str::limit(Str::squish((string) $label), self::MAX_ADDON_LABEL);
            if ($clean !== '') {
                $out[] = $clean;
            }
        }

        return $out;
    }

    /**
     * A field that has to be there and has to fit.
     *
     * It used to name the field in the refusal ("Invalid quote (destination)")
     * and so told a prober which of the two it had got wrong. The field name
     * is worth nothing to an honest caller - it sent the body - and worth a
     * little to a dishonest one, so it is gone. Every refusal from this class
     * is now the same sentence.
     */
    private static function required(mixed $v, int $max): string
    {
        $s = self::string($v, $max + 1);

        if ($s === '' || mb_strlen($s, 'UTF-8') > $max) {
            throw QuoteRejected::badRequest();
        }

        return $s;
    }

    /** A scalar, trimmed of control characters and cut to size. */
    private static function string(mixed $v, int $max): string
    {
        if ($v === null) {
            return '';
        }
        if (!is_scalar($v)) {
            throw QuoteRejected::badRequest();
        }

        // Control characters have no business in any of these fields and would
        // travel straight into a WhatsApp message and an admin page.
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $v) ?? '';

        return Str::limit(trim($s), $max);
    }
}
