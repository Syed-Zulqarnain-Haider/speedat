<?php

declare(strict_types=1);

namespace Speedat\Tests\Shipments;

use Speedat\Core\Db;
use Speedat\Domain\Leads\LeadRepository;
use Speedat\Domain\Leads\LeadStatus;
use Speedat\Domain\Shipments\CustomerMessage;
use Speedat\Domain\Shipments\InvalidTransition;
use Speedat\Domain\Shipments\Shipment;
use Speedat\Domain\Shipments\ShipmentRepository;
use Speedat\Domain\Shipments\ShipmentStatus;
use Speedat\Tests\TestCase;

/**
 * Shipments against a real database: the transaction that creates one, the
 * transition rules as they are actually enforced, and the timeline the
 * customer's WhatsApp update is built from.
 */
final class ShipmentRepositoryTest extends TestCase
{
    private const ACTOR = 'owner@speedat.test';

    private ShipmentRepository $shipments;

    private LeadRepository $leads;

    /** @var list<string> */
    private array $createdShipments = [];

    /** @var list<int> */
    private array $createdLeads = [];

    public function setUp(): void
    {
        if (!Db::healthy()) {
            $this->skip('the test database is not reachable - start MariaDB (bin/local/start-db.ps1) and run bin/migrate.php --database=speedat_php_test');
        }

        $this->leads     = new LeadRepository();
        $this->shipments = new ShipmentRepository($this->leads);
    }

    public function tearDown(): void
    {
        if (!Db::healthy()) {
            return;
        }

        foreach ($this->createdShipments as $id) {
            Db::exec('DELETE FROM shipment_events WHERE shipment_id = ?', [$id]);
            Db::exec('DELETE FROM shipments WHERE id = ?', [$id]);
        }
        foreach ($this->createdLeads as $id) {
            Db::exec('DELETE FROM leads WHERE id = ?', [$id]);
        }

        $this->createdShipments = [];
        $this->createdLeads     = [];
    }

    /* ===================================================================== */
    /*  creating                                                             */
    /* ===================================================================== */

    public function testANewShipmentIsBookedAndAlreadyHasItsFirstEvent(): void
    {
        $shipment = $this->create();

        $this->assertSame(ShipmentStatus::BOOKED, $shipment->status);
        $this->assertSame('Booked', $shipment->statusLabel());
        $this->assertTrue($shipment->isOpen());
        $this->assertSame(self::ACTOR, $shipment->createdBy);

        $history = $this->shipments->history($shipment->id);
        $this->assertNotNull($history);
        $this->assertSame(1, count($history?->events ?? []), 'a shipment always has at least one event');
        $this->assertSame(ShipmentStatus::BOOKED, $history?->events[0]->status);
        $this->assertSame('', $history?->events[0]->note);
        $this->assertSame(self::ACTOR, $history?->events[0]->by);
    }

    public function testCreatingFromALeadMarksThatLeadBooked(): void
    {
        $lead                 = $this->leads->createMessageLead('Ayesha', '03001234567', '', 'Two boxes to Canada', 'ca', 4000, null);
        $this->createdLeads[] = $lead->id;

        $this->assertSame(LeadStatus::NEW, $lead->status);

        $shipment = $this->create(leadId: $lead->id);

        $this->assertSame($lead->id, $shipment->leadId);
        $this->assertSame(LeadStatus::BOOKED, $this->leads->find($lead->id)?->status);
        $this->assertSame(self::ACTOR, $this->leads->find($lead->id)?->lastTouchedBy());
    }

    public function testAnEmptyTrackingNumberIsStoredAsNothingRatherThanAnEmptyString(): void
    {
        $this->assertNull($this->create(trackingNo: '')->trackingNo);
        $this->assertNull($this->create(trackingNo: '   ')->trackingNo);
        $this->assertSame('1234567890', $this->create(trackingNo: ' 1234567890 ')->trackingNo);
    }

    public function testAShipmentWithoutADestinationOrServiceIsRefused(): void
    {
        $this->assertThrows(\InvalidArgumentException::class, fn () => $this->create(destId: ''));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $this->create(serviceId: '  '));
    }

    /* ===================================================================== */
    /*  status transitions                                                   */
    /* ===================================================================== */

    public function testAValidTransitionIsWrittenToBothTheRowAndTheTimeline(): void
    {
        $shipment = $this->create();

        $event = $this->shipments->addEvent($shipment->id, ShipmentStatus::PICKED_UP, 'Collected from Gulberg', self::ACTOR);

        $this->assertNotNull($event);
        $this->assertSame(ShipmentStatus::PICKED_UP, $event?->status);
        $this->assertSame('Collected from Gulberg', $event?->note);

        // The headline and the timeline cannot disagree.
        $history = $this->shipments->history($shipment->id);
        $this->assertSame(ShipmentStatus::PICKED_UP, $history?->shipment->status);
        $this->assertSame(ShipmentStatus::PICKED_UP, $history?->latest()?->status);
        $this->assertSame('Collected from Gulberg', $history?->currentNote());
        $this->assertSame(2, count($history?->events ?? []));
    }

    /**
     * The whole point of the table: a mis-click cannot tell a customer their
     * parcel has been delivered before it has been collected.
     */
    public function testARefusedTransitionChangesNothingAtAll(): void
    {
        $shipment = $this->create();

        $e = $this->assertThrows(
            InvalidTransition::class,
            fn () => $this->shipments->addEvent($shipment->id, ShipmentStatus::DELIVERED, 'signed for', self::ACTOR),
        );

        $this->assertStringContains('Booked', $e->getMessage());
        $this->assertStringContains('Delivered', $e->getMessage());

        $history = $this->shipments->history($shipment->id);
        $this->assertSame(ShipmentStatus::BOOKED, $history?->shipment->status, 'the status did not move');
        $this->assertSame(1, count($history?->events ?? []), 'no event was written');
    }

    public function testTheWholeHappyPathWalksThroughAndThenRefusesToGoBack(): void
    {
        $shipment = $this->create();

        foreach (
            [
                ShipmentStatus::PICKED_UP,
                ShipmentStatus::IN_TRANSIT,
                ShipmentStatus::CUSTOMS,
                ShipmentStatus::OUT_FOR_DELIVERY,
                ShipmentStatus::DELIVERED,
            ] as $status
        ) {
            $this->assertNotNull(
                $this->shipments->addEvent($shipment->id, $status, '', self::ACTOR),
                'could not move to ' . $status,
            );
        }

        $this->assertSame(ShipmentStatus::DELIVERED, $this->shipments->find($shipment->id)?->status);
        $this->assertFalse($this->shipments->find($shipment->id)?->isOpen() ?? true);

        // Delivered is the end of the road, except for a problem afterwards.
        $this->assertThrows(
            InvalidTransition::class,
            fn () => $this->shipments->addEvent($shipment->id, ShipmentStatus::IN_TRANSIT, '', self::ACTOR),
        );
        $this->assertNotNull(
            $this->shipments->addEvent($shipment->id, ShipmentStatus::EXCEPTION, 'Receiver says the box is damaged', self::ACTOR),
        );
    }

    public function testRepeatingAStatusAddsAnotherNoteUnderTheSameHeadline(): void
    {
        $shipment = $this->create();
        $this->shipments->addEvent($shipment->id, ShipmentStatus::IN_TRANSIT, 'On EK623', self::ACTOR);
        $this->shipments->addEvent($shipment->id, ShipmentStatus::CUSTOMS, 'Arrived Toronto, with customs', self::ACTOR);
        $this->shipments->addEvent($shipment->id, ShipmentStatus::CUSTOMS, 'Day three, still with customs', self::ACTOR);

        $history = $this->shipments->history($shipment->id);

        $this->assertSame(4, count($history?->events ?? []));
        $this->assertSame('Day three, still with customs', $history?->currentNote());
    }

    public function testAnUnknownStatusOrIdIsRefusedAndAMissingShipmentIsNull(): void
    {
        $shipment = $this->create();

        $this->assertThrows(
            \InvalidArgumentException::class,
            fn () => $this->shipments->addEvent($shipment->id, 'cancelled', '', self::ACTOR),
        );
        $this->assertThrows(
            \InvalidArgumentException::class,
            fn () => $this->shipments->addEvent('not-an-id', ShipmentStatus::PICKED_UP, '', self::ACTOR),
        );

        // A well-formed id for a shipment that is not there is null, not an
        // error: it is a stale tab, not a broken client.
        $this->assertNull($this->shipments->addEvent('SH-22222222', ShipmentStatus::PICKED_UP, '', self::ACTOR));
        $this->assertNull($this->shipments->find('SH-22222222'));
        $this->assertNull($this->shipments->history('SH-22222222'));
    }

    /* ===================================================================== */
    /*  editing, listing, counting                                           */
    /* ===================================================================== */

    public function testEditingChangesOnlyTheFieldsItIsGiven(): void
    {
        $shipment = $this->create(carrier: 'DHL', trackingNo: '111');

        $edited = $this->shipments->update($shipment->id, [
            'trackingNo' => ' 999 ',
            'notes'      => "Fragile.\nTwo boxes taped together.",
        ], self::ACTOR);

        $this->assertSame('999', $edited?->trackingNo);
        $this->assertSame("Fragile.\nTwo boxes taped together.", $edited?->notes);
        $this->assertSame('DHL', $edited?->carrier, 'the carrier was not in the patch');
        $this->assertSame(ShipmentStatus::BOOKED, $edited?->status, 'editing never changes the status');

        // An emptied tracking number becomes NULL, because "" is not a number.
        $this->assertNull($this->shipments->update($shipment->id, ['trackingNo' => ''], self::ACTOR)?->trackingNo);
    }

    public function testEditingAFieldThatDoesNotExistIsRefusedRatherThanIgnored(): void
    {
        $shipment = $this->create();

        $this->assertThrows(
            \InvalidArgumentException::class,
            fn () => $this->shipments->update($shipment->id, ['status' => ShipmentStatus::DELIVERED], self::ACTOR),
            'status must not be writable through the detail editor',
        );
        $this->assertThrows(
            \InvalidArgumentException::class,
            fn () => $this->shipments->update($shipment->id, ['destId' => 'gb'], self::ACTOR),
        );
    }

    public function testTheOpenListExcludesDeliveredAndTheCountsAgree(): void
    {
        $before = $this->shipments->counts();

        $open      = $this->create();
        $delivered = $this->create();
        $problem   = $this->create();

        foreach (
            [
                ShipmentStatus::PICKED_UP,
                ShipmentStatus::IN_TRANSIT,
                ShipmentStatus::OUT_FOR_DELIVERY,
                ShipmentStatus::DELIVERED,
            ] as $status
        ) {
            $this->shipments->addEvent($delivered->id, $status, '', self::ACTOR);
        }
        $this->shipments->addEvent($problem->id, ShipmentStatus::EXCEPTION, 'Address unreachable', self::ACTOR);

        $openIds = array_map(static fn (Shipment $s): string => $s->id, $this->shipments->list(ShipmentRepository::OPEN, 500));

        $this->assertTrue(in_array($open->id, $openIds, true));
        $this->assertTrue(in_array($problem->id, $openIds, true), 'an exception is still on the desk');
        $this->assertFalse(in_array($delivered->id, $openIds, true));

        $allIds = array_map(static fn (Shipment $s): string => $s->id, $this->shipments->list(ShipmentRepository::ANY, 500));
        $this->assertTrue(in_array($delivered->id, $allIds, true));

        $after = $this->shipments->counts();
        $this->assertSame($before['open'] + 2, $after['open'], 'two of the three are still open');
        $this->assertSame($before['exception'] + 1, $after['exception']);

        $exceptionIds = array_map(static fn (Shipment $s): string => $s->id, $this->shipments->list(ShipmentStatus::EXCEPTION, 500));
        $this->assertTrue(in_array($problem->id, $exceptionIds, true));
        $this->assertFalse(in_array($open->id, $exceptionIds, true));
    }

    /**
     * The message the customer gets is built from the record the office wrote,
     * end to end - the join between this repository and CustomerMessage.
     */
    public function testTheCustomerMessageIsBuiltFromTheStoredTimeline(): void
    {
        $shipment = $this->create(customerName: 'Ayesha Khan', carrier: 'DHL', trackingNo: '1234567890');
        $this->shipments->addEvent($shipment->id, ShipmentStatus::IN_TRANSIT, 'Handed to Emirates flight EK623', self::ACTOR);
        $this->shipments->addEvent($shipment->id, ShipmentStatus::CUSTOMS, 'Cleared, releasing tomorrow', self::ACTOR);

        $history = $this->shipments->history($shipment->id);
        $this->assertNotNull($history);

        $message = CustomerMessage::forShipment('Speedat International Courier', $history->shipment, 'Canada', $history);

        $this->assertStringContains('on your shipment ' . $shipment->id . ' to Canada:', $message);
        $this->assertStringContains(ShipmentStatus::CUSTOMER_TEXT[ShipmentStatus::CUSTOMS], $message);
        $this->assertStringContains('Cleared, releasing tomorrow', $message);
        $this->assertStringNotContains('EK623', $message, 'the earlier note stayed behind');
        $this->assertStringContains('Tracking number: 1234567890 (DHL)', $message);
    }

    /* ------------------------------------------------------------ helpers */

    private function create(
        ?int $leadId = null,
        string $destId = 'ca',
        string $serviceId = 'express',
        string $customerName = 'Ayesha Khan',
        string $carrier = '',
        string $trackingNo = '',
    ): Shipment {
        $shipment                 = $this->shipments->create(
            $leadId,
            null,
            $customerName,
            '03001234567',
            'Imran Khan',
            $destId,
            $serviceId,
            $carrier,
            $trackingNo,
            '',
            self::ACTOR,
        );
        $this->createdShipments[] = $shipment->id;

        return $shipment;
    }
}
