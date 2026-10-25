<?php

declare(strict_types=1);

namespace Speedat\Domain\Pricing;

/** A service the company sells (Express, Normal). Port of `Service` in types.ts. */
final class Service
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        /** One-line description shown under the service name before a price exists. */
        public readonly string $note = '',
    ) {
    }

    /** @param array<string,mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            id: (string) ($a['id'] ?? ''),
            name: (string) ($a['name'] ?? ''),
            note: (string) ($a['note'] ?? ''),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'note' => $this->note];
    }
}
