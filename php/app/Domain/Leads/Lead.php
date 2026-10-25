<?php

declare(strict_types=1);

namespace Speedat\Domain\Leads;

use Speedat\Core\Clock;

/**
 * One row of the inbox: somebody who wants to ship something.
 *
 * Immutable. The repository is the only thing that builds one, and it builds it
 * from a database row; nothing else needs a constructor that could disagree
 * with the column widths.
 *
 * `destId` is an ID, never a name. A destination the owner later removes from
 * the rates still has to print as "United Kingdom" and not as "gb" on a lead
 * booked under it - that lookup is Shipments\NameLookup's job, and it is done
 * where the lead is displayed, not stored here, because the answer changes
 * every time the rates are published.
 */
final class Lead
{
    public function __construct(
        public readonly int $id,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
        /** LeadKind::QUOTE or LeadKind::MESSAGE. */
        public readonly string $kind,
        public readonly ?string $quoteId,
        public readonly string $name,
        public readonly string $phone,
        public readonly string $email,
        public readonly string $message,
        public readonly ?string $destId,
        public readonly ?int $weightG,
        /** One of LeadStatus::ALL. */
        public readonly string $status,
        public readonly string $notes,
        /** The admin who last changed it; '' while nobody has touched it. */
        public readonly string $updatedBy,
        public readonly ?string $ipHash,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            createdAt: Clock::fromSql(self::text($row['created_at'] ?? null)) ?? Clock::now(),
            updatedAt: Clock::fromSql(self::text($row['updated_at'] ?? null)) ?? Clock::now(),
            kind: self::text($row['kind'] ?? '') ?? '',
            quoteId: self::text($row['quote_id'] ?? null),
            name: self::text($row['name'] ?? '') ?? '',
            phone: self::text($row['phone'] ?? '') ?? '',
            email: self::text($row['email'] ?? '') ?? '',
            message: self::text($row['message'] ?? '') ?? '',
            destId: self::text($row['dest_id'] ?? null),
            weightG: isset($row['weight_g']) && $row['weight_g'] !== null ? (int) $row['weight_g'] : null,
            status: LeadStatus::orNew(self::text($row['status'] ?? '') ?? ''),
            notes: self::text($row['notes'] ?? '') ?? '',
            updatedBy: self::text($row['updated_by'] ?? '') ?? '',
            ipHash: self::text($row['ip_hash'] ?? null),
        );
    }

    /**
     * The one line the inbox list shows.
     *
     * For a quote lead the message begins with the summary QuoteText built -
     * "Express to Canada - 5 kg - PKR 19,620 ..." - and any contents line
     * follows it. For a contact message it is the first line of what they
     * wrote. Either way: the first line, which is the line that was written to
     * be read first.
     */
    public function summary(): string
    {
        $first = strtok($this->message, "\n");

        return trim($first === false ? '' : $first);
    }

    /** Whether anyone has worked this lead yet, and who last did. */
    public function lastTouchedBy(): string
    {
        return $this->updatedBy;
    }

    public function isUntouched(): bool
    {
        return $this->updatedBy === '' && $this->status === LeadStatus::NEW;
    }

    public function weightKg(): ?float
    {
        return $this->weightG === null ? null : $this->weightG / 1000;
    }

    private static function text(mixed $v): ?string
    {
        return $v === null ? null : (is_scalar($v) ? (string) $v : null);
    }
}
