<?php

declare(strict_types=1);

namespace Speedat\Domain\Tracking;

use Speedat\Core\Config;
use Speedat\Domain\Shipments\ShipmentEvent;
use Speedat\Domain\Shipments\ShipmentStatus;

/**
 * What /track may show a stranger who holds a shipment number, and nothing more.
 *
 * THE WHOLE PRIVACY PROMISE OF THE PAGE IS IN THIS CLASS. Its input is the
 * shipment's events and the carrier's checkpoints; its output is a list of
 * rows that each hold a time, a place and a label - and the label is always one
 * of OUR words (ShipmentStatus::label or Stage::label), never text a person
 * typed. In particular:
 *   - the office's event NOTE is never read. It goes out on WhatsApp to the
 *     customer it is about; on a public page it could name anyone;
 *   - the carrier's description never got this far (see Stage);
 *   - nothing about the customer, the receiver or their phone is passed in,
 *     so nothing about them can come out.
 *
 * Events the tracking job wrote itself (by "tracking") are left out: they are
 * the same moment as the scan that caused them, and the scan says it better
 * because it has a place.
 */
final class PublicTimeline
{
    /**
     * @param list<ShipmentEvent> $events      oldest first
     * @param list<Checkpoint>    $checkpoints oldest first
     * @return list<array{at:\DateTimeImmutable,label:string,place:string,stage:string}> newest first
     */
    public static function rows(array $events, array $checkpoints): array
    {
        $rows = [];

        foreach ($events as $event) {
            if ($event->by === Refresh::ACTOR) {
                continue;
            }
            $status = ShipmentStatus::orBooked($event->status);
            $rows[] = [
                'at'    => $event->at,
                'label' => ShipmentStatus::label($status),
                'place' => '',
                'stage' => $status,
            ];
        }

        foreach ($checkpoints as $cp) {
            $place = trim($cp->city . ($cp->city !== '' && $cp->country !== '' ? ', ' : '') . $cp->country);
            $rows[] = [
                'at'    => $cp->at,
                'label' => Stage::label($cp->stage),
                'place' => $place,
                'stage' => $cp->stage,
            ];
        }

        // Newest first; on a tie the carrier's scan (added later) goes first,
        // which is the more specific of two rows for the same minute.
        $keyed = [];
        foreach ($rows as $i => $row) {
            $keyed[] = [$row['at']->getTimestamp(), $i, $row];
        }
        usort($keyed, static fn (array $a, array $b): int => [$b[0], $b[1]] <=> [$a[0], $a[1]]);

        return array_map(static fn (array $k): array => $k[2], $keyed);
    }

    /** The public link for a shipment, or '' when config.php has no app.url. */
    public static function link(string $shipmentId, ?string $baseUrl = null): string
    {
        $base = rtrim($baseUrl ?? Config::str('app.url'), '/');

        return $base === '' ? '' : $base . '/track?id=' . rawurlencode($shipmentId);
    }
}
