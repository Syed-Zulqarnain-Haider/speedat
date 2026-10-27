<?php

declare(strict_types=1);

namespace Speedat\Tests\Tracking;

use Speedat\Core\Clock;
use Speedat\Core\Db;
use Speedat\Domain\Shipments\ShipmentRepository;
use Speedat\Domain\Tracking\Checkpoint;
use Speedat\Domain\Tracking\Stage;
use Speedat\Domain\Tracking\TrackingRepository;
use Speedat\Tests\TestCase;

/**
 * The two tracking tables against a real database: the tracking row's
 * registered_at rules, the scans stored once each, and which shipments the
 * hourly job picks up.
 *
 * TIME IS FROZEN FAR AWAY. The test database is shared with every other suite
 * and is not truncated, so due() would also see whatever open shipment some
 * other test left with a tracking number. The clock is frozen in 2033: every
 * shipment made here is created "now", and everything anybody else made is
 * years older than due()'s 120-day window, so the list is this test's alone.
 * The assertions still only look at this test's ids.
 */
final class TrackingRepositoryTest extends TestCase
{
    private const NOW = '2033-05-17 09:00:00';

    private TrackingRepository $tracking;

    private ShipmentRepository $shipments;

    /** @var list<string> */
    private array $created = [];

    public function setUp(): void
    {
        if (!Db::healthy()) {
            $this->skip('the test database is not reachable - start MariaDB (bin/local/start-db.ps1) and run bin/migrate.php --database=speedat_php_test');
        }

        Clock::freeze(self::now());

        $this->tracking  = new TrackingRepository();
        $this->shipments = new ShipmentRepository();
    }

    public function tearDown(): void
    {
        Clock::freeze(null);

        if (!Db::healthy()) {
            return;
        }

        foreach ($this->created as $id) {
            Db::exec('DELETE FROM tracking_checkpoints WHERE shipment_id = ?', [$id]);
            Db::exec('DELETE FROM shipment_tracking WHERE shipment_id = ?', [$id]);
            Db::exec('DELETE FROM shipment_events WHERE shipment_id = ?', [$id]);
            Db::exec('DELETE FROM shipments WHERE id = ?', [$id]);
        }

        $this->created = [];
    }

    /* ===================================================================== */
    /*  save()                                                               */
    /* ===================================================================== */

    public function testSaveInsertsTheRowTheFirstTime(): void
    {
        $id = $this->shipment();

        $this->assertNull($this->tracking->state($id), 'no row before the first save');

        $this->tracking->save($id, 'fake:100001:RT1', 100001, TrackingRepository::STATE_REGISTERED, '', true);

        $row = $this->row($id);
        $this->assertSame('fake:100001:RT1', $row['track_key']);
        $this->assertSame(100001, (int) $row['carrier_code']);
        $this->assertSame(TrackingRepository::STATE_REGISTERED, $row['state']);
        $this->assertSame('', $row['error']);
        $this->assertSame(self::NOW, $row['registered_at'], 'true stamps registered_at with now');
        $this->assertSame(self::NOW, $row['checked_at']);

        $state = $this->tracking->state($id);
        $this->assertSame(TrackingRepository::STATE_REGISTERED, $state['state'] ?? null);
        $this->assertSame(self::NOW, $state['checked_at'] === null ? null : Clock::sql($state['checked_at']));
    }

    public function testSaveUpdatesTheSameRowAndRegisteredFollowsTrueFalseNull(): void
    {
        $id   = $this->shipment();
        $now  = self::now();

        $this->tracking->save($id, 'fake:1:A', 1, TrackingRepository::STATE_REGISTERED, '', true, $now);

        // null: leave registered_at alone; everything else is overwritten.
        $later = $now->modify('+1 hour');
        $this->tracking->save($id, 'fake:1:A', 7, TrackingRepository::STATE_ACTIVE, '', null, $later);

        $row = $this->row($id);
        $this->assertSame(self::NOW, $row['registered_at'], 'null left registered_at as it was');
        $this->assertSame(Clock::sql($later), $row['checked_at']);
        $this->assertSame(TrackingRepository::STATE_ACTIVE, $row['state']);
        $this->assertSame(7, (int) $row['carrier_code']);
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM shipment_tracking WHERE shipment_id = ?', [$id]), 'one row, updated');

        // true on an update: stamped again, with the new time.
        $again = $now->modify('+2 hours');
        $this->tracking->save($id, 'fake:1:B', 1, TrackingRepository::STATE_REGISTERED, '', true, $again);
        $this->assertSame(Clock::sql($again), $this->row($id)['registered_at']);
        $this->assertSame('fake:1:B', $this->row($id)['track_key']);

        // false: cleared, so the provider is asked again.
        $this->tracking->save($id, 'fake:1:B', 1, TrackingRepository::STATE_ERROR, 'not registered', false, $now->modify('+3 hours'));
        $row = $this->row($id);
        $this->assertNull($row['registered_at'], 'false cleared registered_at');
        $this->assertSame(TrackingRepository::STATE_ERROR, $row['state']);
        $this->assertSame('not registered', $row['error']);

        // And an error is cleared by the next good save.
        $this->tracking->save($id, 'fake:1:B', 1, TrackingRepository::STATE_ACTIVE, '', null, $now->modify('+4 hours'));
        $this->assertSame('', $this->row($id)['error']);
        $this->assertNull($this->row($id)['registered_at'], 'null after false is still null');
    }

    public function testFalseOrNullOnTheFirstSaveLeavesRegisteredAtEmpty(): void
    {
        $manual = $this->shipment();
        $this->tracking->save($manual, '', 0, TrackingRepository::STATE_MANUAL, '', false);
        $this->assertNull($this->row($manual)['registered_at']);
        $this->assertSame(self::NOW, $this->row($manual)['checked_at'], 'checked_at is always stamped');

        $other = $this->shipment();
        $this->tracking->save($other, 'k', 0, TrackingRepository::STATE_ACTIVE, '', null);
        $this->assertNull($this->row($other)['registered_at']);
    }

    public function testAnOverlongErrorIsCutToTheColumnRatherThanRefused(): void
    {
        $id = $this->shipment();

        $this->tracking->save($id, 'k', 0, TrackingRepository::STATE_ERROR, str_repeat('x', 400), false);

        $this->assertSame(190, mb_strlen((string) $this->row($id)['error']));
    }

    /* ===================================================================== */
    /*  checkpoints                                                          */
    /* ===================================================================== */

    public function testTheSameScansTwiceAreStoredOnce(): void
    {
        $id   = $this->shipment();
        $list = $this->scans();

        $this->assertSame(3, $this->tracking->addCheckpoints($id, $list));
        $this->assertSame(0, $this->tracking->addCheckpoints($id, $list), 'yesterday\'s answer repeated writes nothing');

        $this->assertSame(3, (int) Db::value('SELECT COUNT(*) FROM tracking_checkpoints WHERE shipment_id = ?', [$id]));

        // One new scan on top of the old ones: only it is counted.
        $more   = $list;
        $more[] = new Checkpoint(self::utc('2033-05-16 18:00:00'), 'London', 'GB', Stage::DELIVERED, sha1('d'));
        $this->assertSame(1, $this->tracking->addCheckpoints($id, $more));
        $this->assertSame(4, (int) Db::value('SELECT COUNT(*) FROM tracking_checkpoints WHERE shipment_id = ?', [$id]));
    }

    public function testTheSameFingerprintOnAnotherShipmentIsItsOwnRow(): void
    {
        $a = $this->shipment();
        $b = $this->shipment();

        $this->assertSame(3, $this->tracking->addCheckpoints($a, $this->scans()));
        $this->assertSame(3, $this->tracking->addCheckpoints($b, $this->scans()), 'the fingerprint is unique per shipment');
    }

    public function testCheckpointsComeBackOldestFirstInUtc(): void
    {
        $id = $this->shipment();

        // Handed over newest first, and one of them in Karachi time.
        $this->tracking->addCheckpoints($id, [
            new Checkpoint(self::utc('2033-05-16 07:30:00'), 'London', 'GB', Stage::OUT_FOR_DELIVERY, sha1('c')),
            new Checkpoint(new \DateTimeImmutable('2033-05-15 14:00:00', new \DateTimeZone('Asia/Karachi')), 'Dubai', 'AE', Stage::IN_TRANSIT, sha1('b')),
            new Checkpoint(self::utc('2033-05-14 06:00:00'), 'Lahore', 'PK', Stage::PICKED_UP, sha1('a')),
        ]);

        $back = $this->tracking->checkpoints($id);

        $this->assertCount(3, $back);
        $this->assertSame(['Lahore', 'Dubai', 'London'], array_map(static fn (Checkpoint $c): string => $c->city, $back), 'oldest first');
        $this->assertSame('2033-05-15 09:00:00', $back[1]->at->format('Y-m-d H:i:s'), '14:00 in Karachi is 09:00 UTC');
        foreach ($back as $cp) {
            $this->assertSame('UTC', $cp->at->getTimezone()->getName(), 'handed back in UTC');
        }
        $this->assertSame(Stage::IN_TRANSIT, $back[1]->stage);
        $this->assertSame('AE', $back[1]->country);
        $this->assertSame(sha1('b'), $back[1]->fingerprint);
    }

    public function testTheLimitKeepsTheNewestScans(): void
    {
        $id = $this->shipment();
        $this->tracking->addCheckpoints($id, $this->scans());

        $back = $this->tracking->checkpoints($id, 2);

        $this->assertSame(['Dubai', 'London'], array_map(static fn (Checkpoint $c): string => $c->city, $back), 'the two newest, still oldest first');
    }

    public function testAnUnknownStageOrCountryIsStoredAsSomethingSafe(): void
    {
        $id = $this->shipment();

        $this->tracking->addCheckpoints($id, [
            new Checkpoint(self::utc('2033-05-14 06:00:00'), 'Lahore', 'Pakistan', 'signed for by AHMED', sha1('x')),
        ]);

        $back = $this->tracking->checkpoints($id);
        $this->assertSame(Stage::IN_TRANSIT, $back[0]->stage);
        $this->assertSame('', $back[0]->country);
    }

    /* ===================================================================== */
    /*  due()                                                                */
    /* ===================================================================== */

    public function testAShipmentWithACarrierAndANumberIsDue(): void
    {
        $id = $this->shipment();

        $row = $this->dueRow($id);

        $this->assertNotNull($row, 'never checked, so due');
        $this->assertSame('DHL', $row['carrier'] ?? null);
        $this->assertSame('booked', $row['status'] ?? null);
        $this->assertSame('', $row['track_key'] ?? null);
        $this->assertSame('', $row['state'] ?? null);
        $this->assertFalse($row['registered'] ?? null);
    }

    public function testDueCarriesTheTrackingRow(): void
    {
        $id = $this->shipment();
        $this->tracking->save($id, 'fake:100001:X', 100001, TrackingRepository::STATE_ACTIVE, '', true, self::now()->modify('-2 hours'));

        $row = $this->dueRow($id);

        $this->assertNotNull($row);
        $this->assertSame('fake:100001:X', $row['track_key'] ?? null);
        $this->assertSame(100001, $row['carrier_code'] ?? null);
        $this->assertSame(TrackingRepository::STATE_ACTIVE, $row['state'] ?? null);
        $this->assertTrue($row['registered'] ?? null);
    }

    public function testNoTrackingNumberOrNoCarrierIsNotDue(): void
    {
        $this->assertNull($this->dueRow($this->shipment(trackingNo: '')), 'no tracking number');
        $this->assertNull($this->dueRow($this->shipment(carrier: '')), 'no carrier');
    }

    public function testAShipmentOlderThan120DaysIsNotDue(): void
    {
        $old = $this->shipment();
        Db::exec('UPDATE shipments SET created_at = ? WHERE id = ?', [Clock::sql(self::now()->modify('-121 days')), $old]);

        $recent = $this->shipment();
        Db::exec('UPDATE shipments SET created_at = ? WHERE id = ?', [Clock::sql(self::now()->modify('-119 days')), $recent]);

        $this->assertNull($this->dueRow($old));
        $this->assertNotNull($this->dueRow($recent));
    }

    public function testAFinishedShipmentIsNotDue(): void
    {
        $id = $this->shipment();
        $this->tracking->save($id, 'k', 1, TrackingRepository::STATE_DONE, '', null, self::now()->modify('-3 days'));

        $this->assertNull($this->dueRow($id));
    }

    public function testOneCheckedTenMinutesAgoWaitsAndOneCheckedTwoHoursAgoIsDue(): void
    {
        $fresh = $this->shipment();
        $this->tracking->save($fresh, 'k', 1, TrackingRepository::STATE_ACTIVE, '', true, self::now()->modify('-10 minutes'));

        $stale = $this->shipment();
        $this->tracking->save($stale, 'k', 1, TrackingRepository::STATE_ACTIVE, '', true, self::now()->modify('-2 hours'));

        $this->assertNull($this->dueRow($fresh), 'a second cron run in the same hour costs nothing');
        $this->assertNotNull($this->dueRow($stale));
    }

    public function testAManualShipmentIsLookedAtAgainHourly(): void
    {
        $id = $this->shipment(carrier: 'TeamGlobal');
        $this->tracking->save($id, '', 0, TrackingRepository::STATE_MANUAL, '', false, self::now()->modify('-2 hours'));

        $this->assertNotNull($this->dueRow($id), 'manual is not done: the office may yet change the carrier');
    }

    public function testAnErrorIsRetriedOnlyAfterSixHours(): void
    {
        $twoHours = $this->shipment();
        $this->tracking->save($twoHours, 'k', 1, TrackingRepository::STATE_ERROR, 'bad number', false, self::now()->modify('-2 hours'));

        $fiveAndAHalf = $this->shipment();
        $this->tracking->save($fiveAndAHalf, 'k', 1, TrackingRepository::STATE_ERROR, 'bad number', false, self::now()->modify('-330 minutes'));

        $sevenHours = $this->shipment();
        $this->tracking->save($sevenHours, 'k', 1, TrackingRepository::STATE_ERROR, 'bad number', false, self::now()->modify('-7 hours'));

        $this->assertNull($this->dueRow($twoHours), 'an ordinary row two hours old would be due; an error is not');
        $this->assertNull($this->dueRow($fiveAndAHalf));
        $this->assertNotNull($this->dueRow($sevenHours));
    }

    public function testNeverCheckedComeFirstThenTheLeastRecentlyChecked(): void
    {
        $hourAgo = $this->shipment();
        $this->tracking->save($hourAgo, 'k', 1, TrackingRepository::STATE_ACTIVE, '', true, self::now()->modify('-1 hour'));

        $never = $this->shipment();

        $dayAgo = $this->shipment();
        $this->tracking->save($dayAgo, 'k', 1, TrackingRepository::STATE_ACTIVE, '', true, self::now()->modify('-1 day'));

        $order = $this->dueIds();

        $this->assertSame([$never, $dayAgo, $hourAgo], $order);
    }

    public function testTheLimitIsHonoured(): void
    {
        $this->shipment();
        $this->shipment();
        $this->shipment();

        $this->assertCount(2, $this->tracking->due(2, self::now()));
    }

    /* ===================================================================== */
    /*  helpers                                                              */
    /* ===================================================================== */

    private static function now(): \DateTimeImmutable
    {
        return self::utc(self::NOW);
    }

    private static function utc(string $when): \DateTimeImmutable
    {
        return new \DateTimeImmutable($when, new \DateTimeZone('UTC'));
    }

    private function shipment(string $carrier = 'DHL', string $trackingNo = 'auto'): string
    {
        $number = $trackingNo === 'auto' ? 'RT' . strtoupper(bin2hex(random_bytes(6))) : $trackingNo;

        $shipment = $this->shipments->create(
            null,
            null,
            'Tracking test',
            '03001234567',
            'Receiver',
            'gb',
            'express',
            $carrier,
            $number,
            '',
            'tracking-test',
        );

        $this->created[] = $shipment->id;

        return $shipment->id;
    }

    /** @return array<string,mixed> */
    private function row(string $id): array
    {
        $row = Db::one('SELECT * FROM shipment_tracking WHERE shipment_id = ?', [$id]);
        if ($row === null) {
            $this->fail('no shipment_tracking row for ' . $id);
        }

        return $row;
    }

    /** @return array<string,mixed>|null */
    private function dueRow(string $id): ?array
    {
        foreach ($this->tracking->due(500, self::now()) as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /** @return list<string> this test's due shipments, in the order due() gave them */
    private function dueIds(): array
    {
        $ids = [];
        foreach ($this->tracking->due(500, self::now()) as $row) {
            if (in_array($row['id'], $this->created, true)) {
                $ids[] = $row['id'];
            }
        }

        return $ids;
    }

    /** @return list<Checkpoint> */
    private function scans(): array
    {
        return [
            new Checkpoint(self::utc('2033-05-14 06:00:00'), 'Lahore', 'PK', Stage::PICKED_UP, sha1('a')),
            new Checkpoint(self::utc('2033-05-15 09:00:00'), 'Dubai', 'AE', Stage::IN_TRANSIT, sha1('b')),
            new Checkpoint(self::utc('2033-05-16 07:30:00'), 'London', 'GB', Stage::OUT_FOR_DELIVERY, sha1('c')),
        ];
    }
}
