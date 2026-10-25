<?php

declare(strict_types=1);

namespace Speedat\View;

/**
 * The "from PKR 5,220 · 4-6 days" on a country tile: the cheapest service's
 * 1 kg parcel total with the add-ons that are on by default, and that
 * service's transit days as `Copy::daysText()` prints them.
 *
 * Port of the `FromPrice` interface in src/lib/site/copy.ts.
 */
final class FromPrice
{
    public function __construct(
        /** The 1 kg parcel total plus the on-by-default add-ons - the number the calculator prints for 1 kg. */
        public readonly float $total,
        /** "3-5", "4", or "" when the rate carries no number. */
        public readonly string $days,
        public readonly string $serviceId,
    ) {
    }
}
