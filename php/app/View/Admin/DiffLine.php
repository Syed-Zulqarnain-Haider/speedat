<?php

declare(strict_types=1);

namespace Speedat\View\Admin;

/**
 * One line of the review's change list: what changed, what it was, what it
 * becomes, and a flag when the move is large enough to be worth a second
 * look. The shape is `DiffLine` in src/lib/site/diff.ts, which
 * `Speedat\Domain\SiteDoc` produces here.
 *
 * The old value is printed struck through and the new one bold, so the owner
 * reads a price change as a price change and not as two numbers.
 */
final class DiffLine
{
    public function __construct(
        public readonly string $label,
        /** null when there was nothing before (an added country). */
        public readonly ?string $old = null,
        /** null when there is nothing after (a removed country). */
        public readonly ?string $new = null,
        /** "+38% - please double-check", or "" for an ordinary move. */
        public readonly string $flag = '',
        /** added|removed|renamed|visibility|rate|grid|field - only used as a hook. */
        public readonly string $kind = '',
    ) {
    }

    /**
     * Build from the diff array the domain layer hands over.
     *
     * @param array<string,mixed> $line
     */
    public static function fromArray(array $line): self
    {
        return new self(
            label: self::text($line['label'] ?? ''),
            old: isset($line['old']) && $line['old'] !== null ? self::text($line['old']) : null,
            new: isset($line['new']) && $line['new'] !== null ? self::text($line['new']) : null,
            flag: self::text($line['flag'] ?? ''),
            kind: self::text($line['kind'] ?? ''),
        );
    }

    /**
     * @param  list<array<string,mixed>> $lines
     * @return list<self>
     */
    public static function all(array $lines): array
    {
        return array_map(self::fromArray(...), $lines);
    }

    /** The label is followed by ": " only when there is a value to show. */
    public function hasValues(): bool
    {
        return $this->old !== null || $this->new !== null;
    }

    private static function text(mixed $v): string
    {
        return match (true) {
            is_string($v)            => $v,
            is_int($v), is_float($v) => (string) $v,
            is_bool($v)              => $v ? '1' : '',
            default                  => '',
        };
    }
}
