<?php

declare(strict_types=1);

namespace Speedat\Domain\Tracking;

use Speedat\Domain\Shipments\ShipmentStatus;

/**
 * What a carrier scan means, in our own words.
 *
 * A carrier's event text is never shown on /track. It is the carrier's prose -
 * "Delivered - signed for by AHMED", "Arrived at DHL facility, 14 Main Blvd" -
 * and it carries names and streets often enough that the page cannot print it
 * and promise the customer it shows "city and status only". So every scan is
 * reduced to one of the words below, and the customer reads the label.
 *
 * TWO VOCABULARIES, ON PURPOSE. A Stage is finer than a ShipmentStatus: "ready
 * for collection" and "delivery attempted" are real things a carrier says that
 * the office's seven statuses have no word for, and the customer deserves to
 * read them. toStatus() is where the two meet, for the cron's automatic status
 * moves, and it returns null for the stages that should not move the desk.
 */
final class Stage
{
    public const INFO_RECEIVED    = 'info_received';
    public const PICKED_UP        = 'picked_up';
    public const IN_TRANSIT       = 'in_transit';
    public const CUSTOMS          = 'customs';
    public const OUT_FOR_DELIVERY = 'out_for_delivery';
    public const COLLECTION       = 'collection';
    public const ATTEMPTED        = 'attempted';
    public const DELIVERED        = 'delivered';
    public const RETURNING        = 'returning';
    public const EXCEPTION        = 'exception';

    /** @var list<string> */
    public const ALL = [
        self::INFO_RECEIVED,
        self::PICKED_UP,
        self::IN_TRANSIT,
        self::CUSTOMS,
        self::OUT_FOR_DELIVERY,
        self::COLLECTION,
        self::ATTEMPTED,
        self::DELIVERED,
        self::RETURNING,
        self::EXCEPTION,
    ];

    /** What the customer reads on /track. No em dashes: house style. */
    public const LABEL = [
        self::INFO_RECEIVED    => 'Booked with the carrier',
        self::PICKED_UP        => 'Collected by the carrier',
        self::IN_TRANSIT       => 'In transit',
        self::CUSTOMS          => 'At customs',
        self::OUT_FOR_DELIVERY => 'Out for delivery',
        self::COLLECTION       => 'Ready for collection',
        self::ATTEMPTED        => 'Delivery attempted',
        self::DELIVERED        => 'Delivered',
        self::RETURNING        => 'Being returned',
        self::EXCEPTION        => 'Held up',
    ];

    /**
     * The desk status a stage moves a shipment to, or null for "leave it".
     *
     * Attempted and ready-for-collection move nothing: the parcel is where it
     * was, waiting, and the office would rather see "Out for delivery" than a
     * "Needs attention" flag for a doorbell nobody answered. Returning does
     * flag it - a parcel coming back is always something to ring about.
     */
    private const STATUS = [
        self::INFO_RECEIVED    => ShipmentStatus::BOOKED,
        self::PICKED_UP        => ShipmentStatus::PICKED_UP,
        self::IN_TRANSIT       => ShipmentStatus::IN_TRANSIT,
        self::CUSTOMS          => ShipmentStatus::CUSTOMS,
        self::OUT_FOR_DELIVERY => ShipmentStatus::OUT_FOR_DELIVERY,
        self::COLLECTION       => null,
        self::ATTEMPTED        => null,
        self::DELIVERED        => ShipmentStatus::DELIVERED,
        self::RETURNING        => ShipmentStatus::EXCEPTION,
        self::EXCEPTION        => ShipmentStatus::EXCEPTION,
    ];

    public static function isValid(string $stage): bool
    {
        return in_array($stage, self::ALL, true);
    }

    public static function label(string $stage): string
    {
        return self::LABEL[$stage] ?? self::LABEL[self::IN_TRANSIT];
    }

    public static function toStatus(string $stage): ?string
    {
        return self::STATUS[$stage] ?? null;
    }
}
