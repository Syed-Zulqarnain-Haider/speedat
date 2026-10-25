<?php

declare(strict_types=1);

namespace Speedat\View;

use Speedat\Domain\SiteDoc\Countries;

/**
 * A country flag as the template needs it: either an image with its own width
 * and height (so nothing shifts while it loads) or the two-letter badge for a
 * country with no file. Port of the decision `src/components/site/Flag.tsx`
 * makes, taken out of the template because "which of the two shapes" is a
 * decision, not markup.
 *
 * The name -> ISO code mapping belongs to `Speedat\Domain\SiteDoc\Countries`;
 * this only decides how the answer is drawn. The badge letters live here
 * because they exist nowhere but on screen.
 *
 * The flag is always printed beside the country's name, so the image is
 * decorative: `alt=""`, and the badge is `aria-hidden`. Height is
 * `round(width * 0.75)` - the flag-icons set is 4:3.
 */
final class Flag
{
    private function __construct(
        /** Lowercase ISO code, or null for the badge. */
        public readonly ?string $code,
        /** The two letters the badge prints; "" when there is a code. */
        public readonly string $badge,
        public readonly int $width,
        public readonly int $height,
        /**
         * Below the fold, so the file may wait. The country tiles, the "1
         * Country" tick and the hold panel are NOT lazy - the flag is the one
         * symbol a first-time visitor recognises and must be in the first paint.
         */
        public readonly bool $lazy,
    ) {
    }

    public static function for(string $name, string $id = '', int $size = 32, bool $lazy = false): self
    {
        $code = Countries::flagCode($name, $id === '' ? null : $id);

        return new self(
            code: $code,
            badge: $code === null ? self::badgeFor($name) : '',
            width: $size,
            height: (int) round($size * 0.75),
            lazy: $lazy,
        );
    }

    /** The first two letters of the name, upper-cased, exactly as Flag.tsx builds it. */
    public static function badgeFor(string $name): string
    {
        return strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name) ?? '', 0, 2));
    }

    /** The path under public/assets, for View::asset(). Empty for a badge. */
    public function assetPath(): string
    {
        return $this->code === null ? '' : 'flags/' . $this->code . '.svg';
    }
}
