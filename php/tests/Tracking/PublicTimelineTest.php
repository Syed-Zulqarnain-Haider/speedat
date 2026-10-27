<?php

declare(strict_types=1);

namespace Speedat\Tests\Tracking;

use Speedat\Domain\Shipments\ShipmentEvent;
use Speedat\Domain\Shipments\ShipmentStatus;
use Speedat\Domain\Tracking\Checkpoint;
use Speedat\Domain\Tracking\PublicTimeline;
use Speedat\Domain\Tracking\Refresh;
use Speedat\Domain\Tracking\Stage;
use Speedat\Tests\TestCase;

/**
 * The public timeline is the page's privacy promise. These tests hand it the
 * most revealing records the office could write and check what comes out.
 */
final class PublicTimelineTest extends TestCase
{
    public function testOfficeNotesNeverReachThePublicRows(): void
    {
        $rows = PublicTimeline::rows([
            $this->event(1, ShipmentStatus::BOOKED, 'Customer Bilal Ahmed, 0300 1234567, collect from House 5', '2026-10-24 08:00:00', 'owner@speedat.test'),
            $this->event(2, ShipmentStatus::PICKED_UP, 'Handed to Emirates EK623', '2026-10-24 12:00:00', 'owner@speedat.test'),
        ], []);

        $dump = var_export($rows, true);
        foreach (['Bilal', '0300', 'House 5', 'EK623', 'owner@speedat.test'] as $secret) {
            $this->assertStringNotContains($secret, $dump, $secret);
        }

        $this->assertSame('Picked up', $rows[0]['label'], 'newest first');
        $this->assertSame('Booked', $rows[1]['label']);
        $this->assertSame('', $rows[0]['place']);
    }

    public function testCarrierScansAndOfficeEventsInterleaveNewestFirst(): void
    {
        $rows = PublicTimeline::rows(
            [
                $this->event(1, ShipmentStatus::BOOKED, '', '2026-10-24 08:00:00'),
                // Written by the cron because of the scan below: redundant, left out.
                $this->event(2, ShipmentStatus::IN_TRANSIT, '', '2026-10-25 17:05:00', Refresh::ACTOR),
            ],
            [
                $this->scan('2026-10-25 17:00:00', 'Lahore', 'PK', Stage::IN_TRANSIT),
                $this->scan('2026-10-26 05:10:00', 'Dubai', 'AE', Stage::CUSTOMS),
                $this->scan('2026-10-26 06:00:00', '', 'AE', Stage::COLLECTION),
            ],
        );

        $this->assertSame(
            [['Ready for collection', 'AE'], ['At customs', 'Dubai, AE'], ['In transit', 'Lahore, PK'], ['Booked', '']],
            array_map(static fn (array $r): array => [$r['label'], $r['place']], $rows),
        );
    }

    public function testTheLinkNeedsAnAppUrlAndEncodesTheId(): void
    {
        $this->assertSame('https://speedat.co/track?id=SH-ABCD2345', PublicTimeline::link('SH-ABCD2345', 'https://speedat.co/'));
        $this->assertSame('', PublicTimeline::link('SH-ABCD2345', ''));
    }

    public function testEveryStageHasACustomerLabelAndAKnownDeskMove(): void
    {
        foreach (Stage::ALL as $stage) {
            $this->assertTrue(Stage::label($stage) !== '', $stage);
            $this->assertStringNotContains("\u{2014}", Stage::label($stage), 'house style: no em dashes');
            $to = Stage::toStatus($stage);
            $this->assertTrue($to === null || ShipmentStatus::isValid($to), $stage);
        }

        $this->assertNull(Stage::toStatus(Stage::ATTEMPTED), 'a missed doorbell does not flag the desk');
        $this->assertSame(ShipmentStatus::EXCEPTION, Stage::toStatus(Stage::RETURNING));
    }

    private function event(int $id, string $status, string $note, string $utc, string $by = 'owner@speedat.test'): ShipmentEvent
    {
        return new ShipmentEvent($id, 'SH-ABCD2345', $status, $note, new \DateTimeImmutable($utc, new \DateTimeZone('UTC')), $by);
    }

    private function scan(string $utc, string $city, string $country, string $stage): Checkpoint
    {
        return new Checkpoint(new \DateTimeImmutable($utc, new \DateTimeZone('UTC')), $city, $country, $stage, sha1($utc . $city));
    }
}
