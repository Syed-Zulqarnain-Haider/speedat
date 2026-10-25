<?php

declare(strict_types=1);

namespace Speedat\Domain\Shipments;

/**
 * Where a shipment has got to, what the admin calls it, what the customer is
 * told, and which move is allowed next.
 *
 * The seven statuses and both sets of wording are a character-for-character
 * port of src/lib/shipments.ts and src/components/admin/shipmentMessage.ts.
 * Do not reword the customer sentences: they are what goes out on WhatsApp and
 * the office knows them by heart.
 *
 * THE TRANSITION TABLE IS NEW AND IS NOT A PORT. The Next.js app let any status
 * follow any status - `addShipmentEvent` took whatever the form posted - so a
 * mis-click could mark a parcel Delivered before it had been collected, and the
 * customer got "Your shipment has been delivered." about a box still on the
 * shelf. There is no undo for a sent WhatsApp message, so the moves are
 * enumerated here and anything else is refused.
 *
 * How to read the table:
 *   - forward along the chain, and one legitimate skip: a shipment can go from
 *     booked straight to in_transit when it is handed to the airline the same
 *     visit and nobody stopped to press Picked up;
 *   - backwards only where the world actually goes backwards: customs can send
 *     a parcel back onto a plane, and a failed delivery goes back to customs
 *     or out again tomorrow;
 *   - exception is reachable from everywhere, including from delivered, because
 *     a problem can surface after the fact and the office must be able to
 *     record it;
 *   - from exception every working status is reachable, because an exception is
 *     a pause, not a place in the chain;
 *   - a status may always be re-asserted. "Still at customs, day three" is a
 *     real event with a real note, and it is how the office keeps a customer
 *     informed without inventing a status change that did not happen.
 */
final class ShipmentStatus
{
    public const BOOKED           = 'booked';
    public const PICKED_UP        = 'picked_up';
    public const IN_TRANSIT       = 'in_transit';
    public const CUSTOMS          = 'customs';
    public const OUT_FOR_DELIVERY = 'out_for_delivery';
    public const DELIVERED        = 'delivered';
    public const EXCEPTION        = 'exception';

    /** Every status, in the order src/lib/shipments.ts lists them. @var list<string> */
    public const ALL = [
        self::BOOKED,
        self::PICKED_UP,
        self::IN_TRANSIT,
        self::CUSTOMS,
        self::OUT_FOR_DELIVERY,
        self::DELIVERED,
        self::EXCEPTION,
    ];

    /** What the admin reads. Port of STATUS_LABEL. */
    public const LABEL = [
        self::BOOKED           => 'Booked',
        self::PICKED_UP        => 'Picked up',
        self::IN_TRANSIT       => 'In transit',
        self::CUSTOMS          => 'At customs',
        self::OUT_FOR_DELIVERY => 'Out for delivery',
        self::DELIVERED        => 'Delivered',
        self::EXCEPTION        => 'Needs attention',
    ];

    /** What the customer reads on WhatsApp. Port of STATUS_CUSTOMER_TEXT. */
    public const CUSTOMER_TEXT = [
        self::BOOKED           => 'Your shipment is booked. We will collect it and hand it to the airline.',
        self::PICKED_UP        => 'We have collected your shipment and it is being prepared for the flight.',
        self::IN_TRANSIT       => 'Your shipment is on its way to the destination country.',
        self::CUSTOMS          => 'Your shipment is with customs at the destination. This can take a day or two.',
        self::OUT_FOR_DELIVERY => 'Your shipment is out for delivery today.',
        self::DELIVERED        => 'Your shipment has been delivered.',
        self::EXCEPTION        => 'There is a hold-up with your shipment. We are on it and will update you shortly.',
    ];

    /**
     * Where each status may go next, not counting itself.
     *
     * @var array<string,list<string>>
     */
    public const TRANSITIONS = [
        self::BOOKED           => [self::PICKED_UP, self::IN_TRANSIT, self::EXCEPTION],
        self::PICKED_UP        => [self::IN_TRANSIT, self::EXCEPTION],
        self::IN_TRANSIT       => [self::CUSTOMS, self::OUT_FOR_DELIVERY, self::EXCEPTION],
        self::CUSTOMS          => [self::IN_TRANSIT, self::OUT_FOR_DELIVERY, self::EXCEPTION],
        self::OUT_FOR_DELIVERY => [self::CUSTOMS, self::DELIVERED, self::EXCEPTION],
        self::DELIVERED        => [self::EXCEPTION],
        self::EXCEPTION        => [
            self::PICKED_UP,
            self::IN_TRANSIT,
            self::CUSTOMS,
            self::OUT_FOR_DELIVERY,
            self::DELIVERED,
        ],
    ];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    /**
     * A stored status narrowed to one we know; anything else reads as booked.
     * Port of `currentStatus` in shipmentMessage.ts.
     */
    public static function orBooked(string $status): string
    {
        return self::isValid($status) ? $status : self::BOOKED;
    }

    public static function label(string $status): string
    {
        return self::LABEL[self::orBooked($status)];
    }

    public static function customerText(string $status): string
    {
        return self::CUSTOMER_TEXT[self::orBooked($status)];
    }

    /** Whether one status may follow another. Re-asserting the same one is allowed. */
    public static function canMove(string $from, string $to): bool
    {
        if (!self::isValid($from) || !self::isValid($to)) {
            return false;
        }
        if ($from === $to) {
            return true;
        }

        return in_array($to, self::TRANSITIONS[$from], true);
    }

    /**
     * Everything the admin may choose next, including staying put, in the
     * canonical order so the dropdown never reshuffles itself.
     *
     * @return list<string>
     */
    public static function nextFrom(string $from): array
    {
        $from = self::orBooked($from);

        return array_values(array_filter(self::ALL, static fn (string $s): bool => self::canMove($from, $s)));
    }

    /** A shipment still on the desk: anything that is not delivered. */
    public static function isOpen(string $status): bool
    {
        return self::orBooked($status) !== self::DELIVERED;
    }
}
