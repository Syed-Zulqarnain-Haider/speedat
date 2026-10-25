<?php

declare(strict_types=1);

namespace Speedat\Tests\Shipments;

use Speedat\Domain\Shipments\ShipmentId;
use Speedat\Domain\Shipments\ShipmentStatus;
use Speedat\Tests\TestCase;

/**
 * The transition table, the labels, and the ids.
 *
 * The transition table is not a port - the Next.js app had none - so it is
 * tested harder than the parts that are: every allowed move is named, and so is
 * every refusal anyone is likely to reach by mis-clicking.
 */
final class ShipmentStatusTest extends TestCase
{
    public function testTheStatusesAndTheirLabelsAreTheOnesTheTypeScriptHas(): void
    {
        $this->assertSame(
            'booked,picked_up,in_transit,customs,out_for_delivery,delivered,exception',
            implode(',', ShipmentStatus::ALL),
        );

        $this->assertSame('Picked up', ShipmentStatus::label(ShipmentStatus::PICKED_UP));
        $this->assertSame('At customs', ShipmentStatus::label(ShipmentStatus::CUSTOMS));
        $this->assertSame('Needs attention', ShipmentStatus::label(ShipmentStatus::EXCEPTION));
    }

    public function testAnUnknownStatusReadsAsBooked(): void
    {
        $this->assertSame('out_for_delivery', ShipmentStatus::orBooked('out_for_delivery'));
        $this->assertSame('booked', ShipmentStatus::orBooked(''));
        $this->assertSame('booked', ShipmentStatus::orBooked('cancelled'));
        $this->assertFalse(ShipmentStatus::isValid('cancelled'));
    }

    public function testTheMovesThatAreAllowed(): void
    {
        $allowed = [
            // A parcel collected and handed to the airline on the same visit,
            // with nobody stopping to press Picked up in between.
            [ShipmentStatus::BOOKED, ShipmentStatus::PICKED_UP],
            [ShipmentStatus::BOOKED, ShipmentStatus::IN_TRANSIT],
            [ShipmentStatus::PICKED_UP, ShipmentStatus::IN_TRANSIT],
            [ShipmentStatus::IN_TRANSIT, ShipmentStatus::CUSTOMS],
            [ShipmentStatus::IN_TRANSIT, ShipmentStatus::OUT_FOR_DELIVERY],
            [ShipmentStatus::CUSTOMS, ShipmentStatus::OUT_FOR_DELIVERY],
            // Customs can put a parcel back on a plane.
            [ShipmentStatus::CUSTOMS, ShipmentStatus::IN_TRANSIT],
            [ShipmentStatus::OUT_FOR_DELIVERY, ShipmentStatus::DELIVERED],
            // A failed delivery goes back.
            [ShipmentStatus::OUT_FOR_DELIVERY, ShipmentStatus::CUSTOMS],
            // A problem can surface after delivery and must be recordable.
            [ShipmentStatus::DELIVERED, ShipmentStatus::EXCEPTION],
            // An exception is a pause, not a place in the chain.
            [ShipmentStatus::EXCEPTION, ShipmentStatus::IN_TRANSIT],
            [ShipmentStatus::EXCEPTION, ShipmentStatus::DELIVERED],
        ];

        foreach ($allowed as [$from, $to]) {
            $this->assertTrue(ShipmentStatus::canMove($from, $to), $from . ' -> ' . $to . ' should be allowed');
        }

        // Exception is reachable from everywhere.
        foreach (ShipmentStatus::ALL as $from) {
            $this->assertTrue(ShipmentStatus::canMove($from, ShipmentStatus::EXCEPTION), $from . ' -> exception');
        }

        // Re-asserting a status records a new note under the same headline.
        foreach (ShipmentStatus::ALL as $status) {
            $this->assertTrue(ShipmentStatus::canMove($status, $status), $status . ' -> itself');
        }
    }

    public function testTheMovesThatAreRefused(): void
    {
        $refused = [
            // The one that would send "Your shipment has been delivered."
            // about a box still on the shelf.
            [ShipmentStatus::BOOKED, ShipmentStatus::DELIVERED],
            [ShipmentStatus::BOOKED, ShipmentStatus::CUSTOMS],
            [ShipmentStatus::BOOKED, ShipmentStatus::OUT_FOR_DELIVERY],
            [ShipmentStatus::PICKED_UP, ShipmentStatus::DELIVERED],
            [ShipmentStatus::PICKED_UP, ShipmentStatus::CUSTOMS],
            [ShipmentStatus::IN_TRANSIT, ShipmentStatus::DELIVERED],
            [ShipmentStatus::IN_TRANSIT, ShipmentStatus::BOOKED],
            [ShipmentStatus::CUSTOMS, ShipmentStatus::DELIVERED],
            // Nothing goes back to booked or picked up once it has flown.
            [ShipmentStatus::DELIVERED, ShipmentStatus::IN_TRANSIT],
            [ShipmentStatus::DELIVERED, ShipmentStatus::BOOKED],
            [ShipmentStatus::OUT_FOR_DELIVERY, ShipmentStatus::PICKED_UP],
            [ShipmentStatus::EXCEPTION, ShipmentStatus::BOOKED],
        ];

        foreach ($refused as [$from, $to]) {
            $this->assertFalse(ShipmentStatus::canMove($from, $to), $from . ' -> ' . $to . ' should be refused');
        }

        $this->assertFalse(ShipmentStatus::canMove('cancelled', ShipmentStatus::DELIVERED));
        $this->assertFalse(ShipmentStatus::canMove(ShipmentStatus::BOOKED, 'cancelled'));
    }

    public function testNextFromIsInTheCanonicalOrderAndIncludesStayingPut(): void
    {
        $this->assertSame(
            'booked,picked_up,in_transit,exception',
            implode(',', ShipmentStatus::nextFrom(ShipmentStatus::BOOKED)),
        );
        $this->assertSame(
            'delivered,exception',
            implode(',', ShipmentStatus::nextFrom(ShipmentStatus::DELIVERED)),
        );
    }

    public function testOnlyDeliveredIsClosed(): void
    {
        foreach (ShipmentStatus::ALL as $status) {
            $this->assertSame($status !== ShipmentStatus::DELIVERED, ShipmentStatus::isOpen($status), $status);
        }
    }

    /* ----------------------------------------------------------------- ids */

    public function testAnIdIsSHAndEightCharactersOfTheReadableAlphabet(): void
    {
        $seen = [];

        for ($i = 0; $i < 200; $i++) {
            $id = ShipmentId::generate();

            $this->assertTrue(ShipmentId::isValid($id), 'generated an invalid id: ' . $id);
            $seen[$id] = true;
        }

        // Not a statistical test, just a smoke alarm: 200 draws from 30^8
        // colliding would mean the randomness is not random.
        $this->assertSame(200, count($seen));
    }

    public function testTheConfusableCharactersAreNotInTheAlphabet(): void
    {
        foreach (['0', 'O', '1', 'I', 'L', 'U'] as $bad) {
            $this->assertFalse(
                str_contains(ShipmentId::ALPHABET, $bad),
                $bad . ' is in the alphabet and comes back wrong over the phone',
            );
        }
    }

    public function testInvalidIdsAreRefused(): void
    {
        foreach (['', 'SH-', 'SH-ABCDEFG', 'SH-ABCDEFGHI', 'sh-abcdefgh', 'SH-0BCDEFGH', 'XX-ABCDEFGH'] as $bad) {
            $this->assertFalse(ShipmentId::isValid($bad), 'accepted "' . $bad . '"');
        }
    }

    public function testSomethingACustomerTypedIsNormalisedForLookupOnly(): void
    {
        $this->assertSame('SH-GJAVFXDR', ShipmentId::normalise(' sh-gjav fxdr '));
        $this->assertSame('SH-GJAVFXDR', ShipmentId::normalise('GJAVFXDR'));
        $this->assertSame('SH-GJAVFXDR', ShipmentId::normalise('sh gjavfxdr'));
        $this->assertNull(ShipmentId::normalise('GJAV'), 'too short to be an id');
        $this->assertNull(ShipmentId::normalise('GJAVFXD0'), '0 is not in the alphabet');
    }
}
