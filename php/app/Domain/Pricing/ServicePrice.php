<?php

declare(strict_types=1);

namespace Speedat\Domain\Pricing;

/**
 * One service's price for one shipment. Port of `ServicePrice` in types.ts.
 *
 * `first` and `addl` are the rate-card numbers the price was built from, carried
 * along so the calculator can show its working; `base` is the price before tax,
 * `tax` the amount added and `total` the rounded number the customer is quoted.
 *
 * The TypeScript's `doc` and `docRate` are gone with the document rate itself
 * (owner, 2026-09-27): there is one way to reach a price now, and it is weight.
 */
final class ServicePrice
{
    public function __construct(
        public readonly ?float $first,
        public readonly ?float $addl,
        /** Slab mode: how many additional steps were charged on top of the first slab. */
        public readonly int $steps,
        /** Grid mode: the kilogram box whose price was charged; null in slab mode. */
        public readonly ?int $gridKg,
        public readonly float $base,
        public readonly float $tax,
        public readonly float $total,
        public readonly string $days,
    ) {
    }
}
