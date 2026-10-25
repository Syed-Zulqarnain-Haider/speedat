<?php

declare(strict_types=1);

namespace Speedat\Domain\Shipments;

/**
 * A shipment and its timeline, the pair every detail page needs. Port of the
 * `{ shipment, events }` shape `getShipment` returns in src/lib/shipments.ts.
 *
 * The events are OLDEST FIRST, which is the order the page prints them in and
 * the order CustomerMessage expects: it reads the last entry to decide whether
 * the note belongs under the current headline.
 */
final class ShipmentHistory
{
    /** @param list<ShipmentEvent> $events oldest first */
    public function __construct(
        public readonly Shipment $shipment,
        public readonly array $events,
    ) {
    }

    public function latest(): ?ShipmentEvent
    {
        return $this->events === [] ? null : $this->events[count($this->events) - 1];
    }

    /** The note that belongs with the shipment's current status, or ''. */
    public function currentNote(): string
    {
        $latest = $this->latest();

        return $latest !== null && $latest->status === $this->shipment->status ? trim($latest->note) : '';
    }
}
