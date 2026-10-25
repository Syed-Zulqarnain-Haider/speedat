<?php

declare(strict_types=1);

namespace Speedat\Domain\Leads;

/**
 * How many leads sit in each status. Port of `LeadCounts` in
 * src/lib/leads.ts - the numbers on the admin's inbox badges.
 *
 * A status the database has never seen is zero, not missing: a badge that
 * disappears when its count is nought is a badge the owner learns to distrust.
 */
final class LeadCounts
{
    public function __construct(
        public readonly int $new = 0,
        public readonly int $contacted = 0,
        public readonly int $booked = 0,
        public readonly int $lost = 0,
    ) {
    }

    /** @param array<string,int> $byStatus */
    public static function fromMap(array $byStatus): self
    {
        // Positional, not named: `new:` as a named argument is legal but reads
        // like the start of an expression, and this file has to parse cleanly
        // on 8.2 and 8.3 with no surprises.
        return new self(
            $byStatus[LeadStatus::NEW] ?? 0,
            $byStatus[LeadStatus::CONTACTED] ?? 0,
            $byStatus[LeadStatus::BOOKED] ?? 0,
            $byStatus[LeadStatus::LOST] ?? 0,
        );
    }

    public function total(): int
    {
        return $this->new + $this->contacted + $this->booked + $this->lost;
    }

    /** The one number the navigation badge shows: what still needs a person. */
    public function open(): int
    {
        return $this->new + $this->contacted;
    }

    /** @return array<string,int> */
    public function toArray(): array
    {
        return [
            LeadStatus::NEW       => $this->new,
            LeadStatus::CONTACTED => $this->contacted,
            LeadStatus::BOOKED    => $this->booked,
            LeadStatus::LOST      => $this->lost,
        ];
    }
}
