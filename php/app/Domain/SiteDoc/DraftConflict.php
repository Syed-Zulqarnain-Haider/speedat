<?php

declare(strict_types=1);

namespace Speedat\Domain\SiteDoc;

/**
 * Somebody else wrote the draft while this editor had it open.
 *
 * The sibling of `PublishConflict`, one step earlier in the day: that one is
 * "the live version moved", this one is "the working copy moved". Both mean
 * the same thing to the person in front of the screen - what you are looking
 * at is not what is stored - and both have the same only safe answer, which is
 * to show him who wrote it and when and let him decide.
 *
 * `current` is the draft as it stands NOW, so the screen can say
 * `$e->current->editedBy()` and offer to reload it. Nothing was written: the
 * transaction that raised this rolled back.
 */
final class DraftConflict extends \RuntimeException
{
    public function __construct(public readonly Draft $current)
    {
        $who = $current->editedBy();

        parent::__construct(
            'The draft has changed since this editor was opened' . ($who === '' ? '' : ' (' . $who . ')')
        );
    }
}
