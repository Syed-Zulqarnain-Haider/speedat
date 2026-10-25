<?php

declare(strict_types=1);

namespace Speedat\View;

use Speedat\Domain\Pricing\Format;

/**
 * The contact line that closes every inner page. Port of
 * `src/components/site/CtaBand.tsx` (the file kept its old name there; this
 * one is named after what it renders, `.reach`).
 *
 * The two numbers as plain links and the hours on one line, then a WhatsApp
 * button and, when there is a landline, a Call button. A hairline above, no
 * band, nothing centred.
 *
 * `showLine` is false on /contact: that page states each number once above the
 * form, so under it the buttons go alone. The same number four times in two
 * screens read as a template.
 */
final class Reach
{
    private function __construct(
        /** `content.ctaTitle`; an h2 only when the owner set one. */
        public readonly string $title,
        /** `content.ctaSub`; a sentence only when the owner set one. */
        public readonly string $sub,
        public readonly string $waHref,
        /** The WhatsApp number as printed: "+92 315 766 7076". */
        public readonly string $waText,
        /** "" when the owner set no landline. */
        public readonly string $telHref,
        /** The landline as the owner typed it. */
        public readonly string $phone,
        /** `content.hours`; joins the line when set. */
        public readonly string $hours,
        public readonly bool $showLine,
    ) {
    }

    public static function build(Doc $doc, bool $showLine = true, bool $withHours = true): self
    {
        return new self(
            title: $doc->trimmed('ctaTitle'),
            sub: $doc->trimmed('ctaSub'),
            waHref: $doc->waHref(),
            waText: Format::fmtPhone($doc->company()->whatsapp),
            telHref: $doc->telHref(),
            phone: $doc->company()->phone,
            hours: $withHours ? $doc->content()->hours : '',
            showLine: $showLine,
        );
    }
}
