<?php

declare(strict_types=1);

namespace Speedat\View;

/**
 * One fact on the footer's wrapping line: a word, or a word and a link.
 * "WhatsApp +92 315 766 7076" is a prefix and a link; "Rates updated 22 Sept
 * 2026" is plain text; the email address is a link with no prefix.
 *
 * Modelled rather than concatenated because the link has to be a real `<a>` -
 * the brief's "every number a plain link" - and a template that built the
 * anchor from a string would have to print that string raw.
 */
final class FootFact
{
    public function __construct(
        /** Printed before the link, with the trailing space the design wants; "" for a bare fact. */
        public readonly string $prefix,
        public readonly string $text,
        /** "" makes the whole fact plain text. */
        public readonly string $href = '',
        /** WhatsApp and Maps open in a new tab; tel: and mailto: do not. */
        public readonly bool $external = false,
    ) {
    }

    public static function plain(string $text): self
    {
        return new self('', $text);
    }
}
