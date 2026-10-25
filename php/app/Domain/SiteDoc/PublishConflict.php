<?php

declare(strict_types=1);

namespace Speedat\Domain\SiteDoc;

/**
 * Somebody else published while this draft was being edited.
 *
 * Not an error to log and retry: the publisher was looking at prices that are
 * no longer the live ones, so the only safe answer is to show him what changed
 * and let him decide. `live` is the version number that actually is live now.
 */
final class PublishConflict extends \RuntimeException
{
    public function __construct(public readonly int $live)
    {
        parent::__construct('A newer version (' . $live . ') was published meanwhile');
    }
}
