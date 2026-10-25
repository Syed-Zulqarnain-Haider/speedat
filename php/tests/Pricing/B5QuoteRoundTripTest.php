<?php

declare(strict_types=1);

namespace Speedat\Tests\Pricing;

use Speedat\Domain\Pricing\Engine;
use Speedat\Domain\Pricing\Piece;
use Speedat\Domain\Pricing\QuoteText;
use Speedat\Domain\Pricing\RateCard;
use Speedat\Domain\Pricing\Settings;
use Speedat\Domain\Quotes\QuoteInput;
use Speedat\Tests\TestCase;

/**
 * The two places where the browser's number and the stored number came apart
 * BETWEEN the engines rather than inside one (2026-10-25).
 *
 * The golden vectors hold each engine to the same answers, but they cannot see
 * QuoteInput, which sits between the browser and QuoteText on the server. A
 * label the browser posted raw was squished there and then compared exactly,
 * so the stored total lost an add-on the customer's WhatsApp total kept; and
 * with no cargo threshold the browser priced a shipment QuoteInput would not
 * log. These tests go through QuoteInput the way a POST does. No database.
 */
final class B5QuoteRoundTripTest extends TestCase
{
    private const ID = 'SP-260920-K7QX';

    /**
     * The scenario as found: "Pickup  and packing" (two spaces, as the owner
     * typed it) posted raw by the browser. It must come back with the
     * document's own label and amount, so the logged total includes PKR 500.
     */
    public function testAPostedLabelWithADoubleSpaceStillResolves(): void
    {
        $settings = new Settings(addons: "Pickup  and packing | 500 | on\nInsurance\t| 250 | off");

        $input = QuoteInput::fromArray([
            'id'        => self::ID,
            'destId'    => 'gb',
            'serviceId' => 'express',
            'pieces'    => [['kg' => 2, 'qty' => 1]],
            'addons'    => ['Pickup  and packing', 'Insurance'],
        ]);

        $addons = QuoteText::resolveAddons($settings, $input->addonLabels);

        $this->assertCount(2, $addons, 'both ticked add-ons survive QuoteInput');
        $this->assertSame('Pickup  and packing', $addons[0]->label, 'the label is the document\'s, not the squished one');
        $this->assertSame(10750.0, QuoteText::bookedTotal(10000.0, $addons), 'the stored total is the customer\'s total');
    }

    /** An 81-character label is cut to 80 by QuoteInput, and must still match its line. */
    public function testALabelLongerThanTheCapStillResolves(): void
    {
        $label    = str_repeat('Fragile goods cover ', 5); // 100 characters with the trailing space
        $settings = new Settings(addons: $label . '| 900 | off');

        $input = QuoteInput::fromArray([
            'id'        => self::ID,
            'destId'    => 'gb',
            'serviceId' => 'express',
            'pieces'    => [['kg' => 1, 'qty' => 1]],
            'addons'    => [$label],
        ]);

        $this->assertSame(QuoteText::ADDON_LABEL_MAX, mb_strlen($input->addonLabels[0]), 'QuoteInput cuts to the shared cap');
        $this->assertCount(1, QuoteText::resolveAddons($settings, $input->addonLabels));
    }

    /**
     * maxKg 0 is "no limit" to the owner, but QuoteInput refuses to log over a
     * tonne. The engine now answers "cargo" above the same tonne, so the
     * browser cannot show a Book link for a quote that will not be stored.
     */
    public function testNoCargoThresholdStopsAtTheTonneTheLogStopsAt(): void
    {
        $this->assertSame(QuoteInput::MAX_GRAMS, Engine::NO_LIMIT_MAX_G);

        $card = RateCard::fromArray([
            'services'     => [['id' => 'express', 'name' => 'Express']],
            'destinations' => [[
                'id'     => 'gb',
                'name'   => 'United Kingdom',
                'active' => true,
                'rates'  => ['express' => ['first' => 4000, 'addl' => 1000, 'days' => '3-5']],
            ]],
            'settings'     => ['pricingMode' => 'slab', 'firstKg' => 0.5, 'stepKg' => 0.5, 'maxKg' => 0, 'roundTo' => 10],
        ]);

        $heavy = Engine::priceAll($card, 'gb', [Piece::fromArray(['kg' => 400, 'qty' => 3])]);
        $this->assertFalse($heavy->ok);
        $this->assertSame('overmax', $heavy->reason);

        $tonne = Engine::priceAll($card, 'gb', [Piece::fromArray(['kg' => 1000, 'qty' => 1])]);
        $this->assertTrue($tonne->ok, 'exactly a tonne is still a parcel price, and still loggable');
        $this->assertSame(QuoteInput::MAX_GRAMS, $tonne->weights?->billableG);
    }

    /**
     * Overmax became reachable at maxKg 0, so whatever names the threshold
     * beside it must name the tonne, not 0 ("Over the cargo threshold (0 kg)"
     * was the admin panel's line for 3 x 400 kg). An owner's own threshold is
     * still printed as typed.
     */
    public function testTheThresholdNamedBesideOvermaxIsTheOneInEffect(): void
    {
        $this->assertSame(1000.0, Engine::cargoLimitKg(new Settings(maxKg: 0)));
        $this->assertSame((float) Engine::NO_LIMIT_MAX_G / 1000, Engine::cargoLimitKg(new Settings(maxKg: 0)));
        $this->assertSame(70.0, Engine::cargoLimitKg(new Settings(maxKg: 70)));
        $this->assertSame(32.5, Engine::cargoLimitKg(new Settings(maxKg: 32.5)));
    }
}
