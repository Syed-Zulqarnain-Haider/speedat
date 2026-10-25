<?php

declare(strict_types=1);

namespace Speedat\View;

/**
 * One country's row on the rates board: the flag, the name and one cell per
 * service. Port of `BoardRow` in src/components/site/home/routes.ts, with the
 * flag resolved here so the template only prints, and without its `doc` - the
 * flat document rate left the product on 2026-09-27 (owner).
 */
final class BoardRow
{
    /** @param list<BoardCell> $cells one per service, in the document's order */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $cells,
        public readonly Flag $flag,
    ) {
    }
}
