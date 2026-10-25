<?php

declare(strict_types=1);

namespace Speedat\Domain\Pricing;

/**
 * When a parcel handed over today can be collected and delivered. Port of
 * `DeliveryEstimate` in src/lib/pricing/dates.ts.
 */
final class DeliveryEstimate
{
    public function __construct(
        /** The first working day the parcel can be collected. */
        public readonly CivilDate $pickup,
        public readonly CivilDate $from,
        public readonly CivilDate $to,
        /** True when pickup moved off the requested day: cutoff passed, holiday, or a non-working day. */
        public readonly bool $moved,
        public readonly int $minDays,
        public readonly int $maxDays,
    ) {
    }
}
