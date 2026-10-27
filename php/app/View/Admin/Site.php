<?php

declare(strict_types=1);

namespace Speedat\View\Admin;

use Speedat\Domain\Pricing\Destination;
use Speedat\Domain\Pricing\Engine;
use Speedat\Domain\Pricing\RateCard;
use Speedat\Domain\Pricing\Service;
use Speedat\Domain\Pricing\Settings;

/**
 * One rate document as the admin screens read it - the draft in the editor,
 * or the live version they are compared against.
 *
 * It is backed by the decoded document array (the shape
 * `src/lib/site/types.ts` describes) and parsed with the pricing layer's own
 * constructors, so a number the editor prints and a number the engine charges
 * come from one parser. `company` and `content` are free text with no
 * behaviour, so they stay strings keyed by name and every read is defaulted:
 * a half-filled document renders rather than fatals.
 *
 * Deliberately NOT built on `Speedat\Domain\SiteDoc`: that layer is being
 * written alongside this one, and an admin screen that cannot render until
 * another agent's constructor settles is an admin screen nobody can review.
 * The array shape is the contract both read.
 */
final class Site
{
    /**
     * @param array<string,string>                                                      $company
     * @param array<string,string>                                                      $content
     * @param array<string,string>                                                      $promo   the optional popup block, keyed by field
     * @param list<array{author:string,rating:int,text:string,date:string,show:bool}>   $reviews the owner-chosen customer reviews, in document order
     */
    private function __construct(
        public readonly RateCard $card,
        private readonly array $company,
        private readonly array $content,
        /** False while the site still shows sample rates. */
        public readonly bool $live,
        /** 0 for a document that was never published. */
        public readonly int $version,
        /** ISO 8601 as stored; Format::fmtDateTime() prints it. */
        public readonly string $publishedAt,
        private readonly array $promo = [],
        private readonly array $reviews = [],
    ) {
    }

    /** @param array<string,mixed> $doc */
    public static function fromArray(array $doc): self
    {
        return new self(
            card: RateCard::fromArray($doc),
            company: self::strings(is_array($doc['company'] ?? null) ? $doc['company'] : []),
            content: self::strings(is_array($doc['content'] ?? null) ? $doc['content'] : []),
            live: ($doc['live'] ?? false) === true,
            version: is_numeric($doc['version'] ?? null) ? (int) $doc['version'] : 0,
            publishedAt: self::text($doc['publishedAt'] ?? ''),
            promo: self::strings(is_array($doc['promo'] ?? null) ? $doc['promo'] : []),
            reviews: self::parseReviews(is_array($doc['reviews'] ?? null) ? $doc['reviews'] : []),
        );
    }

    public function settings(): Settings
    {
        return $this->card->settings;
    }

    /** @return list<Service> */
    public function services(): array
    {
        return $this->card->services;
    }

    /** Every destination in the admin's own order - hidden ones included; this is the editor. */
    /** @return list<Destination> */
    public function destinations(): array
    {
        return $this->card->destinations;
    }

    /** @return list<Destination> */
    public function activeAlpha(): array
    {
        $out = [];
        foreach ($this->card->destinations as $dest) {
            if ($dest->active) {
                $out[] = $dest;
            }
        }
        usort($out, static fn (Destination $a, Destination $b): int => strcmp($a->name, $b->name));

        return $out;
    }

    public function destination(string $id): ?Destination
    {
        return $this->card->destination($id);
    }

    /** A `company` field, printed exactly as typed. */
    public function company(string $key): string
    {
        return $this->company[$key] ?? '';
    }

    /** A `content` field. Blank means "automatic" for the fields the site generates. */
    public function content(string $key): string
    {
        return $this->content[$key] ?? '';
    }

    /** A `promo` text field, exactly as typed. */
    public function promo(string $key): string
    {
        return $this->promo[$key] ?? '';
    }

    /**
     * Whether the promo popup is switched on. `self::strings()` folds the stored
     * boolean to "1"/"" the same way a checkbox's value arrives, so a non-empty
     * value is "on".
     */
    public function promoEnabled(): bool
    {
        return ($this->promo['enabled'] ?? '') !== '';
    }

    /**
     * The owner-chosen customer reviews, in document order - every one, shown or
     * hidden, because this is the editor and the owner has to see and switch the
     * hidden ones too. Each carries its author, a whole star count 1 - 5, the
     * text, a free-text date and its "show on site" flag.
     *
     * @return list<array{author:string,rating:int,text:string,date:string,show:bool}>
     */
    public function reviews(): array
    {
        return $this->reviews;
    }

    public function currency(): string
    {
        return $this->card->settings->currency;
    }

    /** Per-kilogram pricing: one price box per whole kilogram instead of a slab pair. */
    public function isGrid(): bool
    {
        return $this->card->settings->isGrid();
    }

    /** @return list<int> the kilogram boxes a grid row carries, 1 ... cargo threshold */
    public function gridWeights(): array
    {
        return Engine::gridWeights($this->card->settings);
    }

    /**
     * @param  array<mixed,mixed>  $a
     * @return array<string,string>
     */
    private static function strings(array $a): array
    {
        $out = [];
        foreach ($a as $key => $value) {
            if (is_string($key)) {
                $out[$key] = self::text($value);
            }
        }

        return $out;
    }

    /**
     * The stored reviews, normalised to the shape the editor renders. A non-list
     * or an entry that is not an object yields no review for that slot, the same
     * forgiveness the document layer gives. The star count is pinned to a whole
     * 1 - 5 so a hand-edited value cannot draw six stars, and `show` defaults ON
     * so a review added to the document appears unless the owner hides it - both
     * matching `Domain\SiteDoc\Review`.
     *
     * @param  array<mixed,mixed> $a
     * @return list<array{author:string,rating:int,text:string,date:string,show:bool}>
     */
    private static function parseReviews(array $a): array
    {
        $out = [];
        foreach ($a as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $out[] = [
                'author' => self::text($entry['author'] ?? ''),
                'rating' => self::stars($entry['rating'] ?? 5),
                'text'   => self::text($entry['text'] ?? ''),
                'date'   => self::text($entry['date'] ?? ''),
                'show'   => self::truthy($entry['show'] ?? true),
            ];
        }

        return $out;
    }

    /** A whole star count inside 1 - 5; a non-number falls to five, never zero. */
    private static function stars(mixed $v): int
    {
        if (!is_int($v) && !is_float($v) && !(is_string($v) && is_numeric($v))) {
            return 5;
        }

        // Clamped before the cast: PHP 8.5 warns on (int) of a float that does
        // not fit, and "1e300" is numeric.
        return (int) round(max(1.0, min(5.0, (float) $v)));
    }

    /**
     * A stored flag read the way the rest of the document reads one: true unless
     * it is explicitly off, so an absent or "1" value is on and false / "" / "0"
     * is off.
     */
    private static function truthy(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }

        return !in_array($v, ['', '0', 0, 'off', 'false', false, null], true);
    }

    private static function text(mixed $v): string
    {
        return match (true) {
            is_string($v)            => $v,
            is_bool($v)              => $v ? '1' : '',
            is_int($v), is_float($v) => (string) $v,
            default                  => '',
        };
    }
}
