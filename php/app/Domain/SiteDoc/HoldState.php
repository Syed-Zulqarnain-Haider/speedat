<?php

declare(strict_types=1);

namespace Speedat\Domain\SiteDoc;

/**
 * The price hold's shape and its message hygiene. Port of
 * src/lib/site/hold-shared.ts - the half that a view may read, with no
 * database in it.
 *
 * The hold lives OUTSIDE the published document on purpose: turning it on must
 * never push a half-keyed draft, and turning it off must never need a diff.
 */
final class HoldState
{
    public const MESSAGE_MAX = 300;

    public const DEFAULT_MESSAGE = 'Our rates are being updated right now. Send us your destination and parcel '
        . 'weight and we will price it by hand within the hour.';

    public function __construct(
        public readonly bool $on,
        /** Shown to customers in place of the calculator while the hold is on. */
        public readonly string $message,
        /** Who switched it on (or off). */
        public readonly string $by,
        /** ISO timestamp, null when the switch was never touched. */
        public readonly ?string $since,
    ) {
    }

    /** The hold as it reads when nobody has ever touched it. */
    public static function none(): self
    {
        return new self(false, self::DEFAULT_MESSAGE, '', null);
    }

    /**
     * Read a stored row, however damaged.
     *
     * A hold is a business switch, not a security control, so junk in the row
     * reads as "off with whatever message could be salvaged" rather than as an
     * exception - an unreadable settings row must not take the prices off the
     * site by accident.
     *
     * @param array<array-key,mixed> $v
     */
    public static function fromArray(array $v): self
    {
        return new self(
            on: ($v['on'] ?? null) === true,
            message: self::cleanMessage($v['message'] ?? null),
            by: is_string($v['by'] ?? null) ? $v['by'] : '',
            since: is_string($v['since'] ?? null) ? $v['since'] : null,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['on' => $this->on, 'message' => $this->message, 'by' => $this->by, 'since' => $this->since];
    }

    /**
     * Trim, collapse whitespace, cap the length; fall back to the default when
     * empty. Port of `cleanHoldMessage`.
     *
     * The cap is applied AFTER the trim, exactly as the TypeScript does, so a
     * message cut at 300 characters may end on a space. Trimming again here
     * would be tidier and would disagree with the other half of the same
     * product about what is stored.
     *
     * The cap counts UTF-16 code units, because the TypeScript caps it with a
     * plain `slice(0, 300)` and that is what JavaScript counts - not the code
     * points `mb_substr` would count, and not the characters the schema's own
     * caps count. An emoji is two units here and one character there; both are
     * right for their own rule.
     */
    public static function cleanMessage(mixed $raw): string
    {
        $s = Text::js($raw);
        $s = Text::collapseSpaces($s);
        $s = Text::trim($s);
        $s = Text::sliceUtf16($s, self::MESSAGE_MAX);

        return $s === '' ? self::DEFAULT_MESSAGE : $s;
    }
}
