<?php

declare(strict_types=1);

namespace Speedat\Domain\SiteDoc;

/**
 * One line of the review the owner reads before publishing. Port of `DiffLine`
 * in src/lib/site/diff.ts.
 *
 * These lines are kept in the audit log, so they are short by design: the
 * windowing in `Diff::fieldChange()` exists to keep a 12 000-character FAQ edit
 * down to the sentence that actually changed.
 */
final class DiffLine
{
    public const ADDED      = 'added';
    public const REMOVED    = 'removed';
    public const RENAMED    = 'renamed';
    public const VISIBILITY = 'visibility';
    public const RATE       = 'rate';
    public const FIELD      = 'field';
    public const PROFILE    = 'profile';

    public function __construct(
        /** One of the constants above. */
        public readonly string $kind,
        public readonly string $label,
        /** Null when the line has no "before" side (an addition). */
        public readonly ?string $old = null,
        /** Null when the line has no "after" side (a removal). */
        public readonly ?string $new = null,
        /** e.g. "+40% - please double-check" for large rate moves. */
        public readonly ?string $flag = null,
    ) {
    }

    /** @return array<string,string> only the sides the line actually has, as the TypeScript object does */
    public function toArray(): array
    {
        $out = ['kind' => $this->kind, 'label' => $this->label];

        if ($this->old !== null) {
            $out['old'] = $this->old;
        }
        if ($this->new !== null) {
            $out['new'] = $this->new;
        }
        if ($this->flag !== null) {
            $out['flag'] = $this->flag;
        }

        return $out;
    }
}
