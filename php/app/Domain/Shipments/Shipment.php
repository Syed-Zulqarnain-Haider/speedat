<?php

declare(strict_types=1);

namespace Speedat\Domain\Shipments;

use Speedat\Core\Clock;

/**
 * A booked job on its way somewhere. One row of the shipments table.
 *
 * `destId` and `serviceId` are IDS. They are never the names, and the names are
 * not stored alongside them, because a rate card is republished every time an
 * airline sends a sheet and a copied name would be a copy of whatever it was
 * called that week. Turning an id into a name is NameLookup's job, and it has
 * to be done every time the record is shown - including for a country the owner
 * has since removed from the rates, which is the case the Next.js app got wrong
 * and printed "gb" to the customer.
 */
final class Shipment
{
    public function __construct(
        public readonly string $id,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
        public readonly ?int $leadId,
        public readonly ?string $quoteId,
        public readonly ?string $trackingNo,
        public readonly string $carrier,
        public readonly string $customerName,
        public readonly string $customerPhone,
        public readonly string $receiverName,
        /** An id from the rate card, not a country name. */
        public readonly string $destId,
        /** An id from the rate card, not a service name. */
        public readonly string $serviceId,
        /** One of ShipmentStatus::ALL. */
        public readonly string $status,
        public readonly string $notes,
        public readonly string $createdBy,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: self::text($row['id'] ?? '') ?? '',
            createdAt: Clock::fromSql(self::text($row['created_at'] ?? null)) ?? Clock::now(),
            updatedAt: Clock::fromSql(self::text($row['updated_at'] ?? null)) ?? Clock::now(),
            leadId: isset($row['lead_id']) && $row['lead_id'] !== null ? (int) $row['lead_id'] : null,
            quoteId: self::text($row['quote_id'] ?? null),
            trackingNo: self::text($row['tracking_no'] ?? null),
            carrier: self::text($row['carrier'] ?? '') ?? '',
            customerName: self::text($row['customer_name'] ?? '') ?? '',
            customerPhone: self::text($row['customer_phone'] ?? '') ?? '',
            receiverName: self::text($row['receiver_name'] ?? '') ?? '',
            destId: self::text($row['dest_id'] ?? '') ?? '',
            serviceId: self::text($row['service_id'] ?? '') ?? '',
            status: ShipmentStatus::orBooked(self::text($row['status'] ?? '') ?? ''),
            notes: self::text($row['notes'] ?? '') ?? '',
            createdBy: self::text($row['created_by'] ?? '') ?? '',
        );
    }

    public function statusLabel(): string
    {
        return ShipmentStatus::label($this->status);
    }

    public function isOpen(): bool
    {
        return ShipmentStatus::isOpen($this->status);
    }

    private static function text(mixed $v): ?string
    {
        return $v === null ? null : (is_scalar($v) ? (string) $v : null);
    }
}
