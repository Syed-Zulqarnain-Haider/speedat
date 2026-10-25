<?php

declare(strict_types=1);

namespace Speedat\Domain\Pricing;

/**
 * A country the site prices to. Port of `Destination` in types.ts.
 *
 * `active = false` keeps the rates but takes the destination off the site: it
 * is not listed and it is not priced, so a hidden destination and a missing
 * one are the same refusal to the customer.
 */
final class Destination
{
    /** @param array<string,Rate> $rates keyed by service id */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly bool $active = true,
        public readonly array $rates = [],
    ) {
    }

    /** @param array<string,mixed> $a */
    public static function fromArray(array $a): self
    {
        $rates = [];

        if (isset($a['rates']) && is_array($a['rates'])) {
            foreach ($a['rates'] as $serviceId => $rate) {
                if (is_array($rate)) {
                    $rates[(string) $serviceId] = Rate::fromArray($rate);
                }
            }
        }

        return new self(
            id: (string) ($a['id'] ?? ''),
            name: (string) ($a['name'] ?? ''),
            active: (bool) ($a['active'] ?? false),
            rates: $rates,
        );
    }

    public function rate(string $serviceId): ?Rate
    {
        return $this->rates[$serviceId] ?? null;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $rates = [];
        foreach ($this->rates as $serviceId => $rate) {
            $rates[$serviceId] = $rate->toArray();
        }

        return ['id' => $this->id, 'name' => $this->name, 'active' => $this->active, 'rates' => $rates];
    }
}
