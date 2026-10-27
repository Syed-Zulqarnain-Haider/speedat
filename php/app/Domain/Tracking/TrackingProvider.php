<?php

declare(strict_types=1);

namespace Speedat\Domain\Tracking;

/**
 * A tracking service. 17TRACK today (SeventeenTrack); a direct DHL adapter
 * later would implement the same two calls - for a service with no "register"
 * step, register() answers every number with ''.
 *
 * NEITHER CALL THROWS on a network or provider failure. A failure is a value:
 * a reason string per number, which the cron writes on the admin desk and
 * tries again an hour later. The hourly job must never stop at one bad answer.
 */
interface TrackingProvider
{
    /** A short stable name, part of the key a registration is stored under. */
    public function name(): string;

    /** How many numbers one call may carry. */
    public function batchSize(): int;

    /**
     * Ask the provider to start following these numbers.
     *
     * @param list<TrackRequest> $requests at most batchSize()
     * @return array<string,string> number => '' when accepted (or already known), otherwise the reason
     */
    public function register(array $requests): array;

    /**
     * What the provider knows now.
     *
     * @param list<TrackRequest> $requests at most batchSize()
     * @return array<string,TrackReport> number => report; a number missing from the answer is a failure
     */
    public function fetch(array $requests): array;
}
