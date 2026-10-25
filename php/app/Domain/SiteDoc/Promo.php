<?php

declare(strict_types=1);

namespace Speedat\Domain\SiteDoc;

/**
 * The optional promotional popup, as the published document carries it.
 *
 * A new sub-object modelled field for field on {@see Company}: immutable, one
 * FIELDS order, and a fromArray/toArray pair that round-trip byte for byte so
 * the document survives a store round trip like every other block.
 *
 * `enabled` is the ONLY switch, and it is FALSE by default. A fresh document,
 * and every version published before this block existed, therefore shows no
 * popup at all - the popup is opt-in, exactly as the owner asked. The four text
 * fields are whatever the owner typed and nothing else: this class never
 * invents a discount, a number or a percentage.
 *
 * The field ORDER matters and is not cosmetic - it is the order the stored JSON
 * carries and the order a review would list a change in, so it is kept the same
 * everywhere (`FIELDS`, the constructor, `toArray`).
 */
final class Promo
{
    public function __construct(
        /** Off by default: no popup renders until an admin turns this on. */
        public readonly bool $enabled = false,
        public readonly string $title = '',
        public readonly string $message = '',
        /** The button's words, e.g. "Get a price". Blank hides the button. */
        public readonly string $ctaLabel = '',
        /** Where the button points; blank hides the button. */
        public readonly string $ctaHref = '',
    ) {
    }

    /** The keys, in the order the document carries them. */
    public const FIELDS = ['enabled', 'title', 'message', 'ctaLabel', 'ctaHref'];

    /** @param array<string,mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            // `Text::truthy` and not `=== true`, so a stored "0"/"1" reads the
            // same here as it does in the Next.js half of the product - the same
            // reasoning `Migrate` uses for the document's `live` flag.
            enabled: Text::truthy($a['enabled'] ?? false),
            title: Text::of($a['title'] ?? ''),
            message: Text::of($a['message'] ?? ''),
            ctaLabel: Text::of($a['ctaLabel'] ?? ''),
            ctaHref: Text::of($a['ctaHref'] ?? ''),
        );
    }

    /** @return array<string,bool|string> in FIELDS order */
    public function toArray(): array
    {
        return [
            'enabled'  => $this->enabled,
            'title'    => $this->title,
            'message'  => $this->message,
            'ctaLabel' => $this->ctaLabel,
            'ctaHref'  => $this->ctaHref,
        ];
    }
}
