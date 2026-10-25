<?php

declare(strict_types=1);

namespace Speedat\Domain\Pricing;

/**
 * An optional charge the customer can tick. Port of `Addon` in types.ts.
 *
 * The amount always comes from the published settings, never from the browser:
 * `QuoteText::resolveAddons()` takes the labels a booking named and looks each
 * one up again here.
 */
final class Addon
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly float $amount,
        /** Ticked by default. */
        public readonly bool $on,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'label' => $this->label, 'amount' => $this->amount, 'on' => $this->on];
    }
}
