<?php

declare(strict_types=1);

namespace Speedat\View\Admin;

use Speedat\Domain\Pricing\Format;

/**
 * One line of Version history. Every publish is kept; restoring one copies
 * its rates, settings and words into the editor - it does NOT publish them,
 * which is why the button says "Restore into editor" and the owner still has
 * to review and publish afterwards.
 */
final class VersionRow
{
    public function __construct(
        public readonly int $version,
        /** ISO 8601 as stored. */
        public readonly string $publishedAt,
        public readonly string $publishedBy,
        /** The one-line summary the publish recorded, e.g. "12 prices, 1 country added". */
        public readonly string $summary,
        public readonly bool $isLive,
    ) {
    }

    /**
     * @param  list<array<string,mixed>> $rows
     * @return list<self>
     */
    public static function all(array $rows, int $liveVersion): array
    {
        $out = [];
        foreach ($rows as $row) {
            $version = is_numeric($row['version'] ?? null) ? (int) $row['version'] : 0;
            $out[]   = new self(
                version: $version,
                publishedAt: is_string($row['publishedAt'] ?? null) ? $row['publishedAt'] : '',
                publishedBy: is_string($row['publishedBy'] ?? null) ? $row['publishedBy'] : '',
                summary: is_string($row['summary'] ?? null) ? $row['summary'] : '',
                isLive: $version === $liveVersion,
            );
        }

        return $out;
    }

    public function when(): string
    {
        return Format::fmtDateTime($this->publishedAt);
    }
}
