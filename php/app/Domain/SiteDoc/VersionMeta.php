<?php

declare(strict_types=1);

namespace Speedat\Domain\SiteDoc;

/**
 * One row of the version history, without the document itself. Port of
 * `VersionMeta` in src/lib/site/repo.ts.
 *
 * The history screen lists dozens of these; loading every full document to
 * print a date and a one-line summary would read megabytes of JSON to render a
 * table.
 */
final class VersionMeta
{
    public function __construct(
        public readonly int $version,
        /** ISO 8601 in UTC. */
        public readonly string $publishedAt,
        public readonly string $publishedBy,
        /** "admin" | "import:<file>" | "email:<from>" | "restore:<n>" | "seed". */
        public readonly string $source,
        public readonly string $summary,
        public readonly int $changeCount,
    ) {
    }
}
