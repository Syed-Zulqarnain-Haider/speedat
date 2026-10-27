<?php

declare(strict_types=1);

namespace Speedat\Domain\Tracking;

use Speedat\Core\Clock;
use Speedat\Core\Config;
use Speedat\Core\Db;
use Speedat\Core\Log;
use Speedat\Domain\Shipments\InvalidTransition;
use Speedat\Domain\Shipments\ShipmentRepository;
use Speedat\Domain\Shipments\ShipmentStatus;

/**
 * The hourly tracking job: ask the carrier, keep the scans, move the desk.
 * Run by Ops\Cron as its `tracking` job.
 *
 * ORDER OF WORK, and why:
 *   1. pick the shipments that are due (TrackingRepository::due);
 *   2. register the ones the provider has not seen, or whose carrier or number
 *      the office has since corrected;
 *   3. fetch what the provider knows, for all of them, in batches;
 *   4. per shipment, ONE Db::transaction: store the new scans, move the status
 *      forward, stamp the tracking row.
 * Every HTTP call is made before any transaction opens, never inside one: a
 * transaction may be run twice (Db::transaction retries a deadlock) and an
 * HTTP call cannot be taken back.
 *
 * THE DESK ONLY MOVES FORWARD, AND NEVER OUT OF "NEEDS ATTENTION".
 *   - A scan can move a shipment along the chain - booked, picked up, in
 *     transit, customs, out for delivery, delivered - through whatever steps
 *     ShipmentStatus allows, each written as an event by "tracking".
 *   - It never moves one backwards. Carriers re-report old scans, and a parcel
 *     the office marked delivered must not go back to "in transit" because
 *     DHL's feed was late.
 *   - It may flag a shipment Needs attention (a return, a carrier exception),
 *     because that is what the badge on the desk is for. It never clears one:
 *     whether the problem is solved is the office's call.
 *
 * NOTHING HERE SENDS A MESSAGE. The WhatsApp update stays a button the office
 * presses; the customer sees new scans on /track the next time they look.
 */
final class Refresh
{
    /** Who the automatic events are by, on the desk timeline. */
    public const ACTOR = 'tracking';

    /** The default number of shipments one run looks at. One 17TRACK batch. */
    private const DEFAULT_PER_RUN = 40;

    /** The chain the automatic moves walk along, in order. */
    private const CHAIN = [
        ShipmentStatus::BOOKED,
        ShipmentStatus::PICKED_UP,
        ShipmentStatus::IN_TRANSIT,
        ShipmentStatus::CUSTOMS,
        ShipmentStatus::OUT_FOR_DELIVERY,
        ShipmentStatus::DELIVERED,
    ];

    public function __construct(
        private readonly ?TrackingProvider $provider,
        private readonly TrackingRepository $tracking = new TrackingRepository(),
        private readonly ShipmentRepository $shipments = new ShipmentRepository(),
        private readonly int $perRun = self::DEFAULT_PER_RUN,
    ) {
    }

    /** The provider config.php names, or none when no key is set. */
    public static function fromConfig(): self
    {
        $key = Config::str('tracking.api_key');

        $provider = null;
        if ($key !== '' && Config::str('tracking.provider', '17track') === '17track') {
            $provider = new SeventeenTrack($key, Config::str('tracking.base_url', SeventeenTrack::DEFAULT_BASE));
        }

        return new self($provider, perRun: max(1, Config::int('tracking.per_run', self::DEFAULT_PER_RUN)));
    }

    /** The line the cron log gets. */
    public function run(?\DateTimeImmutable $now = null): string
    {
        if ($this->provider === null) {
            return 'off (no tracking key in config.php)';
        }

        $now      = $now ?? Clock::utcNow();
        $provider = $this->provider;

        $due      = $this->tracking->due($this->perRun * 3, $now);
        $tracked  = [];
        $manual   = 0;

        foreach ($due as $row) {
            $code = Carriers::trackCode($row['carrier']);
            if ($code === null) {
                // A carrier no service tracks (TeamGlobal) or one we do not
                // recognise. Recorded, so the desk can say "tracked by hand",
                // and so it is not looked at again for an hour.
                $this->tracking->save($row['id'], '', 0, TrackingRepository::STATE_MANUAL, '', false, $now);
                $manual++;
                continue;
            }
            if (count($tracked) >= $this->perRun) {
                break;
            }
            $row['code'] = $code;
            $row['key']  = $provider->name() . ':' . $code . ':' . $row['tracking_no'];
            $tracked[]   = $row;
        }

        if ($tracked === []) {
            return 'nothing due' . ($manual > 0 ? ', ' . $manual . ' tracked by hand' : '');
        }

        // 2. Register what needs registering.
        $toRegister = array_values(array_filter(
            $tracked,
            static fn (array $r): bool => $r['key'] !== $r['track_key'] || !$r['registered'],
        ));

        $registerFailed = [];
        foreach (array_chunk($toRegister, $provider->batchSize()) as $chunk) {
            $answers = $provider->register(array_map(
                static fn (array $r): TrackRequest => new TrackRequest($r['tracking_no'], $r['code']),
                $chunk,
            ));
            foreach ($chunk as $r) {
                $error = $answers[$r['tracking_no']] ?? 'no answer from the tracking service';
                if ($error === '') {
                    $this->tracking->save($r['id'], $r['key'], $r['code'], TrackingRepository::STATE_REGISTERED, '', true, $now);
                } else {
                    $this->tracking->save($r['id'], $r['key'], $r['code'], TrackingRepository::STATE_ERROR, $error, false, $now);
                    $registerFailed[$r['id']] = true;
                }
            }
        }

        // 3. Fetch, for everything that is registered now.
        $toFetch = array_values(array_filter($tracked, static fn (array $r): bool => !isset($registerFailed[$r['id']])));

        $moved    = 0;
        $scans    = 0;
        $failures = count($registerFailed);

        foreach (array_chunk($toFetch, $provider->batchSize()) as $chunk) {
            $reports = $provider->fetch(array_map(
                static fn (array $r): TrackRequest => new TrackRequest($r['tracking_no'], $r['code']),
                $chunk,
            ));

            foreach ($chunk as $r) {
                $report = $reports[$r['tracking_no']] ?? TrackReport::failed('no answer from the tracking service');

                try {
                    [$added, $steps] = $this->apply($r, $report, $now);
                    $scans += $added;
                    $moved += $steps > 0 ? 1 : 0;
                    $failures += $report->error !== '' ? 1 : 0;
                } catch (\Throwable $e) {
                    // One shipment's bad answer must not stop the others.
                    Log::exception('tracking_apply_failed', $e, ['shipment' => $r['id']]);
                    $failures++;
                }
            }
        }

        return count($tracked) . ' checked, ' . $scans . ' new scan(s), ' . $moved . ' status change(s)'
            . ($failures > 0 ? ', ' . $failures . ' not answered' : '')
            . ($manual > 0 ? ', ' . $manual . ' tracked by hand' : '');
    }

    /**
     * Where the automatic moves go from `from` to reach `to`: the statuses to
     * write in order, or [] when the move should not be made.
     *
     * @return list<string>
     */
    public static function path(string $from, string $to): array
    {
        $from = ShipmentStatus::orBooked($from);

        if ($from === $to || $from === ShipmentStatus::EXCEPTION) {
            return [];
        }

        if ($to === ShipmentStatus::EXCEPTION) {
            return ShipmentStatus::canMove($from, $to) ? [$to] : [];
        }

        $fromAt = array_search($from, self::CHAIN, true);
        $toAt   = array_search($to, self::CHAIN, true);
        if ($fromAt === false || $toAt === false || $toAt <= $fromAt) {
            return [];
        }

        // Shortest route along the chain that the transition table allows,
        // never stepping backwards. Six statuses; a breadth-first walk is
        // the whole of it.
        $queue = [[$from]];
        $seen  = [$from => true];
        while ($queue !== []) {
            $route = array_shift($queue);
            $last  = $route[count($route) - 1];
            foreach (self::CHAIN as $i => $next) {
                if ($i <= (int) array_search($last, self::CHAIN, true) || $i > $toAt || isset($seen[$next])) {
                    continue;
                }
                if (!ShipmentStatus::canMove($last, $next)) {
                    continue;
                }
                $step = array_merge($route, [$next]);
                if ($next === $to) {
                    return array_slice($step, 1);
                }
                $seen[$next] = true;
                $queue[]     = $step;
            }
        }

        return [];
    }

    /**
     * Write one answer.
     *
     * @param array{id:string,key:string,code:int,status:string} $r
     * @return array{0:int,1:int} [new scans, status steps]
     */
    private function apply(array $r, TrackReport $report, \DateTimeImmutable $now): array
    {
        if ($report->error !== '') {
            $forgotten = $report->error === SeventeenTrack::ERROR_NOT_REGISTERED;
            $this->tracking->save(
                $r['id'],
                $r['key'],
                $r['code'],
                // A forgotten number is not a bad number: left in "registered"
                // (with registered_at cleared) it is due again next hour, not
                // held back the six hours a refused number waits.
                $forgotten ? TrackingRepository::STATE_REGISTERED : TrackingRepository::STATE_ERROR,
                $report->error,
                // The provider has forgotten the number: register it again
                // next time. Any other failure is a bad hour, not a bad number.
                $forgotten ? false : null,
                $now,
            );

            return [0, 0];
        }

        return Db::transaction(function () use ($r, $report, $now): array {
            // The row lock is the same one ShipmentRepository::addEvent takes,
            // so an admin pressing a status button at this moment waits for
            // this rather than racing it.
            $current = Db::value('SELECT status FROM shipments WHERE id = ? FOR UPDATE', [$r['id']]);
            if (!is_string($current)) {
                return [0, 0];
            }

            $added = $this->tracking->addCheckpoints($r['id'], $report->checkpoints);

            $steps = 0;
            $to    = $report->latest !== null ? Stage::toStatus($report->latest) : null;
            if ($to !== null) {
                foreach (self::path($current, $to) as $status) {
                    try {
                        $this->shipments->addEvent($r['id'], $status, '', self::ACTOR);
                        $steps++;
                    } catch (InvalidTransition) {
                        break;
                    }
                }
            }

            $delivered = $report->latest === Stage::DELIVERED;
            $this->tracking->save(
                $r['id'],
                $r['key'],
                $report->carrierCode > 0 ? $report->carrierCode : $r['code'],
                $delivered ? TrackingRepository::STATE_DONE : TrackingRepository::STATE_ACTIVE,
                '',
                null,
                $now,
            );

            return [$added, $steps];
        });
    }
}
