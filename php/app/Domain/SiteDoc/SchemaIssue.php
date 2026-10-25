<?php

declare(strict_types=1);

namespace Speedat\Domain\SiteDoc;

/**
 * One thing wrong with a submitted document: where it is and what is wrong.
 *
 * `path` is dotted, the way Zod's `issue.path.join(".")` prints it
 * ("content.phone2", "destinations.3.rates.express.first"), because that string
 * ends up in the publish error the admin reads and in the tests that pin it.
 */
final class SchemaIssue
{
    public function __construct(
        public readonly string $path,
        public readonly string $message,
    ) {
    }

    /** "content.phone2: String must contain at most 80 character(s)" */
    public function __toString(): string
    {
        return ($this->path === '' ? '' : $this->path . ': ') . $this->message;
    }
}
