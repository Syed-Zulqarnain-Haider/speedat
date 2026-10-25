<?php

declare(strict_types=1);

namespace Speedat\Domain\SiteDoc;

/**
 * Everything a publish needs besides the document. Port of `PublishArgs` in
 * src/lib/site/repo.ts, plus the hold switch the TypeScript publisher passes
 * separately.
 */
final class PublishArgs
{
    /** @param array<array-key,mixed>|null $raw */
    public function __construct(
        public readonly SiteData $data,
        /** Who published; stored in the history. */
        public readonly string $by,
        /** "admin" | "import:<file>" | "email:<from>" | "restore:<n>" | "seed". */
        public readonly string $source,
        public readonly string $summary,
        public readonly int $changeCount = 0,
        /** The version the caller believes is live; publishing on top of a newer one is refused. */
        public readonly ?int $expectedBase = null,
        /** Publishing new rates may lift the price hold - the publisher chooses. */
        public readonly bool $liftHold = false,
        /**
         * The document exactly as it was SUBMITTED, before `Migrate` and
         * `SiteData::fromArray()` normalised it - when the caller still has
         * it.
         *
         * `$data` is a `SiteData`, and `SiteData::fromArray()` coerces
         * whatever it was built from into a schema-valid shape: a `services`
         * key that arrived as a string is an empty LIST by the time it
         * reaches the publisher, and `$data->toArray()` is therefore
         * schema-valid by construction. Checking only `$data` is checking the
         * answer rather than the question. Hand the raw array in and
         * `Repo::publishVersion()` checks what was actually sent.
         *
         * Null is not "skip the check" - the normalised document is still
         * checked. It is "this caller has nothing rawer than the value
         * object", which is true of a restore, a seed and a script.
         */
        public readonly ?array $raw = null,
    ) {
    }
}
