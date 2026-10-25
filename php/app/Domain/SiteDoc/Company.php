<?php

declare(strict_types=1);

namespace Speedat\Domain\SiteDoc;

/**
 * Who the company is, as the published document carries it. Port of the
 * `Company` interface in src/lib/site/types.ts.
 *
 * The field ORDER matters and is not cosmetic: `diffSite` walks the group with
 * `Object.keys()`, so the review the owner reads before publishing lists the
 * changed fields in exactly this order. Keep it identical to the TypeScript.
 */
final class Company
{
    public function __construct(
        public readonly string $name = '',
        public readonly string $tagline = '',
        /** Country shipped from, shown as "From". */
        public readonly string $origin = '',
        /** Digits only with the country code, e.g. 923157667076. */
        public readonly string $whatsapp = '',
        public readonly string $phone = '',
        public readonly string $email = '',
        /** Shown under the prices, e.g. "Door-to-door - Pickup on request". */
        public readonly string $includes = '',
        /** Comma-separated pickup cities. */
        public readonly string $originCities = '',
        /** "Good to know" points, one per line. */
        public readonly string $notes = '',
    ) {
    }

    /** The keys, in the order the TypeScript object carries them. */
    public const FIELDS = [
        'name', 'tagline', 'origin', 'whatsapp', 'phone', 'email', 'includes', 'originCities', 'notes',
    ];

    /** @param array<string,mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            name: Text::of($a['name'] ?? ''),
            tagline: Text::of($a['tagline'] ?? ''),
            origin: Text::of($a['origin'] ?? ''),
            whatsapp: Text::of($a['whatsapp'] ?? ''),
            phone: Text::of($a['phone'] ?? ''),
            email: Text::of($a['email'] ?? ''),
            includes: Text::of($a['includes'] ?? ''),
            originCities: Text::of($a['originCities'] ?? ''),
            notes: Text::of($a['notes'] ?? ''),
        );
    }

    /** @return array<string,string> in FIELDS order */
    public function toArray(): array
    {
        return [
            'name'         => $this->name,
            'tagline'      => $this->tagline,
            'origin'       => $this->origin,
            'whatsapp'     => $this->whatsapp,
            'phone'        => $this->phone,
            'email'        => $this->email,
            'includes'     => $this->includes,
            'originCities' => $this->originCities,
            'notes'        => $this->notes,
        ];
    }
}
