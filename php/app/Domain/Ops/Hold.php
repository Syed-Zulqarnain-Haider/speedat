<?php

declare(strict_types=1);

namespace Speedat\Domain\Ops;

/**
 * The price hold, as the operational surface sees it: the switch that takes
 * every price off the website at once without publishing anything.
 *
 * While it is on the site takes no quotes, so "how long has it been on" is an
 * operational number, not a cosmetic one - it is the difference between an
 * admin mid-edit and a shop that has been shut since Friday.
 *
 * The message the visitor reads is not here: Ops reports the state, never the
 * copy. `since` is UTC.
 */
final class Hold
{
    public function __construct(
        public readonly bool $on,
        public readonly ?\DateTimeImmutable $since,
        public readonly string $by = '',
    ) {
    }

    public static function off(): self
    {
        return new self(false, null, '');
    }

    /** Seconds the hold has been on; zero when it is off or the start is unknown. */
    public function seconds(\DateTimeImmutable $now): int
    {
        if (!$this->on || $this->since === null) {
            return 0;
        }

        return max(0, $now->getTimestamp() - $this->since->getTimestamp());
    }

    public function hours(\DateTimeImmutable $now): int
    {
        return intdiv($this->seconds($now), 3600);
    }
}
