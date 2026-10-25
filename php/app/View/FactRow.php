<?php

declare(strict_types=1);

namespace Speedat\View;

/**
 * One row of the `dl.facts` list on /about and /contact: "Office", "Pickup",
 * "Hours", "Cutoff", "Call", "Also", "Email".
 *
 * The value can be plain text, a link, text plus a trailing "· Google Maps"
 * link (the office row), or a run of phone segments (the "Also" row). Those
 * four shapes are named here so the template is four short branches and no
 * decisions.
 */
final class FactRow
{
    public const PLAIN    = 'plain';
    public const LINK     = 'link';
    public const ADDRESS  = 'address';
    public const SEGMENTS = 'segments';

    /** @param list<PhoneSegment> $segments */
    private function __construct(
        public readonly string $kind,
        public readonly string $label,
        public readonly string $text,
        public readonly string $href = '',
        public readonly bool $external = false,
        /** The "· Google Maps" link on the office row; "" when there is no map URL. */
        public readonly string $mapHref = '',
        public readonly array $segments = [],
    ) {
    }

    public static function plain(string $label, string $text): self
    {
        return new self(self::PLAIN, $label, $text);
    }

    public static function link(string $label, string $text, string $href, bool $external = false): self
    {
        return new self(self::LINK, $label, $text, $href, $external);
    }

    public static function address(string $label, string $text, string $mapHref): self
    {
        return new self(self::ADDRESS, $label, $text, mapHref: $mapHref);
    }

    /** @param list<PhoneSegment> $segments */
    public static function segments(string $label, array $segments): self
    {
        return new self(self::SEGMENTS, $label, '', segments: $segments);
    }
}
