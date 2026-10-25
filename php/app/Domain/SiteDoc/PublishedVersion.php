<?php

declare(strict_types=1);

namespace Speedat\Domain\SiteDoc;

/**
 * A stored version of the site document: what it says, which number it is and
 * when it went live. Port of `PublishedVersion` in src/lib/site/types.ts.
 *
 * The TypeScript widens `SiteData` with two fields; PHP keeps `SiteData` final
 * and immutable and wraps it instead, so `$version->data` is the document and
 * everything else on here is about the publication, not the content.
 *
 * Version 0 is the sample data: it is what the public site shows when the
 * database has never been published to, or cannot be reached at all.
 */
final class PublishedVersion
{
    public function __construct(
        public readonly SiteData $data,
        public readonly int $version,
        /** ISO 8601 in UTC, e.g. "2026-09-27T06:00:00.000Z". */
        public readonly string $publishedAt,
    ) {
    }

    /** The sample document, as the fallback serves it. */
    public static function sample(): self
    {
        return new self(Seed::site()->withLive(false), 0, '1970-01-01T00:00:00.000Z');
    }

    /** True when this is the fallback rather than something somebody published. */
    public function isSample(): bool
    {
        return $this->version === 0;
    }

    /** @return array<string,mixed> the document plus its two publication fields, as the TypeScript object */
    public function toArray(): array
    {
        return $this->data->toArray() + ['version' => $this->version, 'publishedAt' => $this->publishedAt];
    }
}
