<?php

declare(strict_types=1);

namespace Speedat\Tests\Pricing;

use Speedat\Domain\Pricing\Engine;
use Speedat\Domain\Pricing\Quote;
use Speedat\Domain\Pricing\QuoteText;
use Speedat\Domain\Pricing\Settings;
use Speedat\Tests\TestCase;

/**
 * A CHARGE THE CUSTOMER IS TOLD ABOUT AND NEVER CHARGED INSIDE A PRICE - owner,
 * 2026-10-26: "the rates shown everywhere should only show the actual rate,
 * not the pickup charges included. At the bottom just add a drill down and
 * there show 1000 pickup charges". Owner: rates show the courier rate only;
 * the pickup charge is shown under the quote, never added.
 *
 * The add-on line "Pickup | 1000 | separate" (or "info") is the switch. These
 * tests pin the engine half: the line is NOT an add-on (so nothing that sums or
 * offers add-ons can reach it), it IS a separate charge, and the WhatsApp
 * message names it after the price without adding it. "on" and "off" are
 * asserted beside it, unchanged, so the third state cannot quietly move the
 * first two. The browser engine is held to the same answers by
 * tests/vectors/owner-decisions.json (groups addonsList, separateCharges,
 * chargeName, quoteText).
 */
final class SeparateChargesTest extends TestCase
{
    private const LINES = "Delivery | 1000 | on\nPickup | 1000 | separate\nInsurance | 250 | off\nCustoms | 500 | info";

    public function testASeparateLineIsNotAnAddOnAndOnAndOffAreUnchanged(): void
    {
        $addons = Engine::addonsList(new Settings(addons: self::LINES));

        $this->assertSame(
            [
                ['id' => 'a0', 'label' => 'Delivery', 'amount' => 1000.0, 'on' => true],
                ['id' => 'a2', 'label' => 'Insurance', 'amount' => 250.0, 'on' => false],
            ],
            array_map(static fn ($a): array => $a->toArray(), $addons),
            'separate and info lines must leave the add-on list, keeping the other ids where they were',
        );
    }

    public function testSeparateAndInfoAreTheSeparateCharges(): void
    {
        $charges = Engine::separateCharges(new Settings(addons: self::LINES));

        $this->assertSame(
            [
                ['id' => 'a1', 'label' => 'Pickup', 'amount' => 1000.0],
                ['id' => 'a3', 'label' => 'Customs', 'amount' => 500.0],
            ],
            array_map(static fn ($c): array => $c->toArray(), $charges),
        );
    }

    public function testTheKeywordIsCaseBlindAndTrimmedLikeEveryOtherColumn(): void
    {
        $this->assertTrue(Engine::isSeparate('separate'));
        $this->assertTrue(Engine::isSeparate('SEPARATE'));
        $this->assertTrue(Engine::isSeparate('Info'));
        $this->assertFalse(Engine::isSeparate('on'));
        $this->assertFalse(Engine::isSeparate('off'));
        $this->assertFalse(Engine::isSeparate(''));
        $this->assertFalse(Engine::isSeparate('separately'));

        // A trailing no-break space, as a spreadsheet paste leaves it: the same
        // jsTrim the "on" column has been read with since 2026-10-25.
        $charges = Engine::separateCharges(new Settings(addons: "Pickup | 1000 | separate\u{00A0}"));
        $this->assertCount(1, $charges);
        $this->assertSame([], Engine::addonsList(new Settings(addons: "Pickup | 1000 | separate\u{00A0}")));
    }

    public function testAZeroOrBrokenSeparateLineIsNothingToTell(): void
    {
        // The site never prints "PKR 0", and a line with no label or no number
        // is the broken line validateSite already refuses to publish.
        $this->assertSame([], Engine::separateCharges(new Settings(addons: "Pickup | 0 | separate\n | 500 | separate\nPacking | n/a | info")));
    }

    public function testAMisspeltKeywordStaysWhatAnUnknownWordAlwaysWasAnOffAddOn(): void
    {
        $settings = new Settings(addons: 'Pickup | 1000 | seperate');

        $this->assertSame([], Engine::separateCharges($settings));
        $this->assertSame(
            [['id' => 'a0', 'label' => 'Pickup', 'amount' => 1000.0, 'on' => false]],
            array_map(static fn ($a): array => $a->toArray(), Engine::addonsList($settings)),
        );
    }

    /**
     * The stored total and the inbox lead come from resolveAddons(): a browser
     * that posted a separate charge's label (a stale page, a hand-made POST)
     * must not get it added on the server.
     */
    public function testABookingCannotResolveASeparateChargeIntoItsTotal(): void
    {
        $settings = new Settings(addons: self::LINES);
        $resolved = QuoteText::resolveAddons($settings, ['Pickup', 'Delivery', 'Customs']);

        $this->assertSame(['Delivery'], array_map(static fn ($a): string => $a->label, $resolved));
        $this->assertSame(18550.0, QuoteText::bookedTotal(17550.0, $resolved));
    }

    public function testTheChargeIsNamedTheWayTheCustomerReadsIt(): void
    {
        $this->assertSame('Pickup charge', QuoteText::chargeName('Pickup'));
        $this->assertSame('Pickup charge', QuoteText::chargeName('Pickup charge'));
        $this->assertSame('Customs charges', QuoteText::chargeName('Customs charges'));
        $this->assertSame('Handling fee', QuoteText::chargeName('Handling fee'));
    }

    public function testTheWhatsAppPriceIsTheRateAndThePickupIsItsOwnLine(): void
    {
        $settings = new Settings(addons: 'Pickup | 1000 | separate');
        $text     = QuoteText::quoteText(
            $this->quote(),
            'Speedat',
            'PKR',
            QuoteText::resolveAddons($settings, ['Pickup']),
            'clothes',
            Engine::separateCharges($settings),
        );

        $this->assertSame(
            "Hi Speedat, I want to book a shipment.\nQuote: SP-261026-TEST\nTo: Canada\nService: Express (4\u{2013}6 working days)\n"
            . "Packages: 1 \u{00d7} 5 kg\nCharged on: 5 kg\n"
            . "Price: PKR 17,550\nPickup charge (separate): PKR 1,000\nContents: clothes",
            $text,
        );
        $this->assertStringNotContains('18,550', $text, 'the pickup charge was added to the price');
        $this->assertStringNotContains("\u{2014}", $text);
    }

    public function testWithAnOnAddOnTheTotalLeavesTheSeparateChargeOut(): void
    {
        $settings = new Settings(addons: "Delivery | 1000 | on\nPickup | 1000 | separate");
        $text     = QuoteText::quoteText(
            $this->quote(),
            'Speedat',
            'PKR',
            QuoteText::resolveAddons($settings, ['Delivery']),
            null,
            Engine::separateCharges($settings),
        );

        $this->assertStringContains("Courier: PKR 17,550\nDelivery: PKR 1,000\nTotal: PKR 18,550\nPickup charge (separate): PKR 1,000", $text);
        $this->assertStringNotContains('19,550', $text);
    }

    public function testWithoutSeparateChargesTheMessageIsByteForByteWhatItWas(): void
    {
        $settings = new Settings(addons: 'Delivery | 1000 | on');
        $addons   = QuoteText::resolveAddons($settings, ['Delivery']);

        $this->assertSame(
            QuoteText::quoteText($this->quote(), 'Speedat', 'PKR', $addons, null),
            QuoteText::quoteText($this->quote(), 'Speedat', 'PKR', $addons, null, Engine::separateCharges($settings)),
        );
    }

    private function quote(): Quote
    {
        return Quote::fromArray([
            'id'         => 'SP-261026-TEST',
            'from'       => '',
            'dest'       => 'Canada',
            'destId'     => 'ca',
            'service'    => 'Express',
            'serviceId'  => 'express',
            'days'       => "4\u{2013}6",
            'eta'        => '',
            'pickup'     => '',
            'pieces'     => 1,
            'piecesText' => "1 \u{00d7} 5 kg",
            'billableG'  => 5000,
            'chargeG'    => 5000,
            'total'      => 17550,
            'version'    => 1,
        ]);
    }
}
