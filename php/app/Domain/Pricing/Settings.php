<?php

declare(strict_types=1);

namespace Speedat\Domain\Pricing;

/**
 * The pricing settings of a rate card. Port of the `Settings` interface in
 * src/lib/pricing/types.ts, field for field and name for name, so the same
 * JSON loads on both sides without a translation layer.
 *
 * Immutable: a price is computed from one snapshot of the settings and
 * nothing may change under it halfway through.
 *
 * ONE FIELD THE TYPESCRIPT HAS AND THIS DOES NOT: `docMaxKg`, the weight the
 * flat document price applied up to. The owner removed the document rate from
 * the product on 2026-09-27 - everything is priced by weight - so the field has
 * no meaning left. A stored `docMaxKg` is ignored here and dropped by
 * SiteDoc\Migrate; docs/engine-parity.md records the divergence.
 */
final class Settings
{
    public const MODE_SLAB = 'slab';
    public const MODE_GRID = 'grid';

    /**
     * The divisor a document gets when it does not state one - the seed's value
     * (Domain\SiteDoc\Seed) and the industry's. Named rather than repeated so
     * the constructor default and effectiveDivisor()'s fallback cannot drift.
     */
    public const DEFAULT_VOLUMETRIC_DIVISOR = 5000.0;

    public function __construct(
        /** "slab" = first slab + per-step price; "grid" = a price per whole kilogram. */
        public readonly string $pricingMode = self::MODE_SLAB,
        public readonly string $currency = 'PKR',
        /**
         * L x W x H in cm divided by this = volumetric kilograms.
         *
         * Kept exactly as the document spells it, including a 0 or a NAN:
         * Validate::errors() has to see the admin's own value to refuse
         * publishing it, and the settings form has to show it back. The
         * arithmetic reads effectiveDivisor() instead.
         */
        public readonly float $volumetricDivisor = self::DEFAULT_VOLUMETRIC_DIVISOR,
        public readonly float $firstKg = 0.5,
        public readonly float $stepKg = 0.5,
        public readonly float $taxPct = 0.0,
        public readonly float $roundTo = 10.0,
        /** Above this billable weight the site asks for a cargo rate; 0 = no limit. */
        public readonly float $maxKg = 70.0,
        public readonly bool $showEta = true,
        /** Same-day pickup cutoff, 0-23; null = none. */
        public readonly ?float $cutoffHour = 15.0,
        public readonly string $workingDays = 'Mon, Tue, Wed, Thu, Fri, Sat',
        public readonly string $holidays = '',
        /** Optional charges, one per line: `Label | Amount | on`. */
        public readonly string $addons = '',
        public readonly string $disclaimer = '',
    ) {
    }

    /** @param array<string,mixed> $a */
    public static function fromArray(array $a): self
    {
        $defaults = new self();

        $cutoff = array_key_exists('cutoffHour', $a) ? $a['cutoffHour'] : $defaults->cutoffHour;

        return new self(
            pricingMode: self::text($a['pricingMode'] ?? $defaults->pricingMode),
            currency: self::text($a['currency'] ?? $defaults->currency),
            volumetricDivisor: Num::jsNumber($a['volumetricDivisor'] ?? $defaults->volumetricDivisor),
            firstKg: Num::jsNumber($a['firstKg'] ?? $defaults->firstKg),
            stepKg: Num::jsNumber($a['stepKg'] ?? $defaults->stepKg),
            taxPct: Num::jsNumber($a['taxPct'] ?? $defaults->taxPct),
            roundTo: Num::jsNumber($a['roundTo'] ?? $defaults->roundTo),
            maxKg: Num::jsNumber($a['maxKg'] ?? $defaults->maxKg),
            showEta: (bool) ($a['showEta'] ?? $defaults->showEta),
            cutoffHour: $cutoff === null ? null : Num::jsNumber($cutoff),
            workingDays: self::text($a['workingDays'] ?? $defaults->workingDays),
            holidays: self::text($a['holidays'] ?? $defaults->holidays),
            addons: self::text($a['addons'] ?? $defaults->addons),
            disclaimer: self::text($a['disclaimer'] ?? $defaults->disclaimer),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'pricingMode'       => $this->pricingMode,
            'currency'          => $this->currency,
            'volumetricDivisor' => $this->volumetricDivisor,
            'firstKg'           => $this->firstKg,
            'stepKg'            => $this->stepKg,
            'taxPct'            => $this->taxPct,
            'roundTo'           => $this->roundTo,
            'maxKg'             => $this->maxKg,
            'showEta'           => $this->showEta,
            'cutoffHour'        => $this->cutoffHour,
            'workingDays'       => $this->workingDays,
            'holidays'          => $this->holidays,
            'addons'            => $this->addons,
            'disclaimer'        => $this->disclaimer,
        ];
    }

    public function isGrid(): bool
    {
        return $this->pricingMode === self::MODE_GRID;
    }

    /**
     * The divisor the volumetric maths may divide by.
     *
     * WHY THIS IS NOT JUST $this->volumetricDivisor. `Settings::fromArray()`
     * reads the field with `Num::jsNumber()`, a faithful port of JavaScript's
     * `Number()`, so an admin who clears the box gives us 0.0 (`Number("")` is
     * 0) and one who types "abc" gives us NAN. Neither is a divisor:
     *
     *   - PHP 8 raises DivisionByZeroError on the 0, which reached every
     *     visitor who entered dimensions as a 500 (audit round 1, finding 5);
     *   - the TypeScript divides anyway and gets Infinity volumetric grams,
     *     which travel into `max(actual, volumetric)` and make the whole
     *     shipment Infinity grams - measured, not assumed. So there is no
     *     TypeScript behaviour worth copying here, and docs/engine-parity.md
     *     records the divergence.
     *
     * A cleared box is "not set", and a setting that is not set is this class's
     * own default - the same 5000 an absent JSON key already gets in
     * fromArray(). A negative divisor is refused by the same test: it would
     * produce negative volumetric grams, which `max()` silently discards, and
     * "silently discards" is the shape of bug this method exists to end.
     *
     * None of this is reachable from a published document: Validate::errors()
     * refuses anything outside 1000-10000. It is the belt for a document that
     * arrived without being validated - a hand-edited row, an import, a restored
     * backup - where the choice is between a price and a blank page.
     */
    public function effectiveDivisor(): float
    {
        return is_finite($this->volumetricDivisor) && $this->volumetricDivisor > 0
            ? $this->volumetricDivisor
            : self::DEFAULT_VOLUMETRIC_DIVISOR;
    }

    private static function text(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }
}
