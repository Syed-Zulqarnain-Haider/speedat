<?php

declare(strict_types=1);

namespace Speedat\Domain\Shipments;

/**
 * The WhatsApp update a customer receives about a shipment. Port of
 * src/components/admin/shipmentMessage.ts, wording included.
 *
 * Four lines, any of which may be absent:
 *   1. the greeting, naming the company, the shipment and the DESTINATION;
 *   2. the canned sentence for the current status;
 *   3. the note the admin wrote with that status change;
 *   4. the tracking number, with the carrier in brackets when there is one.
 *
 * Two rules that look like details and are not:
 *
 * THE NOTE COMES FROM THE LATEST EVENT, AND ONLY IF THAT EVENT IS THE CURRENT
 * STATUS. Otherwise "Handed to Emirates flight EK623", written when the parcel
 * left, would travel out again under "Your shipment is with customs" a week
 * later, and the customer would reasonably conclude it had been put back on a
 * plane.
 *
 * THE DESTINATION IS A NAME, NEVER AN ID. The shipment row stores "gb"; this
 * builder is handed "United Kingdom". If the caller passes an id it will be
 * sent to the customer verbatim, which is precisely the bug the Next.js admin
 * had - see NameLookup, which exists to make sure the name is available even
 * for a country that has since been taken off the rate card.
 *
 * Pure: no database, no clock, no configuration. The same text comes out in the
 * admin, in a test and anywhere else that asks.
 */
final class CustomerMessage
{
    /**
     * @param list<ShipmentEvent>|list<array{status: string, note: string}> $events
     *        the timeline, OLDEST FIRST - the order getShipment returns
     */
    public static function build(
        string $companyName,
        string $shipmentId,
        string $customerName,
        string $destinationName,
        string $status,
        string $trackingNo,
        string $carrier,
        array $events,
    ): string {
        $status = ShipmentStatus::orBooked($status);

        $lines = [
            'Hi' . ($customerName !== '' ? ' ' . $customerName : '')
                . ', an update from ' . $companyName
                . ' on your shipment ' . $shipmentId
                . ' to ' . $destinationName . ':',
            ShipmentStatus::customerText($status),
            self::noteFor($status, $events),
            $trackingNo !== ''
                ? 'Tracking number: ' . $trackingNo . ($carrier !== '' ? ' (' . $carrier . ')' : '')
                : '',
        ];

        // filter(Boolean) in the TypeScript: an absent note or tracking number
        // leaves no blank line behind it.
        return implode("\n", array_values(array_filter($lines, static fn (string $l): bool => $l !== '')));
    }

    /** The same message, from the records the repository hands back. */
    public static function forShipment(
        string $companyName,
        Shipment $shipment,
        string $destinationName,
        ShipmentHistory $history,
    ): string {
        return self::build(
            $companyName,
            $shipment->id,
            $shipment->customerName,
            $destinationName,
            $shipment->status,
            $shipment->trackingNo ?? '',
            $shipment->carrier,
            $history->events,
        );
    }

    /**
     * The note on the newest event, but only when that event carries the
     * current status.
     *
     * @param list<ShipmentEvent>|list<array{status: string, note: string}> $events
     */
    private static function noteFor(string $status, array $events): string
    {
        if ($events === []) {
            return '';
        }

        $latest = $events[count($events) - 1];

        [$eventStatus, $note] = $latest instanceof ShipmentEvent
            ? [$latest->status, $latest->note]
            : [(string) ($latest['status'] ?? ''), (string) ($latest['note'] ?? '')];

        return ShipmentStatus::orBooked($eventStatus) === $status ? trim($note) : '';
    }
}
