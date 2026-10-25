<?php

declare(strict_types=1);

namespace Speedat\Domain\Pricing;

/**
 * What the shipment weighs, in integer grams. Port of `Weights` in types.ts.
 *
 * Grams, not kilograms, because 0.5 kg steps in floating point drift: 0.1 + 0.2
 * is not 0.3 in JavaScript and it is not 0.3 in PHP either. Everything the
 * price depends on is an integer here, and the two engines therefore cannot
 * disagree by a rounding step.
 */
final class Weights
{
    /** @param list<WeightLine> $lines */
    public function __construct(
        public readonly int $pieces,
        public readonly int $actualG,
        public readonly int $volG,
        /** Sum of chargeable grams, before rounding up to the step. */
        public readonly int $chargeG,
        /** Grams actually billed: rounded up to the next step, at least the first slab. */
        public readonly int $billableG,
        public readonly bool $volumetricWins,
        public readonly array $lines,
    ) {
    }
}
