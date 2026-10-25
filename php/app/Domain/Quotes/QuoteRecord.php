<?php

declare(strict_types=1);

namespace Speedat\Domain\Quotes;

use Speedat\Core\Clock;

/**
 * A logged quote: what the customer was shown, as the SERVER computed it.
 *
 * `total` is whole rupees in an INT column. Money that has been through a float
 * is money that disagrees with the printed sheet, so the engine's rounded
 * figure is turned into an integer once, here, at the boundary.
 *
 * `detail` carries everything that is descriptive rather than structural: the
 * pieces as text, the ETA, the add-ons with their amounts as the published
 * settings define them, and - when the browser's arithmetic disagreed with the
 * engine's - what the browser claimed. That last part is the audit trail for a
 * tampered request: the row holds the right price and a note saying what was
 * asked for instead.
 *
 * There is no shipment type, although `quotes.type` is still a column: the
 * document rate left the product on 2026-09-27 and nothing reads the column any
 * more. QuoteRepository writes a constant into it until a migration can drop it.
 */
final class QuoteRecord
{
    /**
     * @param array<string,mixed> $detail
     */
    public function __construct(
        public readonly string $id,
        public readonly \DateTimeImmutable $createdAt,
        /** Which published version priced it. */
        public readonly int $version,
        public readonly string $destId,
        public readonly string $serviceId,
        public readonly int $billableG,
        /** Shipping plus every add-on that was on, in whole units of the currency. */
        public readonly int $total,
        public readonly array $detail,
        public readonly bool $booked,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        $detail = $row['detail'] ?? null;
        if (is_string($detail)) {
            $decoded = json_decode($detail, true);
            $detail  = is_array($decoded) ? $decoded : [];
        }

        return new self(
            id: (string) ($row['id'] ?? ''),
            createdAt: Clock::fromSql(isset($row['created_at']) ? (string) $row['created_at'] : null) ?? Clock::now(),
            version: (int) ($row['version'] ?? 0),
            destId: (string) ($row['dest_id'] ?? ''),
            serviceId: (string) ($row['service_id'] ?? ''),
            billableG: (int) ($row['billable_g'] ?? 0),
            total: (int) ($row['total'] ?? 0),
            detail: is_array($detail) ? $detail : [],
            booked: (bool) ($row['booked'] ?? false),
        );
    }

    /** Shipping before add-ons, as the engine computed it. */
    public function shipping(): float
    {
        return isset($this->detail['shipping']) && is_numeric($this->detail['shipping'])
            ? (float) $this->detail['shipping']
            : (float) $this->total;
    }

    /** True when the browser's numbers did not match the engine's. */
    public function wasTampered(): bool
    {
        return array_key_exists('clientTotal', $this->detail)
            || array_key_exists('clientBillableG', $this->detail);
    }
}
