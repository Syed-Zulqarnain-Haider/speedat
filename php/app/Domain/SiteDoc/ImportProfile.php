<?php

declare(strict_types=1);

namespace Speedat\Domain\SiteDoc;

use Speedat\Domain\Pricing\Num;

/**
 * A remembered spreadsheet layout. Port of `ImportProfile` in
 * src/lib/site/types.ts.
 *
 * The signature is the sheet's header row, normalised and joined with "|": the
 * next time the same carrier sends the same workbook, the importer recognises
 * it and reuses the column mapping instead of asking again.
 */
final class ImportProfile
{
    /** @param array<string,int> $map field key -> column index, -1 = not in the file */
    public function __construct(
        public readonly string $signature = '',
        /** File name the layout was first seen in. */
        public readonly string $name = '',
        public readonly array $map = [],
        public readonly string $savedAt = '',
        /** Prices in this layout are carrier costs; apply the margin below. */
        public readonly bool $cost = false,
        public readonly float $margin = 0.0,
        public readonly float $mround = 0.0,
    ) {
    }

    /** @param array<string,mixed> $a */
    public static function fromArray(array $a): self
    {
        $map = [];
        if (isset($a['map']) && is_array($a['map'])) {
            foreach ($a['map'] as $key => $column) {
                $map[(string) $key] = (int) Num::jsNumber($column);
            }
        }

        return new self(
            signature: Text::of($a['signature'] ?? ''),
            name: Text::of($a['name'] ?? ''),
            map: $map,
            savedAt: Text::of($a['savedAt'] ?? ''),
            cost: (bool) ($a['cost'] ?? false),
            margin: Num::jsNumber($a['margin'] ?? 0),
            mround: Num::jsNumber($a['mround'] ?? 0),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'signature' => $this->signature,
            'name'      => $this->name,
            // An empty map encodes as [] rather than {}. Both sides read it with
            // Object.keys()/foreach, so the shape survives the round trip.
            'map'       => $this->map,
            'savedAt'   => $this->savedAt,
            'cost'      => $this->cost,
            'margin'    => $this->margin,
            'mround'    => $this->mround,
        ];
    }
}
