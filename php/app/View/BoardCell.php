<?php

declare(strict_types=1);

namespace Speedat\View;

/**
 * One service's cell on the rates board: the 1 kg parcel total with the
 * default add-ons, and the transit days. Port of `BoardCell` in
 * src/components/site/home/routes.ts.
 *
 * `price` is null under hold and when the service is not offered to this
 * country, and the template prints nothing rather than a zero or a dash -
 * under hold that is the whole promise, so the null is load-bearing.
 *
 * `days` is null for the same reasons AND one more: when the engine WAS asked
 * for a 1 kg price and had none, the days go with it (the owner's skippable
 * weights, 2026-09-28 - "say nothing about the others"). A days-only cell is
 * therefore only ever a HELD board, where no price was asked for at all.
 * `Board::rows()` is where that is decided, and says why.
 */
final class BoardCell
{
    public function __construct(
        public readonly string $serviceId,
        public readonly ?float $price,
        /** "3-5" or "4"; null when the rate carries no number, and when a price was asked for and refused. */
        public readonly ?string $days,
    ) {
    }
}
