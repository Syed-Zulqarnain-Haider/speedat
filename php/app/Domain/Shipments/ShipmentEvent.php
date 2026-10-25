<?php

declare(strict_types=1);

namespace Speedat\Domain\Shipments;

use Speedat\Core\Clock;

/**
 * One line of a shipment's history: a status, the note the admin wrote with it,
 * when, and who by.
 *
 * The events are the record, not a derived view of one. A shipment's `status`
 * column is only ever the status of its most recent event, so the timeline and
 * the headline cannot disagree - and the WhatsApp update a customer receives is
 * built from the timeline, so what they read is exactly what the office wrote.
 *
 * `by` IS A RESERVED WORD in MySQL and the column keeps the name anyway, to
 * match the Postgres schema this was ported from. Every statement that touches
 * it writes it in backticks; see ShipmentRepository.
 */
final class ShipmentEvent
{
    public function __construct(
        public readonly int $id,
        public readonly string $shipmentId,
        /** One of ShipmentStatus::ALL. */
        public readonly string $status,
        public readonly string $note,
        public readonly \DateTimeImmutable $at,
        /** The admin's email, or "system". */
        public readonly string $by,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) ($row['id'] ?? 0),
            shipmentId: (string) ($row['shipment_id'] ?? ''),
            status: ShipmentStatus::orBooked((string) ($row['status'] ?? '')),
            note: (string) ($row['note'] ?? ''),
            at: Clock::fromSql(isset($row['at']) ? (string) $row['at'] : null) ?? Clock::now(),
            by: (string) ($row['by'] ?? ''),
        );
    }

    public function statusLabel(): string
    {
        return ShipmentStatus::label($this->status);
    }
}
