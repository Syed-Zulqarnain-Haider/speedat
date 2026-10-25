<?php

declare(strict_types=1);

namespace Speedat\Tests\Pricing;

use Speedat\Domain\Pricing\Engine;
use Speedat\Domain\Pricing\Piece;
use Speedat\Domain\Pricing\RateCard;
use Speedat\Domain\Pricing\Settings;
use Speedat\Tests\TestCase;

/**
 * A WEIGHT WITH NO PRICE IS NOT OFFERED - owner, 2026-09-28: "if I don't want
 * to add a price for some weight it should say rate not available, contact on
 * WhatsApp".
 *
 * Until this change a blank grid box fell upward to the next priced kilogram,
 * so an owner who left 3 kg empty was quietly charging the 5 kg price for a
 * 3 kg parcel - a number they had never typed, printed as if they had, and no
 * way to stop offering a weight except to price it anyway. Every test here
 * fails against that engine: each one used to get a price where it now gets
 * none.
 *
 * The other half of the guarantee is that NO OTHER PRICE MOVED, so the priced
 * kilograms either side of a gap are asserted to the rupee in the same tests.
 * The browser engine is held to the same answers by the golden vectors
 * (`tests/vectors/pricing.json`, re-derived for this decision - see
 * docs/engine-parity.md, "Skippable weight prices").
 */
final class SkippableWeightsTest extends TestCase
{
    /**
     * Grid pricing with a deliberate hole. Express prices 1, 2 and 5 kg and
     * leaves 3 and 4 blank; Normal prices 1, 2, 3 and 5. So 3 kg is the weight
     * one service has and the other does not, and 4 kg is the weight NEITHER
     * has - the two cases the owner described.
     *
     *   kg      1       2       3       5
     *   Express 4,000   6,000   -       9,000
     *   Normal  3,000   5,000   5,500   8,000
     *
     * No tax, no rounding, so every expectation below is the box itself.
     */
    private static function card(): RateCard
    {
        return RateCard::fromArray([
            'services'     => [
                ['id' => 'express', 'name' => 'Express'],
                ['id' => 'normal', 'name' => 'Normal'],
            ],
            'destinations' => [[
                'id'     => 'gb',
                'name'   => 'United Kingdom',
                'active' => true,
                'rates'  => [
                    'express' => ['grid' => ['1' => 4000, '2' => 6000, '5' => 9000], 'days' => '3-5'],
                    'normal'  => ['grid' => ['1' => 3000, '2' => 5000, '3' => 5500, '5' => 8000], 'days' => '6-9'],
                ],
            ]],
            'settings'     => [
                'pricingMode' => 'grid',
                'currency'    => 'PKR',
                'taxPct'      => 0,
                'roundTo'     => 0,
                'maxKg'       => 25,
            ],
        ]);
    }

    /** @param list<array<string,mixed>> $rows */
    private function priced(array $rows): \Speedat\Domain\Pricing\PriceResult
    {
        return Engine::priceAll(self::card(), 'gb', array_map(Piece::fromArray(...), $rows));
    }

    /* ===================================================================== */
    /*  gridPrice - the lookup itself                                        */
    /* ===================================================================== */

    /**
     * THE FALLBACK IS GONE. The old engine walked upward from ceil(kg) and took
     * the first box holding a number; 3 kg therefore answered with the 5 kg
     * price. It now answers with nothing.
     */
    public function testABlankBoxIsNotAPriceAndDoesNotFallToTheNextOneUp(): void
    {
        $grid = ['1' => 100.0, '2' => 200.0, '5' => 500.0];

        $this->assertNull(Engine::gridPrice($grid, 3.0, 25.0), '3 kg is blank: no price, not the 5 kg one');
        $this->assertNull(Engine::gridPrice($grid, 4.0, 25.0), '4 kg is blank: no price');

        // ... and the boxes that ARE priced still answer with their own number.
        $this->assertSame(['kg' => 1, 'price' => 100.0], Engine::gridPrice($grid, 1.0, 25.0));
        $this->assertSame(['kg' => 2, 'price' => 200.0], Engine::gridPrice($grid, 2.0, 25.0));
        $this->assertSame(['kg' => 5, 'price' => 500.0], Engine::gridPrice($grid, 5.0, 25.0));
    }

    /**
     * THE WHOLE-KILOGRAM ROUNDING IS NOT THE FALLBACK AND DID NOT GO. Grid mode
     * bills whole kilograms, so 0.4 kg and 1 kg both read box 1 and 1.2 kg
     * reads box 2. That happens BEFORE a box is read; deleting it would move
     * every fractional price in the card.
     */
    public function testAPartKilogramStillReadsTheWholeKilogramBoxAboveIt(): void
    {
        $grid = ['1' => 100.0, '2' => 200.0, '5' => 500.0];

        $this->assertSame(['kg' => 1, 'price' => 100.0], Engine::gridPrice($grid, 0.4, 25.0), '0.4 kg bills as 1 kg');
        $this->assertSame(['kg' => 1, 'price' => 100.0], Engine::gridPrice($grid, 0.0, 25.0), 'a zero weight bills as 1 kg');
        $this->assertSame(['kg' => 2, 'price' => 200.0], Engine::gridPrice($grid, 1.2, 25.0), '1.2 kg bills as 2 kg, which IS priced');

        // But 2.1 kg bills as 3 kg, and 3 kg is blank - so the rounding lands
        // on a weight that is not offered rather than sliding past it to 5.
        $this->assertNull(Engine::gridPrice($grid, 2.1, 25.0), '2.1 kg bills as 3 kg, which is blank');
    }

    /** Zero is not a price - the site never prints "PKR 0", so a 0 box reads as blank. */
    public function testAZeroBoxIsBlankAndDoesNotFallUpwardEither(): void
    {
        $this->assertNull(Engine::gridPrice(['1' => 0.0, '2' => 700.0], 1.0, 25.0), '0 at 1 kg is not a price');
        $this->assertSame(['kg' => 2, 'price' => 700.0], Engine::gridPrice(['1' => 0.0, '2' => 700.0], 2.0, 25.0));
    }

    /** Above the cargo threshold there is no box to read - a different reason, the same null. */
    public function testAboveTheThresholdThereIsStillNoPrice(): void
    {
        $this->assertNull(Engine::gridPrice(['1' => 100.0, '24' => 2000.0], 25.0, 25.0), '25 kg is blank');
        $this->assertNull(Engine::gridPrice(['1' => 100.0, '26' => 2000.0], 26.0, 25.0), '26 kg is past the threshold');
    }

    /* ===================================================================== */
    /*  priceService and priceAll - what the site is handed                  */
    /* ===================================================================== */

    /** One service has the weight and the other does not: show the one that does. */
    public function testAServiceWithNoPriceAtThatWeightIsSimplyNotOffered(): void
    {
        $res = $this->priced([['kg' => 3]]);

        $this->assertTrue($res->ok, 'the country is served, so this is a priced answer with a hole in it');
        $this->assertNull($res->price('express'), 'Express has no 3 kg box, so Express is not offered at 3 kg');

        $normal = $res->price('normal');
        $this->assertNotNull($normal, 'Normal prices 3 kg and must still be offered');
        // The rupee, not just "a price": this is the half that proves nothing moved.
        $this->assertSame(5500.0, $normal?->total, 'Normal 3 kg is its own box');
        $this->assertSame(3, $normal?->gridKg, 'and it came from the 3 kg box, not a heavier one');
    }

    /** No service has the weight: nothing is offered at all, which is the WhatsApp case. */
    public function testAWeightNoServicePricesIsOfferedByNobody(): void
    {
        $res = $this->priced([['kg' => 4]]);

        $this->assertTrue($res->ok, 'the country is served; 4 kg simply has no price');
        $this->assertNull($res->price('express'), 'Express has no 4 kg box');
        $this->assertNull($res->price('normal'), 'Normal has no 4 kg box either');

        // Before this change BOTH of these answered with the 5 kg price, so the
        // site showed a 4 kg parcel two prices the owner never typed.
        $offered = array_filter($res->prices, static fn (?object $p): bool => $p !== null);
        $this->assertSame([], $offered, 'a weight with no price in any service offers nothing');
    }

    /** The weights either side of the hole are untouched, to the rupee. */
    public function testTheWeightsEitherSideOfTheHoleAreUnmoved(): void
    {
        foreach ([
            2 => ['express' => 6000.0, 'normal' => 5000.0],
            5 => ['express' => 9000.0, 'normal' => 8000.0],
            1 => ['express' => 4000.0, 'normal' => 3000.0],
        ] as $kg => $expected) {
            $res = $this->priced([['kg' => $kg]]);

            foreach ($expected as $serviceId => $total) {
                $price = $res->price($serviceId);
                $this->assertNotNull($price, $kg . ' kg ' . $serviceId . ' is priced');
                $this->assertSame($total, $price?->total, $kg . ' kg ' . $serviceId);
                $this->assertSame($kg, $price?->gridKg, $kg . ' kg ' . $serviceId . ' came from its own box');
            }
        }
    }

    /**
     * SLAB MODE IS NOT TOUCHED. There are no boxes to leave blank there - a
     * slab card prices every weight from `first` and `addl` - so the decision
     * cannot reach it, and this test is the proof rather than the assumption.
     */
    public function testSlabPricingStillPricesEveryWeight(): void
    {
        $card = RateCard::fromArray([
            'services'     => [['id' => 'express', 'name' => 'Express']],
            'destinations' => [[
                'id'     => 'gb',
                'name'   => 'United Kingdom',
                'active' => true,
                'rates'  => ['express' => ['first' => 1000, 'addl' => 500, 'days' => '3-5']],
            ]],
            'settings'     => [
                'pricingMode' => 'slab',
                'currency'    => 'PKR',
                'firstKg'     => 0.5,
                'stepKg'      => 0.5,
                'taxPct'      => 0,
                'roundTo'     => 0,
                'maxKg'       => 25,
            ],
        ]);

        foreach ([1 => 1500.0, 3 => 3500.0, 4 => 4500.0] as $kg => $total) {
            $res   = Engine::priceAll($card, 'gb', [Piece::fromArray(['kg' => $kg])]);
            $price = $res->price('express');

            $this->assertNotNull($price, $kg . ' kg is priced in slab mode');
            $this->assertSame($total, $price?->total, $kg . ' kg slab total');
            $this->assertNull($price?->gridKg, 'a slab answer names no box');
        }
    }

    /** `gridWeights()` still lists every whole kilogram - the admin needs a box to leave blank. */
    public function testTheAdminStillGetsABoxForEveryKilogram(): void
    {
        $this->assertSame(range(1, 25), Engine::gridWeights(new Settings(pricingMode: 'grid', maxKg: 25)));
    }
}
