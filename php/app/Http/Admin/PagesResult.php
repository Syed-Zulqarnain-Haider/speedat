<?php

declare(strict_types=1);

namespace Speedat\Http\Admin;

use Speedat\Domain\SiteDoc\PublishedVersion;

/** How a website-text publish went: a version, nothing to do, or why not. */
final class PagesResult
{
    public function __construct(
        public readonly PublishedVersion $live,
        public readonly PagesForm $form,
        /** The new version's number; 0 when nothing was published. */
        public readonly int $version = 0,
        /** Why nothing was published, "" when nothing was refused. */
        public readonly string $refused = '',
        public readonly int $status = 200,
    ) {
    }
}
