<?php

declare(strict_types=1);

namespace Speedat\Tests\Tracking;

use Speedat\Core\Clock;
use Speedat\Core\Config;
use Speedat\Core\Db;
use Speedat\Core\Paths;
use Speedat\Domain\Ops\Counts;
use Speedat\Domain\Ops\Cron;
use Speedat\Domain\Ops\DailyOnce;
use Speedat\Domain\Ops\Housekeeping;
use Speedat\Domain\Shipments\ShipmentRepository;
use Speedat\Domain\Shipments\ShipmentStatus;
use Speedat\Domain\Tracking\Checkpoint;
use Speedat\Domain\Tracking\Refresh;
use Speedat\Domain\Tracking\SeventeenTrack;
use Speedat\Domain\Tracking\Stage;
use Speedat\Domain\Tracking\TrackingRepository;
use Speedat\Domain\Tracking\TrackReport;
use Speedat\Tests\Ops\Support\FakeHold;
use Speedat\Tests\Ops\Support\FakeVersions;
use Speedat\Tests\Ops\Support\RecordingOffice;
use Speedat\Tests\TestCase;
use Speedat\Tests\Tracking\Support\FakeProvider;

/**
 * The hourly tracking job end to end, against a real database and a fake
 * tracking service (Support\FakeProvider) that answers what the test says and
 * remembers what it was asked.
 *
 * TIME IS FROZEN IN 2033, for the reason TrackingRepositoryTest gives: Refresh
 * looks at EVERY due shipment in the database, not just this test's, and the
 * shared test database holds other suites' shipments. Frozen years ahead, those
 * are all far outside the 120-day window and the job sees only what this test
 * made - so "1 checked" means this test's one shipment.
 */
final class RefreshTest extends TestCase
{
    private const NOW = '2033-05-17 09:00:00';

    /** Who the office's own button presses are by, in these tests. */
    private const ADMIN = 'owner@speedat.test';

    private TrackingRepository $tracking;

    private ShipmentRepository $shipments;

    private string $tag = '';

    /** @var list<string> */
    private array $created = [];

    /** @var list<string> */
    private array $settingsKeys = [];

    private string $savedCachePath = '';

    private int $savedLogDays = 0;

    private string $cacheDir = '';

    public function setUp(): void
    {
        if (!Db::healthy()) {
            $this->skip('the test database is not reachable - start MariaDB (bin/local/start-db.ps1) and run bin/migrate.php --database=speedat_php_test');
        }

        $this->tag = 'trtest' . bin2hex(random_bytes(5));

        Clock::freeze(self::now());

        $this->tracking  = new TrackingRepository();
        $this->shipments = new ShipmentRepository();
    }

    public function tearDown(): void
    {
        Clock::freeze(null);

        if ($this->savedCachePath !== '') {
            Config::set('paths.cache', $this->savedCachePath);
            Config::set('ops.log_days', $this->savedLogDays);
        }
        if ($this->cacheDir !== '' && is_dir($this->cacheDir)) {
            foreach (glob($this->cacheDir . '/*') ?: [] as $entry) {
                if (is_file($entry)) {
                    @unlink($entry);
                }
            }
            @rmdir($this->cacheDir);
        }

        if (!Db::healthy()) {
            return;
        }

        foreach ($this->created as $id) {
            Db::exec('DELETE FROM tracking_checkpoints WHERE shipment_id = ?', [$id]);
            Db::exec('DELETE FROM shipment_tracking WHERE shipment_id = ?', [$id]);
            Db::exec('DELETE FROM shipment_events WHERE shipment_id = ?', [$id]);
            Db::exec('DELETE FROM shipments WHERE id = ?', [$id]);
        }
        foreach ($this->settingsKeys as $key) {
            Db::exec('DELETE FROM app_settings WHERE k = ?', [$key]);
        }

        $this->created      = [];
        $this->settingsKeys = [];
    }

    /* ===================================================================== */
    /*  switched off                                                         */
    /* ===================================================================== */

    public function testWithoutAProviderTheJobSaysItIsOff(): void
    {
        $id = $this->shipment();

        $line = (new Refresh(null))->run(self::now());

        $this->assertStringContains('off', $line);
        $this->assertNull($this->tracking->state($id), 'nothing was written');
    }

    /* ===================================================================== */
    /*  registering and fetching                                             */
    /* ===================================================================== */

    public function testTheFirstRunRegistersThenFetches(): void
    {
        $number   = $this->number();
        $id       = $this->shipment(trackingNo: $number);
        $provider = new FakeProvider();

        $line = $this->refresh($provider)->run(self::now());

        $this->assertSame(
            ['register:' . $number . ':100001', 'fetch:' . $number . ':100001'],
            $provider->calls(),
            'registered first, with DHL\'s code, then fetched',
        );
        $this->assertStringContains('1 checked', $line);

        $row = $this->row($id);
        $this->assertSame('fake:100001:' . $number, $row['track_key']);
        $this->assertSame(100001, (int) $row['carrier_code']);
        $this->assertSame(TrackingRepository::STATE_ACTIVE, $row['state'], 'answered, nothing delivered yet');
        $this->assertSame(self::NOW, $row['registered_at']);
        $this->assertSame(self::NOW, $row['checked_at']);
    }

    public function testASecondRunWithinTheHourDoesNothing(): void
    {
        $this->shipment();
        $provider = new FakeProvider();

        $this->refresh($provider)->run(self::now());
        $provider->clearCalls();

        $line = $this->refresh($provider)->run(self::now()->modify('+30 minutes'));

        $this->assertSame([], $provider->calls(), 'the provider was not asked');
        $this->assertSame('nothing due', $line);
    }

    public function testAnHourLaterItFetchesWithoutRegisteringAgain(): void
    {
        $number   = $this->number();
        $this->shipment(trackingNo: $number);
        $provider = new FakeProvider();

        $this->refresh($provider)->run(self::now());
        $provider->clearCalls();

        $this->refresh($provider)->run(self::now()->modify('+1 hour'));

        $this->assertSame([], $provider->registered(), 'already registered under the same key');
        $this->assertSame([$number], $provider->fetched());
    }

    public function testACorrectedTrackingNumberIsRegisteredAgain(): void
    {
        $first    = $this->number();
        $id       = $this->shipment(trackingNo: $first);
        $provider = new FakeProvider();

        $this->refresh($provider)->run(self::now());
        $provider->clearCalls();

        $second = $this->number();
        $this->shipments->update($id, ['trackingNo' => $second], self::ADMIN);

        $this->refresh($provider)->run(self::now()->modify('+1 hour'));

        $this->assertSame([$second], $provider->registered(), 'the new number is registered');
        $this->assertSame([$second], $provider->fetched(), 'and only the new number is fetched');
        $this->assertSame('fake:100001:' . $second, $this->row($id)['track_key']);
        $this->assertSame(Clock::sql(self::now()->modify('+1 hour')), $this->row($id)['registered_at']);
    }

    public function testACorrectedCarrierIsRegisteredAgainUnderItsCode(): void
    {
        $number   = $this->number();
        $id       = $this->shipment(trackingNo: $number);
        $provider = new FakeProvider();

        $this->refresh($provider)->run(self::now());
        $provider->clearCalls();

        $this->shipments->update($id, ['carrier' => 'FedEx'], self::ADMIN);
        $this->refresh($provider)->run(self::now()->modify('+1 hour'));

        $this->assertSame(['register:' . $number . ':100003', 'fetch:' . $number . ':100003'], $provider->calls());
        $this->assertSame('fake:100003:' . $number, $this->row($id)['track_key']);
    }

    public function testTeamGlobalIsTrackedByHandAndNeverSentToTheProvider(): void
    {
        $number   = $this->number();
        $id       = $this->shipment(carrier: 'TeamGlobal', trackingNo: $number);
        $provider = new FakeProvider();

        $line = $this->refresh($provider)->run(self::now());

        $this->assertSame([], $provider->calls(), 'no call carried the TeamGlobal number');
        $this->assertSame(TrackingRepository::STATE_MANUAL, $this->row($id)['state']);
        $this->assertSame('', $this->row($id)['track_key']);
        $this->assertNull($this->row($id)['registered_at']);
        $this->assertStringContains('1 tracked by hand', $line);

        // A tracked shipment beside it is still handled, and the hand-tracked
        // one is still not sent.
        $other = $this->number();
        $this->shipment(trackingNo: $other);

        $this->refresh($provider)->run(self::now()->modify('+1 hour'));

        $this->assertSame([$other], $provider->registered());
        $this->assertSame([$other], $provider->fetched());
    }

    public function testARefusedRegistrationIsAnErrorAndIsNotFetched(): void
    {
        $bad      = $this->number();
        $good     = $this->number();
        $badId    = $this->shipment(trackingNo: $bad);
        $goodId   = $this->shipment(trackingNo: $good);
        $provider = (new FakeProvider())->reject($bad, 'invalid tracking number');

        $line = $this->refresh($provider)->run(self::now());

        $this->assertSame([$good], $provider->fetched(), 'the refused number was not fetched');
        $this->assertCount(2, $provider->registered());

        $row = $this->row($badId);
        $this->assertSame(TrackingRepository::STATE_ERROR, $row['state']);
        $this->assertSame('invalid tracking number', $row['error']);
        $this->assertNull($row['registered_at']);
        $this->assertSame(TrackingRepository::STATE_ACTIVE, $this->row($goodId)['state']);
        $this->assertStringContains('1 not answered', $line);
    }

    public function testAForgottenNumberIsRegisteredAgainOnTheNextDueRun(): void
    {
        $number   = $this->number();
        $id       = $this->shipment(trackingNo: $number);
        $provider = new FakeProvider();

        $this->refresh($provider)->run(self::now());
        $this->assertNotNull($this->row($id)['registered_at']);

        // An hour later 17TRACK says it has never heard of it.
        $provider->answer($number, TrackReport::failed(SeventeenTrack::ERROR_NOT_REGISTERED));
        $provider->clearCalls();
        $this->refresh($provider)->run(self::now()->modify('+1 hour'));

        $this->assertSame([], $provider->registered());
        $row = $this->row($id);
        $this->assertNull($row['registered_at'], 'cleared, so it is asked again');
        $this->assertSame(TrackingRepository::STATE_REGISTERED, $row['state'], 'not "error": a forgotten number is not held back six hours');
        $this->assertSame(SeventeenTrack::ERROR_NOT_REGISTERED, $row['error']);

        // The next hourly run registers it before fetching.
        $provider->answer($number, new TrackReport(null, []));
        $provider->clearCalls();
        $this->refresh($provider)->run(self::now()->modify('+2 hours'));

        $this->assertSame(['register:' . $number . ':100001', 'fetch:' . $number . ':100001'], $provider->calls());
        $this->assertNotNull($this->row($id)['registered_at']);
        $this->assertSame(TrackingRepository::STATE_ACTIVE, $this->row($id)['state']);
        $this->assertSame('', $this->row($id)['error']);
    }

    public function testAnyOtherFetchFailureKeepsTheRegistration(): void
    {
        $number   = $this->number();
        $id       = $this->shipment(trackingNo: $number);
        $provider = new FakeProvider();

        $this->refresh($provider)->run(self::now());

        $provider->answer($number, TrackReport::failed('timed out'));
        $this->refresh($provider)->run(self::now()->modify('+1 hour'));

        $row = $this->row($id);
        $this->assertSame(TrackingRepository::STATE_ERROR, $row['state']);
        $this->assertSame('timed out', $row['error']);
        $this->assertSame(self::NOW, $row['registered_at'], 'a bad hour, not a bad number');
    }

    /* ===================================================================== */
    /*  moving the desk                                                      */
    /* ===================================================================== */

    public function testDeliveredFromBookedWalksTheChainStoresTheScansAndFinishes(): void
    {
        $number   = $this->number();
        $id       = $this->shipment(trackingNo: $number);
        $provider = (new FakeProvider())->answer($number, new TrackReport(Stage::DELIVERED, $this->scans(), '', 100001));

        $line = $this->refresh($provider)->run(self::now());

        $events = $this->events($id);
        $this->assertSame(
            ['booked', 'in_transit', 'out_for_delivery', 'delivered'],
            array_column($events, 'status'),
        );
        $this->assertSame(['tracking', 'tracking', 'tracking'], array_column(array_slice($events, 1), 'by'), 'written by "tracking"');
        $this->assertSame(ShipmentStatus::DELIVERED, $this->status($id));

        $this->assertCount(3, $this->tracking->checkpoints($id));
        $this->assertSame(TrackingRepository::STATE_DONE, $this->row($id)['state']);
        $this->assertStringContains('3 new scan(s)', $line);
        $this->assertStringContains('1 status change(s)', $line);

        // Done means done: the next run does not ask about it.
        $provider->clearCalls();
        $this->refresh($provider)->run(self::now()->modify('+2 hours'));
        $this->assertSame([], $provider->calls());
    }

    public function testTheSameScansNextHourAreNotStoredTwice(): void
    {
        $number   = $this->number();
        $id       = $this->shipment(trackingNo: $number);
        $provider = (new FakeProvider())->answer($number, new TrackReport(Stage::IN_TRANSIT, $this->scans()));

        $this->refresh($provider)->run(self::now());
        $line = $this->refresh($provider)->run(self::now()->modify('+1 hour'));

        $this->assertCount(3, $this->tracking->checkpoints($id));
        $this->assertStringContains('0 new scan(s), 0 status change(s)', $line);
        $this->assertCount(2, $this->events($id), 'booked, then in transit once');
    }

    public function testItNeverMovesBackwards(): void
    {
        $number = $this->number();
        $id     = $this->shipment(trackingNo: $number);
        $this->shipments->addEvent($id, ShipmentStatus::IN_TRANSIT, '', self::ADMIN);
        $this->shipments->addEvent($id, ShipmentStatus::CUSTOMS, '', self::ADMIN);

        $provider = (new FakeProvider())->answer($number, new TrackReport(Stage::IN_TRANSIT, $this->scans()));

        $this->refresh($provider)->run(self::now());

        $this->assertSame(ShipmentStatus::CUSTOMS, $this->status($id));
        $this->assertCount(3, $this->events($id), 'no event written');
        $this->assertCount(3, $this->tracking->checkpoints($id), 'the scans are still kept');
        $this->assertSame(TrackingRepository::STATE_ACTIVE, $this->row($id)['state']);
    }

    public function testAShipmentNeedingAttentionIsNeverMovedOutOfIt(): void
    {
        $number = $this->number();
        $id     = $this->shipment(trackingNo: $number);
        $this->shipments->addEvent($id, ShipmentStatus::EXCEPTION, 'customer unreachable', self::ADMIN);

        $provider = (new FakeProvider())->answer($number, new TrackReport(Stage::DELIVERED, $this->scans()));

        $this->refresh($provider)->run(self::now());

        $this->assertSame(ShipmentStatus::EXCEPTION, $this->status($id), 'whether it is solved is the office\'s call');
        $this->assertCount(2, $this->events($id));
    }

    public function testAReturnFlagsTheShipment(): void
    {
        $number = $this->number();
        $id     = $this->shipment(trackingNo: $number);
        $this->shipments->addEvent($id, ShipmentStatus::IN_TRANSIT, '', self::ADMIN);

        $provider = (new FakeProvider())->answer($number, new TrackReport(Stage::RETURNING, []));

        $this->refresh($provider)->run(self::now());

        $events = $this->events($id);
        $this->assertSame(ShipmentStatus::EXCEPTION, $this->status($id));
        $this->assertSame(['exception', 'tracking'], [$events[2]['status'] ?? null, $events[2]['by'] ?? null]);
        $this->assertSame(TrackingRepository::STATE_ACTIVE, $this->row($id)['state'], 'a return is not the end of tracking');
    }

    public function testAStageThatMovesNothingMovesNothing(): void
    {
        $number = $this->number();
        $id     = $this->shipment(trackingNo: $number);
        $this->shipments->addEvent($id, ShipmentStatus::IN_TRANSIT, '', self::ADMIN);
        $this->shipments->addEvent($id, ShipmentStatus::OUT_FOR_DELIVERY, '', self::ADMIN);

        $provider = (new FakeProvider())->answer($number, new TrackReport(Stage::ATTEMPTED, []));

        $this->refresh($provider)->run(self::now());

        $this->assertSame(ShipmentStatus::OUT_FOR_DELIVERY, $this->status($id));
        $this->assertCount(3, $this->events($id));
    }

    public function testTheDetectedCarrierCodeIsKept(): void
    {
        $number   = $this->number();
        $id       = $this->shipment(carrier: 'DPD', trackingNo: $number);
        $provider = (new FakeProvider())->answer($number, new TrackReport(null, [], '', 100007));

        $this->refresh($provider)->run(self::now());

        $this->assertSame(['register:' . $number . ':0', 'fetch:' . $number . ':0'], $provider->calls(), 'DPD is sent as "detect"');
        $this->assertSame(100007, (int) $this->row($id)['carrier_code'], 'the code 17TRACK detected is stored');
    }

    public function testOneBadAnswerDoesNotStopTheOthers(): void
    {
        $bad      = $this->number();
        $good     = $this->number();
        $badId    = $this->shipment(trackingNo: $bad);
        $goodId   = $this->shipment(trackingNo: $good);

        // A list that is not a list of Checkpoints: storing it throws inside
        // the shipment's transaction.
        $provider = (new FakeProvider())
            ->answer($bad, new TrackReport(Stage::IN_TRANSIT, ['not a checkpoint']))
            ->answer($good, new TrackReport(Stage::IN_TRANSIT, $this->scans()));

        $line = $this->refresh($provider)->run(self::now());

        $this->assertSame(ShipmentStatus::IN_TRANSIT, $this->status($goodId), 'the good one still moved');
        $this->assertCount(3, $this->tracking->checkpoints($goodId));

        $this->assertSame(ShipmentStatus::BOOKED, $this->status($badId), 'the bad one\'s transaction was rolled back');
        $this->assertCount(1, $this->events($badId));
        $this->assertCount(0, $this->tracking->checkpoints($badId));
        $this->assertStringContains('1 not answered', $line);
    }

    public function testANumberMissingFromTheAnswerIsAFailureNotACrash(): void
    {
        $number   = $this->number();
        $id       = $this->shipment(trackingNo: $number);
        $provider = (new FakeProvider())->omit($number);

        $line = $this->refresh($provider)->run(self::now());

        $this->assertSame(TrackingRepository::STATE_ERROR, $this->row($id)['state']);
        $this->assertSame('no answer from the tracking service', $this->row($id)['error']);
        $this->assertStringContains('1 not answered', $line);
    }

    public function testTheProviderIsCalledInBatchesAndPerRunIsHonoured(): void
    {
        $numbers = [];
        for ($i = 0; $i < 5; $i++) {
            $numbers[] = $this->number();
            $this->shipment(trackingNo: $numbers[$i]);
        }

        $provider = new FakeProvider(2);
        $line     = (new Refresh($provider, perRun: 3))->run(self::now());

        $this->assertStringContains('3 checked', $line);
        $this->assertCount(3, $provider->registered());
        $this->assertCount(3, $provider->fetched());

        // The other two are picked up on the next run.
        $provider->clearCalls();
        (new Refresh($provider, perRun: 3))->run(self::now()->modify('+5 minutes'));
        $this->assertCount(2, $provider->fetched());
    }

    /* ===================================================================== */
    /*  path()                                                               */
    /* ===================================================================== */

    public function testThePathAlongTheChain(): void
    {
        $cases = [
            // from, to, expected
            ['booked', 'picked_up', ['picked_up']],
            ['booked', 'in_transit', ['in_transit']],
            ['booked', 'customs', ['in_transit', 'customs']],
            ['booked', 'delivered', ['in_transit', 'out_for_delivery', 'delivered']],
            ['picked_up', 'customs', ['in_transit', 'customs']],
            ['in_transit', 'delivered', ['out_for_delivery', 'delivered']],
            ['customs', 'out_for_delivery', ['out_for_delivery']],
            ['out_for_delivery', 'delivered', ['delivered']],
            // Never backwards, never in place.
            ['customs', 'in_transit', []],
            ['out_for_delivery', 'customs', []],
            ['delivered', 'in_transit', []],
            ['in_transit', 'in_transit', []],
            // Into "needs attention" from anywhere the table allows; never out.
            ['booked', 'exception', ['exception']],
            ['delivered', 'exception', ['exception']],
            ['exception', 'delivered', []],
            ['exception', 'in_transit', []],
            ['exception', 'exception', []],
            // A status the table does not know is read as booked.
            ['', 'in_transit', ['in_transit']],
            ['nonsense', 'delivered', ['in_transit', 'out_for_delivery', 'delivered']],
            // A target that is not a status goes nowhere.
            ['booked', 'nonsense', []],
        ];

        foreach ($cases as [$from, $to, $expected]) {
            $this->assertSame($expected, Refresh::path($from, $to), $from . ' -> ' . $to . ' was ' . json_encode(Refresh::path($from, $to)));
        }
    }

    /* ===================================================================== */
    /*  the cron                                                             */
    /* ===================================================================== */

    public function testTheCronReportsTheTrackingJob(): void
    {
        $this->isolateHousekeeping();

        $number = $this->number();
        $this->shipment(trackingNo: $number);

        $result = $this->cron(new Refresh(new FakeProvider()))->run();

        $this->assertSame([], $result->failures);
        $this->assertTrue(array_key_exists('tracking', $result->jobs), 'the "tracking" job reported');
        $this->assertStringContains('1 checked', $result->jobs['tracking']);

        $off = $this->cron(null)->run();
        $this->assertSame('off', $off->jobs['tracking'] ?? null, 'no Refresh, reported off');
    }

    /* ===================================================================== */
    /*  helpers                                                              */
    /* ===================================================================== */

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'));
    }

    private function refresh(FakeProvider $provider): Refresh
    {
        return new Refresh($provider);
    }

    private function number(): string
    {
        return 'RT' . strtoupper(bin2hex(random_bytes(6)));
    }

    private function shipment(string $carrier = 'DHL', ?string $trackingNo = null): string
    {
        $shipment = $this->shipments->create(
            null,
            null,
            'Tracking test',
            '03001234567',
            'Receiver',
            'gb',
            'express',
            $carrier,
            $trackingNo ?? $this->number(),
            '',
            self::ADMIN,
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

    private function status(string $id): string
    {
        return (string) Db::value('SELECT status FROM shipments WHERE id = ?', [$id]);
    }

    /** @return list<array<string,mixed>> oldest first */
    private function events(string $id): array
    {
        return Db::all('SELECT status, `by` FROM shipment_events WHERE shipment_id = ? ORDER BY id ASC', [$id]);
    }

    /** @return list<Checkpoint> */
    private function scans(): array
    {
        $utc = new \DateTimeZone('UTC');

        return [
            new Checkpoint(new \DateTimeImmutable('2033-05-14 06:00:00', $utc), 'Lahore', 'PK', Stage::PICKED_UP, sha1($this->tag . 'a')),
            new Checkpoint(new \DateTimeImmutable('2033-05-15 09:00:00', $utc), 'Dubai', 'AE', Stage::IN_TRANSIT, sha1($this->tag . 'b')),
            new Checkpoint(new \DateTimeImmutable('2033-05-16 07:30:00', $utc), 'London', 'GB', Stage::OUT_FOR_DELIVERY, sha1($this->tag . 'c')),
        ];
    }

    /**
     * The cron's housekeeping sweeps the cache directory and prunes logs; both
     * are pointed somewhere harmless first, as CronTest does.
     */
    private function isolateHousekeeping(): void
    {
        $this->savedCachePath = Config::str('paths.cache');
        $this->savedLogDays   = Config::int('ops.log_days', 30);
        $this->cacheDir       = Paths::tmp() . '/' . $this->tag . '-cron-cache';

        Paths::ensureWritable($this->cacheDir);
        Config::set('paths.cache', $this->cacheDir);
        Config::set('ops.log_days', 36500);
    }

    /** Built the way CronTest builds one, with the Refresh as the last argument. */
    private function cron(?Refresh $refresh): Cron
    {
        $now    = self::now();
        $office = new RecordingOffice();

        return new Cron(
            $office->alerts($this->settingsKey('alerts')),
            new Counts($now),
            FakeVersions::publishedAt($now->modify('-1 day')->format('Y-m-d H:i:s'), 12),
            FakeHold::off(),
            new Housekeeping(new DailyOnce($this->settingsKey('ops')), $now),
            $now,
            $refresh,
        );
    }

    private function settingsKey(string $suffix): string
    {
        $key                  = substr($this->tag . '-' . $suffix, 0, 64);
        $this->settingsKeys[] = $key;

        return $key;
    }
}

