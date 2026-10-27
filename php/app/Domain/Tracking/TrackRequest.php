<?php

declare(strict_types=1);

namespace Speedat\Domain\Tracking;

/** A carrier tracking number and the provider's carrier code (0 = detect). */
final class TrackRequest
{
    public function __construct(
        public readonly string $number,
        public readonly int $carrierCode,
    ) {
    }
}
