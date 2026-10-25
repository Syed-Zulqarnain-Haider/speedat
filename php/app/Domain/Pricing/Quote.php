<?php

declare(strict_types=1);

namespace Speedat\Domain\Pricing;

/**
 * A priced quote as the site hands it to the message builders and to the
 * database. Port of the `Quote` interface in src/lib/pricing/quote.ts.
 *
 * `chargeG` is here for a reason: the server re-prices a booked quote from the
 * weight the browser sent, and the chargeable grams - the greater of actual and
 * volumetric weight, before step rounding - are that weight. The billed grams
 * are derived FROM it, never the other way round, so a quote that carried only
 * the billed number could not be re-priced without trusting a rounding nobody
 * checked.
 *
 * There is no `type` and no `docRate`: the document rate left the product on
 * 2026-09-27 (owner), so a quote is a weight and a destination.
 */
final class Quote
{
    public function __construct(
        public readonly string $id,
        public readonly string $from,
        public readonly string $dest,
        public readonly string $destId,
        public readonly string $service,
        public readonly string $serviceId,
        public readonly string $days,
        /** Formatted delivery window; "" when estimates are off. */
        public readonly string $eta,
        public readonly string $pickup,
        public readonly int $pieces,
        public readonly string $piecesText,
        public readonly int $billableG,
        /** Chargeable grams before step rounding. */
        public readonly int $chargeG,
        /** Shipping price before add-ons. */
        public readonly float $total,
        public readonly int $version,
    ) {
    }

    /** @param array<string,mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            id: (string) ($a['id'] ?? ''),
            from: (string) ($a['from'] ?? ''),
            dest: (string) ($a['dest'] ?? ''),
            destId: (string) ($a['destId'] ?? ''),
            service: (string) ($a['service'] ?? ''),
            serviceId: (string) ($a['serviceId'] ?? ''),
            days: (string) ($a['days'] ?? ''),
            eta: (string) ($a['eta'] ?? ''),
            pickup: (string) ($a['pickup'] ?? ''),
            pieces: (int) ($a['pieces'] ?? 0),
            piecesText: (string) ($a['piecesText'] ?? ''),
            billableG: (int) ($a['billableG'] ?? 0),
            chargeG: (int) ($a['chargeG'] ?? 0),
            total: (float) ($a['total'] ?? 0),
            version: (int) ($a['version'] ?? 0),
        );
    }
}
