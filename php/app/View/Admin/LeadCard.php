<?php

declare(strict_types=1);

namespace Speedat\View\Admin;

use Speedat\Core\Money;
use Speedat\Domain\Pricing\Format;

/**
 * One lead in the inbox: a booked quote or a contact message. Port of the
 * card InboxList.tsx renders.
 *
 * The React list saved status and notes over a server action and patched the
 * row in place. Here each card is its own little form that posts and comes
 * back - slower by one page load, and worth it: nothing is written that the
 * server did not validate, and the office's ancient laptop with JavaScript
 * blocked works exactly the same.
 */
final class LeadCard
{
    public const STATUSES = ['new', 'contacted', 'booked', 'lost'];

    public function __construct(
        public readonly int $id,
        /** quote|message */
        public readonly string $kind,
        public readonly string $quoteId,
        public readonly string $name,
        public readonly string $phone,
        public readonly string $email,
        public readonly string $message,
        public readonly string $destination,
        /** "" when the lead carried no weight. */
        public readonly string $weight,
        public readonly string $status,
        public readonly string $notes,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly string $updatedBy,
    ) {
    }

    /**
     * Build from the row the leads layer hands over.
     *
     * @param array<string,mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $grams = is_numeric($row['weightG'] ?? null) ? (float) $row['weightG'] : 0.0;

        return new self(
            id: is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            kind: ($row['kind'] ?? '') === 'quote' ? 'quote' : 'message',
            quoteId: self::text($row['quoteId'] ?? ''),
            name: self::text($row['name'] ?? ''),
            phone: self::text($row['phone'] ?? ''),
            email: self::text($row['email'] ?? ''),
            message: self::text($row['message'] ?? ''),
            destination: self::text($row['destination'] ?? ''),
            weight: $grams > 0 ? Money::numberToString($grams / 1000) : '',
            status: in_array($row['status'] ?? '', self::STATUSES, true) ? (string) $row['status'] : 'new',
            notes: self::text($row['notes'] ?? ''),
            createdAt: self::text($row['createdAt'] ?? ''),
            updatedAt: self::text($row['updatedAt'] ?? ''),
            updatedBy: self::text($row['updatedBy'] ?? ''),
        );
    }

    /**
     * @param  list<array<string,mixed>> $rows
     * @return list<self>
     */
    public static function all(array $rows): array
    {
        return array_map(self::fromArray(...), $rows);
    }

    public function isNew(): bool
    {
        return $this->status === 'new';
    }

    /** "Quote" or "Message" - the tag at the head of the card. */
    public function kindLabel(): string
    {
        return $this->kind === 'quote' ? 'Quote' : 'Message';
    }

    /** A quote shows its reference and the name; a message shows the name, or the word Message. */
    public function heading(): string
    {
        return $this->kind === 'quote' ? $this->name : ($this->name !== '' ? $this->name : 'Message');
    }

    public function when(): string
    {
        return Format::fmtDateTime($this->createdAt);
    }

    public function touched(): string
    {
        return Format::fmtDateTime($this->updatedAt);
    }

    public function waHref(): string
    {
        return Wa::href($this->phone);
    }

    /**
     * What the list filter matches on: everything the office would type to find this lead.
     *
     * THE NUMBER IS IN IT THREE WAYS (fixed 2026-10-25). A lead stored as
     * "+92 300 1234567" was not found by "03001234567", which is how a caller
     * reads it out and how the office types it - the filter compares the key
     * as written, and neither the spaces nor the 92 are in what was typed. So
     * the key also carries the number as wa.me dials it (923001234567) and in
     * the local 0-form (03001234567). admin.js filters on this same attribute,
     * so the browser side matches too without a line of script changing.
     */
    public function searchKey(): string
    {
        return trim(implode(' ', array_filter([
            $this->name,
            $this->phone,
            ...$this->phoneForms(),
            $this->email,
            $this->destination,
            $this->quoteId,
        ], static fn (string $part): bool => $part !== '')));
    }

    /**
     * The number as bare digits, international and local: ["923001234567",
     * "03001234567"]. A number outside Pakistan has no 0-form, and a number
     * too short to dial has neither.
     *
     * @return list<string>
     */
    public function phoneForms(): array
    {
        $digits = Wa::digits($this->phone);

        if ($digits === '') {
            return [];
        }

        return str_starts_with($digits, '92') ? [$digits, '0' . substr($digits, 2)] : [$digits];
    }

    /** @return array<string,string> value => label, for the status select */
    public static function statusOptions(): array
    {
        $out = [];
        foreach (self::STATUSES as $status) {
            $out[$status] = $status;
        }

        return $out;
    }

    private static function text(mixed $v): string
    {
        return match (true) {
            is_string($v)            => $v,
            is_int($v), is_float($v) => (string) $v,
            default                  => '',
        };
    }
}
