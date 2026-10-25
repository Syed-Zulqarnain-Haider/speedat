<?php

declare(strict_types=1);

namespace Speedat\Domain\Pricing;

/** One priced row of the weight table. Port of `WeightLine` in types.ts. */
final class WeightLine
{
    public function __construct(
        public readonly int $qty,
        public readonly float $kg,
        /** Dimensions as entered; NAN when the field was never filled in. */
        public readonly float $L,
        public readonly float $W,
        public readonly float $H,
        public readonly int $actualG,
        public readonly int $volG,
        /** Chargeable grams for ONE piece: the larger of actual and volumetric. */
        public readonly int $pieceG,
        public readonly bool $volumetricWins,
    ) {
    }
}
