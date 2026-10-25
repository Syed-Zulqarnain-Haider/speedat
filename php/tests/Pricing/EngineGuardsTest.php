<?php

declare(strict_types=1);

namespace Speedat\Tests\Pricing;

use Speedat\Core\Money;
use Speedat\Domain\Pricing\Engine;
use Speedat\Domain\Pricing\Piece;
use Speedat\Domain\Pricing\PriceResult;
use Speedat\Domain\Pricing\RateCard;
use Speedat\Domain\Pricing\Settings;
use Speedat\Tests\TestCase;

/**
 * The two ways a number that is not a number used to get into a price.
 *
 * Findings 5 and 6 of docs/audit-round1.md. Neither is about a valid case - the
 * 940 golden vectors own those and none of them moves - both are about what the
 * engine does when it is handed something a form can produce and a validator
 * did not catch:
 *
 *   5. a volumetric divisor of 0 (an admin clearing the box) was a
 *      DivisionByZeroError, i.e. a 500 for every visitor who typed dimensions;
 *   6. a weight of INF (`{"kg": "1e999"}`, nine characters of JSON) contributed
 *      ZERO grams, because `(int) INF` is 0 in PHP, and left the shipment at the
 *      first slab - the CHEAPEST price in the card for an arbitrarily heavy
 *      parcel.
 *
 * Every test here fails without its fix: the divisor ones with
 * DivisionByZeroError, the weight ones with a price where there should be none.
 */
final class EngineGuardsTest extends TestCase
{
    /**
     * Plain slab pricing with round numbers, so every expectation below can be
     * checked by hand:
     *
     *   first 0.5 kg   PKR 1,000      each +0.5 kg   PKR 500
     *   no tax, rounding to 10, cargo threshold 70 kg, divisor 5000
     */
    private static function card(float|string $volumetricDivisor = 5000): RateCard
    {
        return RateCard::fromArray([
            'services'     => [['id' => 'express', 'name' => 'Express']],
            'destinations' => [[
                'id'     => 'ca',
                'name'   => 'Canada',
                'active' => true,
                'rates'  => ['express' => ['first' => 1000, 'addl' => 500, 'doc' => 800, 'days' => '3-5']],
            ]],
            'settings'     => [
                'pricingMode'       => 'slab',
                'currency'          => 'PKR',
                'volumetricDivisor' => $volumetricDivisor,
                'firstKg'           => 0.5,
                'stepKg'            => 0.5,
                'docMaxKg'          => 0.5,
                'taxPct'            => 0,
                'roundTo'           => 10,
                'maxKg'             => 70,
            ],
        ]);
    }

    /* ===================================================================== */
    /*  FINDING 5 - the divisor an admin can clear                           */
    /* ===================================================================== */

    /**
     * A 30x20x10 cm box weighing 1 kg, priced with each divisor the settings
     * form can produce.
     *
     * Volumetric at 5000: 6000 / 5000 = 1.2 kg, which beats the 1 kg actual
     * weight, so the box bills at 1.5 kg (the next 0.5 kg step). The first slab
     * covers 0.5 kg and the remaining kilogram is two 0.5 kg steps:
     * 1,000 + 2 x 500 = 2,000. Every unusable divisor gives that same answer,
     * because an unusable divisor is treated as unset and an unset divisor is
     * 5000.
     */
    public function testAnUnusableVolumetricDivisorPricesAtTheDefaultInsteadOfCrashing(): void
    {
        $sane = Engine::priceAll(self::card(5000), 'ca', [Piece::fromArray(['kg' => 1, 'L' => 30, 'W' => 20, 'H' => 10])]);

        $this->assertTrue($sane->ok, 'the control case must price');
        $this->assertSame(1500, $sane->weights?->billableG, 'volumetric 1.2 kg rounded up to 1.5 kg');
        $this->assertSame(2000.0, $sane->price('express')?->total, '1,000 + two 500 steps');

        // 0 and "" are the same value to the engine: Num::jsNumber() ports
        // JavaScript's Number(), and Number("") is 0. "abc" is NAN. A negative
        // divisor would make the volumetric weight negative, which max() throws
        // away without a word.
        foreach ([0, '', '0', 'abc', -5000, '1e999'] as $typed) {
            $result = Engine::priceAll(
                self::card($typed),
                'ca',
                [Piece::fromArray(['kg' => 1, 'L' => 30, 'W' => 20, 'H' => 10])],
            );

            $where = 'volumetricDivisor = ' . var_export($typed, true);

            $this->assertTrue($result->ok, $where . ' must still produce a price');
            $this->assertSame(1500, $result->weights?->billableG, $where);
            $this->assertSame(2000.0, $result->price('express')?->total, $where);
        }
    }

    /**
     * The fallback is in the arithmetic only. The admin's own value stays on the
     * object, because Domain\SiteDoc\Validate reads it ("Volumetric divisor must
     * be between 1000 and 10000") to refuse publishing the document, and
     * View\Admin\SettingsFields prints it back into the form.
     * Clamping it on the way in would have made a broken document publishable
     * and shown the admin a number they never typed.
     */
    public function testTheAdminsOwnValueIsKeptForTheValidatorAndTheForm(): void
    {
        $cleared = Settings::fromArray(['volumetricDivisor' => '']);

        $this->assertSame(0.0, $cleared->volumetricDivisor, 'Number("") is 0, and the document says 0');
        $this->assertFalse(
            $cleared->volumetricDivisor >= 1000 && $cleared->volumetricDivisor <= 10000,
            'the publish guard must still see a value it refuses',
        );
        $this->assertSame(5000.0, $cleared->effectiveDivisor(), 'the maths uses the default');

        $typo = Settings::fromArray(['volumetricDivisor' => 'abc']);
        $this->assertTrue(is_nan($typo->volumetricDivisor), 'a typo is NAN, not silently a number');
        $this->assertSame(5000.0, $typo->effectiveDivisor());

        // A divisor that IS usable is used, whatever its size - this is a
        // fallback for the unusable, not a clamp to a preferred range.
        $this->assertSame(10.0, Settings::fromArray(['volumetricDivisor' => 10])->effectiveDivisor());
        $this->assertSame(5000.0, (new Settings())->effectiveDivisor(), 'the declared default');
    }

    /** A divisor of 0 with no dimensions never divided, and still must not. */
    public function testWithoutDimensionsTheDivisorIsNeverTouched(): void
    {
        $result = Engine::priceAll(self::card(0), 'ca', [Piece::fromArray(['kg' => 1])]);

        $this->assertTrue($result->ok);
        $this->assertSame(1000, $result->weights?->billableG, '1 kg actual, no volumetric weight');
        $this->assertSame(0, $result->weights?->volG);
    }

    /* ===================================================================== */
    /*  FINDING 6 - the weight that is not a number                          */
    /* ===================================================================== */

    /**
     * `(int) INF` is 0 in PHP and `Money::kgToG()` hands INF straight through,
     * so this is the arithmetic the fix exists to keep away from a price. Stated
     * as a test rather than a comment because it is the surprising half: nothing
     * in `kgToG(INF)` looks like "the cheapest box in the card".
     */
    public function testTheArithmeticThisGuardsAgainst(): void
    {
        $this->assertSame(0, Money::kgToG(INF), 'kgToG(INF) is 0 grams, not an error');
        $this->assertSame(0, Money::kgToG(NAN));
    }

    /** A weight that reads as INF is not a weight, and is not stored as one. */
    public function testANonFiniteWeightBecomesNotANumberAtTheBoundary(): void
    {
        foreach (['1e999', '-1e999', '1e400'] as $typed) {
            $piece = Piece::fromArray(['kg' => $typed]);

            $this->assertTrue(is_nan($piece->kg), 'kg = ' . $typed . ' must read as NAN, never INF');
        }

        // The finite neighbours are untouched: this is about finiteness, not
        // size, and the caps on size live in Domain\Quotes\QuoteInput.
        $this->assertSame(9.0e307, Piece::fromArray(['kg' => '9e307'])->kg);
        $this->assertSame(0.5, Piece::fromArray(['kg' => '0.5'])->kg);
        $this->assertSame(0.0, Piece::fromArray(['kg' => null])->kg, 'Number(null) is 0');
        $this->assertTrue(is_nan(Piece::fromArray([])->kg), 'an absent field is undefined, i.e. NAN');
    }

    /**
     * The money bug itself: a shipment whose only row weighs INF must get NO
     * price, not the first slab.
     *
     * Before the fix `priceAll()` filtered on `kg > 0`, which INF passes, so the
     * shipment counted as weighed; `computeWeights()` then skipped the row and
     * billed the first slab minimum. The customer would be quoted PKR 1,000 -
     * the cheapest number in the card - for a parcel of unbounded weight, and
     * the quote log would say so.
     */
    public function testAnInfiniteWeightIsRefusedInsteadOfPricedAtTheCheapestSlab(): void
    {
        foreach ([Piece::fromArray(['kg' => '1e999']), new Piece(kg: INF), new Piece(kg: NAN)] as $piece) {
            $result = Engine::priceAll(self::card(), 'ca', [$piece]);

            $this->assertFalse($result->ok, 'an unreadable weight must not produce a price');
            $this->assertSame(PriceResult::REASON_WEIGHT, $result->reason, 'the customer is asked for a weight');
            $this->assertNull($result->price('express'));
        }
    }

    /**
     * A forged row beside a real one is ignored, and the real one still prices
     * exactly as it would alone: 2 kg bills at 2 kg, three 0.5 kg steps past the
     * first slab, 1,000 + 3 x 500 = 2,500.
     */
    public function testAGoodRowBesideAnUnreadableOneIsPricedOnItsOwn(): void
    {
        $rows = [
            Piece::fromArray(['kg' => '1e999', 'qty' => 5]),
            Piece::fromArray(['kg' => 2, 'qty' => 1]),
        ];

        $result = Engine::priceAll(self::card(), 'ca', $rows);

        $this->assertTrue($result->ok);
        $this->assertSame(1, $result->weights?->pieces, 'the unreadable row contributes no pieces');
        $this->assertSame(2000, $result->weights?->actualG);
        $this->assertSame(2000, $result->weights?->billableG);
        $this->assertSame(2500.0, $result->price('express')?->total);
        $this->assertCount(1, $result->weights?->lines ?? [], 'and no line to explain to the customer');
    }

    /** An infinite dimension is no dimension: it cannot quietly become 0 cm3. */
    public function testANonFiniteDimensionLeavesTheVolumetricWeightOut(): void
    {
        $result = Engine::priceAll(
            self::card(),
            'ca',
            [Piece::fromArray(['kg' => 1, 'L' => '1e999', 'W' => 20, 'H' => 10])],
        );

        $this->assertTrue($result->ok, 'the weight is still readable, so there is still a price');
        $this->assertSame(0, $result->weights?->volG, 'no volumetric weight, because there are no usable dimensions');
        $this->assertSame(1000, $result->weights?->billableG, 'billed on the 1 kg actual weight');
        $this->assertFalse($result->weights?->volumetricWins);
    }

    /**
     * computeWeights() on its own still answers the first slab for a shipment it
     * skipped every row of - that is what `max(firstG, ...)` means and the
     * vectors pin it. Written down so nobody "fixes" it there: the refusal
     * belongs to priceAll() and to Domain\Quotes\QuoteService, which both check
     * whether anything was weighed at all.
     */
    public function testWhyTheRefusalIsNotInComputeWeights(): void
    {
        $weights = Engine::computeWeights(self::card()->settings, [new Piece(kg: INF)]);

        $this->assertSame(0, $weights->chargeG, 'nothing chargeable');
        $this->assertSame(500, $weights->billableG, 'the first slab, which is why callers check chargeG');
        $this->assertCount(0, $weights->lines);
    }
}
