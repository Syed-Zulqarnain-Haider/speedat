<?php

declare(strict_types=1);

namespace Speedat\Domain\Tracking;

use Speedat\Core\Caps;
use Speedat\Core\Clock;
use Speedat\Core\Db;

/**
 * The two tracking tables (db/migrations/0004_tracking.sql). The only class in
 * this folder that talks to the database.
 */
final class TrackingRepository
{
    public const STATE_REGISTERED = 'registered';
    public const STATE_ACTIVE     = 'active';
    public const STATE_DONE       = 'done';
    public const STATE_ERROR      = 'error';
    public const STATE_MANUAL     = 'manual';

    /**
     * Shipments the hourly job should look at, least recently checked first.
     *
     * In: a carrier and a tracking number are both filled in; the shipment was
     * created in the last 120 days (a parcel still moving after four months is
     * a phone call, not a poll); it has not finished tracking; and it was not
     * checked in the last 50 minutes, so a second cron run in the same hour
     * costs nothing. A number 17TRACK refused is retried every six hours, not
     * every hour - it will usually only come good when the office fixes it.
     *
     * Delivered shipments are still in: the cron that sees "delivered" first
     * is the one that marks tracking done, and a parcel the office marked
     * delivered by hand still deserves its last scans on the timeline.
     *
     * @return list<array{id:string,carrier:string,tracking_no:string,status:string,track_key:string,carrier_code:int,state:string,registered:bool}>
     */
    public function due(int $limit, ?\DateTimeImmutable $now = null): array
    {
        $now   = $now ?? Clock::utcNow();
        $limit = max(1, min(500, $limit));

        $rows = Db::all(
            "SELECT s.id, s.carrier, s.tracking_no, s.status,
                    t.track_key, t.carrier_code, t.state, t.registered_at
               FROM shipments s
          LEFT JOIN shipment_tracking t ON t.shipment_id = s.id
              WHERE s.tracking_no IS NOT NULL AND s.tracking_no <> ''
                AND s.carrier <> ''
                AND s.created_at >= ?
                AND (t.state IS NULL OR t.state <> 'done')
                AND (t.checked_at IS NULL
                     OR (t.state <> 'error' AND t.checked_at < ?)
                     OR (t.state = 'error' AND t.checked_at < ?))
           ORDER BY (t.checked_at IS NULL) DESC, t.checked_at ASC, s.created_at DESC
              LIMIT " . $limit,
            [
                Clock::sql($now->modify('-120 days')),
                Clock::sql($now->modify('-50 minutes')),
                Clock::sql($now->modify('-6 hours')),
            ],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'           => (string) $row['id'],
                'carrier'      => (string) $row['carrier'],
                'tracking_no'  => (string) $row['tracking_no'],
                'status'       => (string) $row['status'],
                'track_key'    => (string) ($row['track_key'] ?? ''),
                'carrier_code' => (int) ($row['carrier_code'] ?? 0),
                'state'        => (string) ($row['state'] ?? ''),
                'registered'   => ($row['registered_at'] ?? null) !== null,
            ];
        }

        return $out;
    }

    /**
     * Write a shipment's tracking row, creating it the first time.
     *
     * @param bool|null $registered true stamps registered_at now, false clears
     *                              it (the provider must be asked again), null
     *                              leaves it as it is
     */
    public function save(
        string $shipmentId,
        string $trackKey,
        int $carrierCode,
        string $state,
        string $error,
        ?bool $registered,
        ?\DateTimeImmutable $now = null,
    ): void {
        $at = Clock::sql($now);

        // registered_at: the new value on an insert, and on an update either
        // the new value, NULL, or the column left alone. Only fixed SQL text is
        // chosen here; every value is bound.
        $onUpdate = match ($registered) {
            true  => 'VALUES(registered_at)',
            false => 'NULL',
            null  => 'registered_at',
        };

        Db::run(
            'INSERT INTO shipment_tracking (shipment_id, track_key, carrier_code, state, error, registered_at, checked_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE track_key = VALUES(track_key), carrier_code = VALUES(carrier_code),
                                     state = VALUES(state), error = VALUES(error),
                                     registered_at = ' . $onUpdate . ', checked_at = VALUES(checked_at)',
            [
                $shipmentId,
                Caps::fit('shipment_tracking.track_key', $trackKey),
                max(0, $carrierCode),
                Caps::fit('shipment_tracking.state', $state),
                Caps::fit('shipment_tracking.error', $error),
                $registered === true ? $at : null,
                $at,
            ],
        );
    }

    /**
     * Store the scans that are new. A scan already stored (same fingerprint)
     * is left as it is, so the hourly call that repeats yesterday's answer
     * writes nothing.
     *
     * @param list<Checkpoint> $checkpoints
     * @return int how many were new
     */
    public function addCheckpoints(string $shipmentId, array $checkpoints): int
    {
        $added = 0;

        foreach ($checkpoints as $cp) {
            $added += Db::exec(
                'INSERT INTO tracking_checkpoints (shipment_id, at, city, country, stage, fingerprint)
                      VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE id = id',
                [
                    $shipmentId,
                    Clock::sql($cp->at),
                    Caps::fit('tracking_checkpoints.city', $cp->city),
                    Checkpoint::country($cp->country),
                    Stage::isValid($cp->stage) ? $cp->stage : Stage::IN_TRANSIT,
                    Caps::fit('tracking_checkpoints.fingerprint', $cp->fingerprint),
                ],
            ) > 0 ? 1 : 0;
        }

        return $added;
    }

    /** @return list<Checkpoint> oldest first */
    public function checkpoints(string $shipmentId, int $limit = 100): array
    {
        $rows = Db::all(
            'SELECT at, city, country, stage, fingerprint FROM tracking_checkpoints
              WHERE shipment_id = ? ORDER BY at DESC, id DESC LIMIT ' . max(1, min(500, $limit)),
            [$shipmentId],
        );

        $out = [];
        foreach (array_reverse($rows) as $row) {
            $at = Clock::fromSql((string) $row['at']);
            if ($at === null) {
                continue;
            }
            $out[] = new Checkpoint(
                $at->setTimezone(new \DateTimeZone('UTC')),
                (string) $row['city'],
                (string) $row['country'],
                (string) $row['stage'],
                (string) $row['fingerprint'],
            );
        }

        return $out;
    }

    /** @return array{state:string,error:string,checked_at:?\DateTimeImmutable}|null */
    public function state(string $shipmentId): ?array
    {
        $row = Db::one('SELECT state, error, checked_at FROM shipment_tracking WHERE shipment_id = ?', [$shipmentId]);

        if ($row === null) {
            return null;
        }

        return [
            'state'      => (string) $row['state'],
            'error'      => (string) $row['error'],
            'checked_at' => Clock::fromSql(isset($row['checked_at']) ? (string) $row['checked_at'] : null),
        ];
    }
}
