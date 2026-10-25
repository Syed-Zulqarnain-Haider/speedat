<?php

declare(strict_types=1);

namespace Speedat\Domain\SiteDoc;

/**
 * The customer reviews the owner has chosen to show, as the published document
 * carries them: an ordered list of {@see Review}, nothing more.
 *
 * Modelled on {@see Promo}'s shape, one step up - where Promo is a single block
 * this is a list of them, so it keeps the same immutable, round-trips-byte-for-byte
 * discipline while delegating each entry to Review. It ships EMPTY: a fresh
 * document, and every version published before this block existed, carries no
 * reviews at all, so the public site shows none until the owner adds some.
 *
 * The list is stored as a JSON array (not an object), so `toArray()` returns a
 * plain list and `fromArray()` reads one; a stored entry that is not an object
 * is skipped rather than tripped over, the same forgiveness
 * `SiteData::fromArray()` already gives import profiles.
 */
final class Reviews
{
    /** @param list<Review> $items */
    public function __construct(
        public readonly array $items = [],
    ) {
    }

    /**
     * Read a stored list of reviews. A non-list, or an entry that is not an
     * object, yields no review for that slot rather than an error.
     *
     * @param array<array-key,mixed> $a
     */
    public static function fromArray(array $a): self
    {
        $items = [];
        foreach ($a as $entry) {
            if (is_array($entry)) {
                $items[] = Review::fromArray($entry);
            }
        }

        return new self($items);
    }

    /** @return list<array<string,bool|int|string>> each review in FIELDS order */
    public function toArray(): array
    {
        $out = [];
        foreach ($this->items as $review) {
            $out[] = $review->toArray();
        }

        return $out;
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Just the reviews the owner has left switched on, in document order - what
     * the public site renders.
     *
     * @return list<Review>
     */
    public function shown(): array
    {
        return array_values(array_filter($this->items, static fn (Review $r): bool => $r->show));
    }
}
