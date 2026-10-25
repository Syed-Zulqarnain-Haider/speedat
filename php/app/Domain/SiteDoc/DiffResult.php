<?php

declare(strict_types=1);

namespace Speedat\Domain\SiteDoc;

/**
 * What changed between two site documents. Port of `Diff` in
 * src/lib/site/diff.ts.
 *
 * `count` is not `count($lines)`: a destination that is added counts once even
 * though it may bring twenty prices with it, and that is the number the version
 * history shows.
 */
final class DiffResult
{
    /**
     * @param array<string,true> $byDest destination ids that changed in any way, in the order they changed
     * @param list<DiffLine>     $lines
     */
    public function __construct(
        public readonly int $count,
        public readonly array $byDest,
        public readonly array $lines,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->count === 0;
    }

    /** @return list<string> */
    public function changedDestinations(): array
    {
        return array_keys($this->byDest);
    }
}
