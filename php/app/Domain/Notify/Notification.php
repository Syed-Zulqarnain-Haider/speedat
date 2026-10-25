<?php

declare(strict_types=1);

namespace Speedat\Domain\Notify;

/**
 * One message for the office. Port of the `Mail` interface in
 * src/lib/notify.ts, named Notification here so it cannot be mistaken for
 * Speedat\Core\Mail.
 *
 * `html` is optional and, when absent, a <pre> of the text is generated - the
 * same fallback the TypeScript has, so a notification looks the same in a mail
 * client whichever side built it.
 */
final class Notification
{
    public function __construct(
        public readonly string $subject,
        public readonly string $text,
        /** Optional HTML alternative; htmlBody() supplies one when this is null. */
        public readonly ?string $html = null,
        /** Grouping label: "lead" or "alert". */
        public readonly ?string $tag = null,
    ) {
    }

    /**
     * The HTML part. Port of notify.ts:
     * `<pre style="font-family:sans-serif;white-space:pre-wrap">{escaped}</pre>`
     *
     * The escaping covers the same four characters the TypeScript does. It is
     * escaping into an HTML text node, which is what a <pre> body is, so & < >
     * and " are the whole set.
     */
    public function htmlBody(): string
    {
        if ($this->html !== null) {
            return $this->html;
        }

        return '<pre style="font-family:sans-serif;white-space:pre-wrap">'
            . str_replace(['&', '<', '>', '"'], ['&amp;', '&lt;', '&gt;', '&quot;'], $this->text)
            . '</pre>';
    }
}
