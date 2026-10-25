<?php

declare(strict_types=1);

namespace Speedat\Domain\Pricing;

/**
 * The answer to "what does this shipment cost?". Port of the `PriceResult`
 * union in types.ts, which has four shapes:
 *
 *   { ok: false, reason: "destination" }             unknown or hidden country
 *   { ok: false, reason: "weight" }                  nothing weighed anything
 *   { ok: false, reason: "overmax", dest, weights }  above the cargo threshold
 *   { ok: true,  dest, weights, prices }             priced
 *
 * PHP has no union types for object shapes, so the fields that only some
 * shapes carry are nullable and the named constructors below are the only way
 * to build one. `overmax` keeps the destination and the weights because the
 * site uses them to write the cargo message to WhatsApp.
 *
 * The TypeScript's priced shape also carries `type` ("pkg" or "doc"). It is
 * gone: since 2026-09-27 (owner) there is one kind of shipment, priced by
 * weight, so there is nothing for a caller to branch on.
 */
final class PriceResult
{
    public const REASON_DESTINATION = 'destination';
    public const REASON_WEIGHT      = 'weight';
    public const REASON_OVERMAX     = 'overmax';

    /** @param array<string,ServicePrice|null> $prices keyed by service id */
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $reason,
        public readonly ?Destination $dest,
        public readonly ?Weights $weights,
        public readonly array $prices,
    ) {
    }

    public static function noDestination(): self
    {
        return new self(false, self::REASON_DESTINATION, null, null, []);
    }

    public static function noWeight(): self
    {
        return new self(false, self::REASON_WEIGHT, null, null, []);
    }

    public static function overMax(Destination $dest, Weights $weights): self
    {
        return new self(false, self::REASON_OVERMAX, $dest, $weights, []);
    }

    /** @param array<string,ServicePrice|null> $prices */
    public static function priced(Destination $dest, Weights $weights, array $prices): self
    {
        return new self(true, null, $dest, $weights, $prices);
    }

    public function price(string $serviceId): ?ServicePrice
    {
        return $this->prices[$serviceId] ?? null;
    }
}
