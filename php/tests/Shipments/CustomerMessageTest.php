<?php

declare(strict_types=1);

namespace Speedat\Tests\Shipments;

use Speedat\Domain\Shipments\CustomerMessage;
use Speedat\Domain\Shipments\ShipmentStatus;
use Speedat\Tests\TestCase;

/**
 * The WhatsApp update, against the same cases as
 * src/components/admin/shipmentMessage.test.ts - same shipment, same events,
 * same expected lines. If the PHP and the TypeScript ever disagree about this
 * text, one of the two is sending a customer something nobody wrote.
 */
final class CustomerMessageTest extends TestCase
{
    private const COMPANY = 'Speedat International Courier';

    private const ID       = 'SH-GJAVFXDR';
    private const NAME     = 'Ayesha Khan';
    private const DEST     = 'Canada';
    private const TRACKING = '1234567890';
    private const CARRIER  = 'DHL';

    public function testItCarriesTheNoteWrittenWithTheCurrentStatusChange(): void
    {
        $msg = $this->build(ShipmentStatus::CUSTOMS, [
            ['status' => 'booked', 'note' => ''],
            ['status' => 'in_transit', 'note' => 'Handed to Emirates flight EK623'],
            ['status' => 'customs', 'note' => 'Cleared by Canada Border Services, releasing tomorrow'],
        ]);

        $this->assertLines([
            'Hi Ayesha Khan, an update from Speedat International Courier on your shipment SH-GJAVFXDR to Canada:',
            ShipmentStatus::CUSTOMER_TEXT[ShipmentStatus::CUSTOMS],
            'Cleared by Canada Border Services, releasing tomorrow',
            'Tracking number: 1234567890 (DHL)',
        ], $msg);
    }

    public function testItDoesNotCarryAnEarlierStatusNoteUnderANewerStatus(): void
    {
        $msg = $this->build(ShipmentStatus::CUSTOMS, [
            ['status' => 'in_transit', 'note' => 'Handed to Emirates flight EK623'],
            ['status' => 'customs', 'note' => ''],
        ]);

        $this->assertStringNotContains('EK623', $msg);
        $this->assertLines([
            'Hi Ayesha Khan, an update from Speedat International Courier on your shipment SH-GJAVFXDR to Canada:',
            ShipmentStatus::CUSTOMER_TEXT[ShipmentStatus::CUSTOMS],
            'Tracking number: 1234567890 (DHL)',
        ], $msg);
    }

    public function testItIgnoresANoteWhoseEventIsNotTheCurrentStatus(): void
    {
        $msg = $this->build(ShipmentStatus::DELIVERED, [['status' => 'customs', 'note' => 'Cleared']]);

        $this->assertStringNotContains('Cleared', $msg);
        $this->assertStringContains(ShipmentStatus::CUSTOMER_TEXT[ShipmentStatus::DELIVERED], $msg);
    }

    public function testItLeavesNoBlankLineWhenTheNoteIsWhitespaceAndThereIsNoTracking(): void
    {
        $msg = CustomerMessage::build(
            self::COMPANY,
            self::ID,
            self::NAME,
            self::DEST,
            ShipmentStatus::CUSTOMS,
            '',
            '',
            [['status' => 'customs', 'note' => '   ']],
        );

        $this->assertLines([
            'Hi Ayesha Khan, an update from Speedat International Courier on your shipment SH-GJAVFXDR to Canada:',
            ShipmentStatus::CUSTOMER_TEXT[ShipmentStatus::CUSTOMS],
        ], $msg);
    }

    public function testItGreetsWithoutANameOmitsABlankCarrierAndReadsAnUnknownStatusAsBooked(): void
    {
        $msg = CustomerMessage::build(
            self::COMPANY,
            self::ID,
            '',
            self::DEST,
            'weird',
            self::TRACKING,
            '',
            [],
        );

        $this->assertLines([
            'Hi, an update from Speedat International Courier on your shipment SH-GJAVFXDR to Canada:',
            ShipmentStatus::CUSTOMER_TEXT[ShipmentStatus::BOOKED],
            'Tracking number: 1234567890',
        ], $msg);
    }

    public function testEveryStatusHasACustomerSentence(): void
    {
        foreach (ShipmentStatus::ALL as $status) {
            $this->assertTrue(
                strlen(ShipmentStatus::CUSTOMER_TEXT[$status] ?? '') > 10,
                'no customer sentence for ' . $status,
            );
        }
    }

    /**
     * The destination is printed as given, so a caller that hands over an id
     * puts an id in front of the customer.
     *
     * This is the Next.js bug in one assertion: the admin page printed
     * `s.destId` when the live rate card no longer carried the country. The
     * builder cannot defend itself here - it has no rate card - which is why
     * NameLookup exists and why NameLookupTest is the other half of this.
     */
    public function testTheDestinationIsPrintedVerbatimSoTheCallerMustPassAName(): void
    {
        $withName = $this->build(ShipmentStatus::IN_TRANSIT, [], 'United Kingdom');
        $withId   = $this->build(ShipmentStatus::IN_TRANSIT, [], 'gb');

        $this->assertStringContains('to United Kingdom:', $withName);
        $this->assertStringContains('to gb:', $withId);
    }

    /* ------------------------------------------------------------ helpers */

    /** @param list<array{status: string, note: string}> $events */
    private function build(string $status, array $events, string $destination = self::DEST): string
    {
        return CustomerMessage::build(
            self::COMPANY,
            self::ID,
            self::NAME,
            $destination,
            $status,
            self::TRACKING,
            self::CARRIER,
            $events,
        );
    }

    /** @param list<string> $expected */
    private function assertLines(array $expected, string $message): void
    {
        $this->assertSame(
            implode(' | ', $expected),
            implode(' | ', explode("\n", $message)),
            'the message lines differ',
        );
    }
}
