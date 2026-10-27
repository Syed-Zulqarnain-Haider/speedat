<?php

declare(strict_types=1);

namespace Speedat\View;

/**
 * /track, as the template reads it. Every string here is safe to show a
 * stranger: it came out of Tracking\PublicTimeline or was typed by the visitor
 * themselves (and is escaped on the way out like everything else).
 */
final class TrackView
{
    public const STATE_FORM      = 'form';
    public const STATE_FOUND     = 'found';
    public const STATE_NOT_FOUND = 'not_found';

    /**
     * @param list<array{date:string,time:string,label:string,place:string,stage:string}> $rows newest first
     */
    public function __construct(
        public readonly PageView $page,
        public readonly string $state,
        /** What the visitor typed, echoed back into the box. */
        public readonly string $typed = '',
        public readonly string $id = '',
        public readonly string $status = '',
        public readonly string $statusLabel = '',
        public readonly string $statusText = '',
        public readonly string $destination = '',
        public readonly string $carrier = '',
        public readonly array $rows = [],
        /** "27 Oct 2026, 14:05" in Pakistan time, or '' when the carrier has never been asked. */
        public readonly string $checkedAt = '',
        /** wa.me link that shares this page, or ''. */
        public readonly string $shareHref = '',
        /** The office's own WhatsApp, for "ask us". */
        public readonly string $officeWaHref = '',
        /** Seconds until the browser reloads a result page by itself; 0 for none. */
        public readonly int $refreshSeconds = 0,
    ) {
    }

    public function found(): bool
    {
        return $this->state === self::STATE_FOUND;
    }

    public function notFound(): bool
    {
        return $this->state === self::STATE_NOT_FOUND;
    }
}
