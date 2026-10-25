<?php

declare(strict_types=1);

namespace Speedat\Domain\SiteDoc;

/** Old and new text for one changed field, and which line(s) of it changed. */
final class FieldChange
{
    public function __construct(
        public readonly string $old,
        public readonly string $new,
        /** "line 3", "lines 2-4", "line 1 added"; null for a single-line field. */
        public readonly ?string $where = null,
    ) {
    }
}
