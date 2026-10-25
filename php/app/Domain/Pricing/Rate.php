<?php

declare(strict_types=1);

namespace Speedat\Domain\Pricing;

/**
 * What one destination charges for one service. Port of the `Rate` interface
 * in src/lib/pricing/types.ts.
 *
 * A missing price is null, never 0: `null` means "not offered" and is skipped,
 * while 0 is a price of zero and is reported as an error by validateSite.
 *
 * THERE IS NO `doc` FIELD, ON PURPOSE. The TypeScript original carries a flat
 * document price per rate; the owner removed the document rate from the product
 * on 2026-09-27, so every shipment - papers included - is priced by weight. A
 * `doc` key in stored JSON is simply not read here, and SiteDoc\Migrate drops
 * it, so an already-published version still loads and republishes clean.
 */
final class Rate
{
    /**
     * @param array<string,float|null>|null $grid Price per whole kilogram, keyed "1", "2", ...
     *                                            null when the rate has no grid at all.
     */
    public function __construct(
        public readonly ?float $first = null,
        public readonly ?float $addl = null,
        /** Transit time as typed, e.g. "3-5". Empty string when not set. */
        public readonly string $days = '',
        public readonly ?array $grid = null,
    ) {
    }

    /** @param array<string,mixed> $a */
    public static function fromArray(array $a): self
    {
        $grid = null;

        if (isset($a['grid']) && is_array($a['grid'])) {
            /** @var array<string,float|null> $boxes */
            $boxes = [];
            foreach (Num::jsKeyOrder($a['grid']) as $kg => $price) {
                $boxes[(string) $kg] = Num::field($price);
            }
            $grid = $boxes;
        }

        return new self(
            first: Num::field($a['first'] ?? null),
            addl: Num::field($a['addl'] ?? null),
            days: isset($a['days']) && is_scalar($a['days']) ? (string) $a['days'] : '',
            grid: $grid,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $out = [];

        if ($this->first !== null) {
            $out['first'] = $this->first;
        }
        if ($this->addl !== null) {
            $out['addl'] = $this->addl;
        }
        if ($this->days !== '') {
            $out['days'] = $this->days;
        }
        if ($this->grid !== null) {
            $out['grid'] = $this->grid;
        }

        return $out;
    }

    /** @param array<string,float|null>|null $grid */
    public function withGrid(?array $grid): self
    {
        return new self($this->first, $this->addl, $this->days, $grid === null ? null : Num::jsKeyOrder($grid));
    }

    /** The grid boxes in JavaScript key order, or an empty array when there is no grid. */
    /** @return array<string,float|null> */
    public function boxes(): array
    {
        return $this->grid ?? [];
    }
}
