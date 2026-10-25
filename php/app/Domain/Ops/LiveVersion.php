<?php

declare(strict_types=1);

namespace Speedat\Domain\Ops;

/**
 * The published version the website is serving right now, reduced to the three
 * facts the operational surface asks about: which version, when it went live,
 * and whether it is flagged live at all.
 *
 * Ops never needs the document itself - health reports its age and metrics
 * export its number - so this carries no rates, no company record and no
 * content. Domain\SiteDoc owns all of that.
 *
 * `publishedAt` is UTC. Everything stored is UTC; only the display layer ever
 * converts (see Speedat\Core\Clock).
 */
final class LiveVersion
{
    public function __construct(
        public readonly int $version,
        public readonly ?\DateTimeImmutable $publishedAt,
        public readonly bool $live,
    ) {
    }

    /**
     * Seconds since publish. Zero, not a negative number, when the stored
     * timestamp is in the future: that means the clocks disagree, and a gauge
     * that goes negative makes every alert rule downstream wrong as well.
     */
    public function ageSeconds(\DateTimeImmutable $now): int
    {
        if ($this->publishedAt === null) {
            return 0;
        }

        return max(0, $now->getTimestamp() - $this->publishedAt->getTimestamp());
    }

    public function ageDays(\DateTimeImmutable $now): int
    {
        return intdiv($this->ageSeconds($now), 86400);
    }
}
