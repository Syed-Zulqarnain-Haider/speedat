<?php

declare(strict_types=1);

namespace Speedat\View;

use Speedat\Domain\Pricing\Destination;
use Speedat\Domain\Pricing\Format;

/**
 * /contact. Port of `src/app/(site)/contact/page.tsx` and
 * `src/components/site/ContactForm.tsx`.
 *
 * The WhatsApp number as the page - big, green, one tap - then the other
 * numbers, the email, the office, the hours and the pickup facts as plain
 * rows, then the message form under its own h2, then the contact line without
 * its numbers.
 *
 * Each number is stated ONCE on this page: the "Also" row skips whatever in
 * `phone2` is the WhatsApp number or the landline already printed above it.
 *
 * THE FORM IS A REAL `<form method="post">`, not a script. The CSRF token, the
 * signed render token and the honeypot are the server's contract; the values
 * and errors come back from the handler so a rejected submit re-renders what
 * was typed instead of emptying the page.
 */
final class ContactView
{
    /**
     * @param list<FactRow>          $facts
     * @param array<string,string>   $values the submitted values, echoed back
     * @param array<string,string>   $errors field name (or "_") -> message
     */
    private function __construct(
        public readonly PageView $page,
        public readonly Doc $doc,
        public readonly string $lede,
        public readonly string $waHref,
        public readonly string $waText,
        public readonly array $facts,
        /** @var list<Destination> */
        public readonly array $destinations,
        public readonly Reach $reach,
        /** The per-session CSRF token; the form does not render without one. */
        public readonly string $csrf,
        /** The signed render timestamp the handler checks. */
        public readonly string $formToken,
        public readonly array $values,
        public readonly array $errors,
        /** True after a successful post: the form is replaced by the thank-you card. */
        public readonly bool $sent,
    ) {
    }

    /**
     * @param array<string,string> $values
     * @param array<string,string> $errors
     */
    public static function build(
        PageView $page,
        string $csrf = '',
        string $formToken = '',
        array $values = [],
        array $errors = [],
        bool $sent = false,
    ): self {
        $doc    = $page->doc;
        $cities = Text::citiesSentence($doc->company()->originCities);
        $cutoff = Text::cutoffFact($doc->settings()->cutoffHour);

        $facts = [];
        if ($doc->telHref() !== '') {
            $facts[] = FactRow::link('Call', $doc->company()->phone, $doc->telHref());
        }

        $also = Phones::segmentsExcept($doc->content()->phone2, [$doc->company()->whatsapp, $doc->company()->phone]);
        if ($also !== []) {
            $facts[] = FactRow::segments('Also', $also);
        }

        if ($doc->company()->email !== '') {
            $facts[] = FactRow::link('Email', $doc->company()->email, 'mailto:' . $doc->company()->email);
        }
        if ($doc->content()->address !== '') {
            $facts[] = FactRow::address(
                'Office',
                $doc->content()->address,
                Text::mapHref($doc->content()->mapUrl, $doc->content()->address)
            );
        }
        if ($doc->content()->hours !== '') {
            $facts[] = FactRow::plain('Hours', $doc->content()->hours);
        }
        if ($cities !== '') {
            $facts[] = FactRow::plain('Pickup', $cities);
        }
        if ($cutoff !== '') {
            $facts[] = FactRow::plain('Cutoff', $cutoff);
        }

        return new self(
            page: $page,
            doc: $doc,
            lede: $doc->content()->contactLede,
            waHref: $doc->waHref(),
            waText: Format::fmtPhone($doc->company()->whatsapp),
            facts: $facts,
            destinations: $doc->activeAlpha(),
            // Every number is stated once above the form, so the line under it
            // is the two buttons alone.
            reach: Reach::build($doc, showLine: false),
            csrf: $csrf,
            formToken: $formToken,
            values: $values,
            errors: $errors,
            sent: $sent,
        );
    }

    public function value(string $field): string
    {
        return $this->values[$field] ?? '';
    }

    public function error(string $field): string
    {
        return $this->errors[$field] ?? '';
    }
}
