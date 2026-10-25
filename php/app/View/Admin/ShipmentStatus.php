<?php

declare(strict_types=1);

namespace Speedat\View\Admin;

/**
 * The seven states a shipment moves through, what the office calls each one,
 * what the customer is told, and the WhatsApp update built from the two.
 * Port of src/components/admin/shipmentMessage.ts, word for word - the office
 * has been sending these sentences for months and a reworded one reads as a
 * different company.
 */
final class ShipmentStatus
{
    /** @var list<string> in the order the select offers them */
    public const ALL = ['booked', 'picked_up', 'in_transit', 'customs', 'out_for_delivery', 'delivered', 'exception'];

    /** @var array<string,string> */
    public const LABEL = [
        'booked'           => 'Booked',
        'picked_up'        => 'Picked up',
        'in_transit'       => 'In transit',
        'customs'          => 'At customs',
        'out_for_delivery' => 'Out for delivery',
        'delivered'        => 'Delivered',
        'exception'        => 'Needs attention',
    ];

    /** @var array<string,string> what the customer reads for each state */
    public const CUSTOMER_TEXT = [
        'booked'           => 'Your shipment is booked. We will collect it and hand it to the airline.',
        'picked_up'        => 'We have collected your shipment and it is being prepared for the flight.',
        'in_transit'       => 'Your shipment is on its way to the destination country.',
        'customs'          => 'Your shipment is with customs at the destination. This can take a day or two.',
        'out_for_delivery' => 'Your shipment is out for delivery today.',
        'delivered'        => 'Your shipment has been delivered.',
        'exception'        => 'There is a hold-up with your shipment. We are on it and will update you shortly.',
    ];

    /** A stored status narrowed to one we know; anything else reads as booked. */
    public static function current(string $status): string
    {
        return in_array($status, self::ALL, true) ? $status : 'booked';
    }

    public static function label(string $status): string
    {
        return self::LABEL[$status] ?? $status;
    }

    /** @return array<string,string> value => label, for the status select */
    public static function options(): array
    {
        return self::LABEL;
    }

    /**
     * The customer's update: greeting, the sentence for the current status,
     * the note the admin wrote with that status change, then the tracking
     * line.
     *
     * The note is read from the LAST timeline entry and only when that entry
     * is the current status, so a note written for an earlier status never
     * travels under a newer headline.
     *
     * @param array{id:string,customerName:string,destination:string,status:string,trackingNo:string,carrier:string} $shipment
     * @param list<array{status:string,note:string}>                                                                 $events oldest first
     */
    public static function customerMessage(string $companyName, array $shipment, array $events): string
    {
        $status = self::current($shipment['status']);
        $latest = $events === [] ? null : $events[count($events) - 1];
        $note   = $latest !== null && self::current($latest['status']) === $status ? trim($latest['note']) : '';

        $parts = [
            'Hi' . ($shipment['customerName'] !== '' ? ' ' . $shipment['customerName'] : '')
                . ', an update from ' . $companyName . ' on your shipment ' . $shipment['id']
                . ' to ' . $shipment['destination'] . ':',
            self::CUSTOMER_TEXT[$status],
            $note,
            $shipment['trackingNo'] !== ''
                ? 'Tracking number: ' . $shipment['trackingNo'] . ($shipment['carrier'] !== '' ? ' (' . $shipment['carrier'] . ')' : '')
                : '',
        ];

        return implode("\n", array_values(array_filter($parts, static fn (string $p): bool => $p !== '')));
    }
}
