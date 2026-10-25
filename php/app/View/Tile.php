<?php

declare(strict_types=1);

namespace Speedat\View;

/**
 * One country tile on the quick view: the flag, the name and "from PKR 5,220 ·
 * 4-6 days". The from-price is `Copy::fromPrice()`, the same call the
 * calculator makes in the browser, so the tile the server painted and the tile
 * the script repaints carry the same figure.
 *
 * `from` is null under hold (no price may be in the HTML) and for a country
 * the rate card cannot price at 1 kg - the tile then prints the name alone.
 */
final class Tile
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly Flag $flag,
        public readonly ?FromPrice $from,
        /** "PKR 5,220", already formatted; "" when there is no price. */
        public readonly string $fromMoney = '',
    ) {
    }
}
