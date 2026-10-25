<?php

declare(strict_types=1);

namespace Speedat\View\Admin;

use Speedat\Domain\Pricing\Format;

/**
 * The price hold switch. Port of HoldBar.tsx.
 *
 * One button takes every price off the website immediately; customers see the
 * owner's message and a WhatsApp form instead. The draft is untouched either
 * way - that is the whole point of the feature, and why any admin may switch
 * it ON (the safe direction) while only an owner may resume prices.
 *
 * Without JavaScript the confirm dialog is a link that reloads the page with
 * the dialog rendered open (`?hold=1`); with it, the button unfolds the same
 * markup in place.
 */
final class HoldPanel
{
    /** Matches HOLD_MESSAGE_MAX in src/lib/site/hold-shared.ts and the column width. */
    public const MESSAGE_MAX = 300;

    public function __construct(
        public readonly bool $on,
        public readonly string $message,
        /** Who last switched it, "" when nobody ever has. */
        public readonly string $by = '',
        /** ISO 8601, "" when never touched. */
        public readonly string $since = '',
        /** Only an owner resumes prices. */
        public readonly bool $isOwner = false,
        /** The confirm dialog is rendered open (?hold=1, the no-JS path). */
        public readonly bool $dialogOpen = false,
    ) {
    }

    /** "- since 23 Sept 2026, 14:05 by boss@speedat.pk", or "" when it was never switched. */
    public function sinceSentence(): string
    {
        if ($this->since === '') {
            return '';
        }

        $when = Format::fmtDateTime($this->since);
        if ($when === '') {
            return '';
        }

        return " - since " . $when . ($this->by !== '' ? ' by ' . $this->by : '');
    }

    public function messageLength(): int
    {
        // Counted the way the textarea's own counter counts it, and the way the
        // server caps it: characters, not bytes, so an accented name is one.
        return mb_strlen(trim($this->message));
    }
}
