<?php

declare(strict_types=1);

namespace Speedat\Domain\SiteDoc;

use Speedat\Domain\Pricing\Num;

/**
 * One customer review the owner has chosen to show, as the published document
 * carries it.
 *
 * A sub-object modelled field for field on {@see Promo}: immutable, one FIELDS
 * order, and a fromArray/toArray pair that round-trip byte for byte so the
 * document survives a store round trip like every other block.
 *
 * These are reviews the OWNER picks from his Google Business profile and pastes
 * in; the site never invents one. `show` is the per-review switch and defaults
 * to true, so a review added to the document is shown unless the owner hides it.
 * `rating` is clamped to 1 - 5 so a hand-edited or imported value can never put
 * six stars (or zero) on the page.
 *
 * The field ORDER matters and is not cosmetic - it is the order the stored JSON
 * carries, so it is kept the same everywhere (`FIELDS`, the constructor,
 * `toArray`).
 */
final class Review
{
    /** The lowest and highest a star rating may be; anything outside is clamped in. */
    public const RATING_MIN = 1;
    public const RATING_MAX = 5;

    public function __construct(
        public readonly string $author = '',
        /** Whole stars, 1 - 5, clamped. */
        public readonly int $rating = self::RATING_MAX,
        public readonly string $text = '',
        /** Free text as the owner types it, e.g. "March 2026". */
        public readonly string $date = '',
        /** Shown by default: a review added to the document appears unless hidden. */
        public readonly bool $show = true,
    ) {
    }

    /** The keys, in the order the document carries them. */
    public const FIELDS = ['author', 'rating', 'text', 'date', 'show'];

    /** @param array<string,mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            author: Text::of($a['author'] ?? ''),
            rating: self::clampRating($a['rating'] ?? self::RATING_MAX),
            text: Text::of($a['text'] ?? ''),
            date: Text::of($a['date'] ?? ''),
            // `Text::truthy` and not `=== true`, so a stored "0"/"1" reads the
            // same here as it does on the Next.js side, the reasoning the
            // document's `live` flag and the promo's `enabled` already use.
            // The default is TRUE: a review that carries no `show` key is shown.
            show: Text::truthy($a['show'] ?? true),
        );
    }

    /** @return array<string,bool|int|string> in FIELDS order */
    public function toArray(): array
    {
        return [
            'author' => $this->author,
            'rating' => $this->rating,
            'text'   => $this->text,
            'date'   => $this->date,
            'show'   => $this->show,
        ];
    }

    /**
     * A whole star count inside 1 - 5.
     *
     * `Num::jsNumber` is the faithful port of JavaScript's `Number()`, so a
     * stored "5", 5 and 5.0 all read the same; a value that is not a number
     * (or an infinite one) falls to the maximum rather than to NaN, and the
     * result is rounded to a whole star and pinned into the range.
     */
    private static function clampRating(mixed $v): int
    {
        $n = Num::jsNumber($v);
        if (!is_finite($n)) {
            $n = (float) self::RATING_MAX;
        }

        // Clamped before the cast: PHP 8.5 warns on (int) of a float that does
        // not fit in an int.
        return (int) round(max((float) self::RATING_MIN, min((float) self::RATING_MAX, $n)));
    }
}
