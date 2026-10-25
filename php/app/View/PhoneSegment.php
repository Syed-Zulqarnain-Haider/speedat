<?php

declare(strict_types=1);

namespace Speedat\View;

/**
 * One run of an owner-typed line: either plain text, or a phone number with
 * the `tel:` href that goes with it. Port of `PhoneSegment` in
 * src/components/site/pages/phones.ts.
 */
final class PhoneSegment
{
    public function __construct(
        public readonly string $text,
        /** "tel:+923337667076", or "" when this run is plain text. */
        public readonly string $tel = '',
    ) {
    }

    public function isPhone(): bool
    {
        return $this->tel !== '';
    }
}
