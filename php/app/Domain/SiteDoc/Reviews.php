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
    /**
     * The author line every seeded placeholder carries ({@see Seed::reviews()}).
     *
     * A review by this "author" is never shown, switched on or not. The seed
     * used to ship its three placeholders switched ON, so a fresh site, the
     * seed fallback when the database is down, and any owner who never opened
     * the reviews block printed three five-star "Sample review" cards and a
     * 5/5 AggregateRating in the JSON-LD - invented reviews, the one thing
     * the brief forbids (found 2026-10-25). The seed now ships them off; this
     * is the backstop for every document already published with them on.
     */
    public const SAMPLE_AUTHOR = 'Sample review';

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
     * the public site renders and the JSON-LD marks up. A seeded placeholder
     * (`SAMPLE_AUTHOR`) is never among them: it is not a customer.
     *
     * @return list<Review>
     */
    public function shown(): array
    {
        return array_values(array_filter(
            $this->items,
            static fn (Review $r): bool => $r->show && !self::isSample($r),
        ));
    }

    /** Whether a review is one of the seed's placeholders rather than a real one. */
    public static function isSample(Review $review): bool
    {
        return strcasecmp(trim($review->author), self::SAMPLE_AUTHOR) === 0;
    }
}
