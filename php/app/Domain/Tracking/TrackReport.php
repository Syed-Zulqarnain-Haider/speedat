<?php

declare(strict_types=1);

namespace Speedat\Domain\Tracking;

/**
 * What a provider knows about one number right now.
 *
 * `latest` is the provider's own summary of where the parcel is (a Stage, or
 * null when it has nothing yet). It is what moves the desk status; the
 * checkpoints are what the customer reads.
 */
final class TrackReport
{
    /**
     * @param list<Checkpoint> $checkpoints oldest first
     */
    public function __construct(
        public readonly ?string $latest,
        public readonly array $checkpoints,
        /** '' when the number was answered; otherwise why not, for the admin desk. */
        public readonly string $error = '',
        /** The carrier code the provider ended up using (it may have detected one). */
        public readonly int $carrierCode = 0,
    ) {
    }

    public static function failed(string $error): self
    {
        return new self(null, [], $error);
    }
}
