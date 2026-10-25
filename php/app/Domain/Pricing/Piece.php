<?php

declare(strict_types=1);

namespace Speedat\Domain\Pricing;

/**
 * One line of the detailed form: `qty` identical pieces of `kg` each, with
 * optional dimensions in centimetres. Port of `PieceInput` in types.ts.
 *
 * Each field carries JavaScript's three states, because the engine treats them
 * differently: a field the form never filled in is NAN (undefined), a field
 * cleared to null is 0 (`Number(null) === 0`), and a typed value is itself.
 * Volumetric weight is only computed when all three dimensions are above zero,
 * so the difference decides whether a parcel is charged by size or by scale.
 */
final class Piece
{
    public function __construct(
        public readonly float $kg = NAN,
        public readonly float $qty = NAN,
        public readonly float $L = NAN,
        public readonly float $W = NAN,
        public readonly float $H = NAN,
    ) {
    }

    /** @param array<string,mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            kg: self::read($a, 'kg'),
            qty: self::read($a, 'qty'),
            L: self::read($a, 'L'),
            W: self::read($a, 'W'),
            H: self::read($a, 'H'),
        );
    }

    /**
     * Build a list of pieces from decoded JSON rows.
     *
     * @param  iterable<mixed> $rows
     * @return list<self>
     */
    public static function listFrom(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = self::fromArray($row);
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $a */
    private static function read(array $a, string $key): float
    {
        // Absent is `undefined`, which Number() turns into NaN; present-and-null
        // is 0. Both fail the `> 0` tests, but only the second prints as 0.
        if (!array_key_exists($key, $a)) {
            return NAN;
        }

        $n = Num::jsNumber($a[$key]);

        // INFINITY IS NOT A WEIGHT. `{"kg": "1e999"}` is nine characters of JSON
        // that Number() turns into INF, and `(int) INF` is 0 in PHP - so it used
        // to reach the engine, contribute nothing, and leave the quote at the
        // first slab: the cheapest price in the card for an arbitrarily heavy
        // shipment (audit round 1, finding 6). NAN is the state this class
        // already carries for "present, but not a number", every engine test
        // treats it as an unreadable field, and it cannot be mistaken for a
        // real weight anywhere downstream.
        return is_finite($n) ? $n : NAN;
    }
}
