<?php

declare(strict_types=1);

namespace Speedat\View;

use Speedat\Domain\Pricing\Engine;

/**
 * /about. Port of `src/app/(site)/about/page.tsx`.
 *
 * The story as plain paragraphs, then the facts as a list of rows - office,
 * pickup cities, hours, cutoff - then mission, vision and values when the
 * owner wrote them, then the contact line.
 *
 * The two phone numbers are NOT rows here: the contact line under the list and
 * the footer already print them, and a third copy 200px above the second read
 * as a template. For the same reason the contact line on this page goes
 * without the hours, which sit in the row just above it.
 */
final class AboutView
{
    /**
     * @param list<string>                             $story
     * @param list<FactRow>                            $facts
     * @param list<array{lead:string,text:string}>     $values
     */
    private function __construct(
        public readonly PageView $page,
        public readonly Doc $doc,
        public readonly string $title,
        public readonly array $story,
        public readonly array $facts,
        public readonly string $mission,
        public readonly string $vision,
        public readonly array $values,
        public readonly Reach $reach,
    ) {
    }

    public static function build(PageView $page): self
    {
        $doc    = $page->doc;
        $cities = Text::citiesSentence($doc->company()->originCities);
        $cutoff = Text::cutoffFact($doc->settings()->cutoffHour);

        $facts = [];
        if ($doc->content()->address !== '') {
            $facts[] = FactRow::address(
                'Office',
                $doc->content()->address,
                Text::mapHref($doc->content()->mapUrl, $doc->content()->address)
            );
        }
        if ($cities !== '') {
            $facts[] = FactRow::plain('Pickup', $cities);
        }
        if ($doc->content()->hours !== '') {
            $facts[] = FactRow::plain('Hours', $doc->content()->hours);
        }
        if ($cutoff !== '') {
            $facts[] = FactRow::plain('Cutoff', $cutoff);
        }

        $values = [];
        foreach (Engine::lines($doc->content()->values) as $line) {
            $p    = Engine::parts($line, 2);
            $name = $p[0];
            $text = $p[1];

            if ($name === '' && $text === '') {
                continue;
            }

            $values[] = [
                // "Honesty." - a value's name as the start of its sentence; a
                // name that already ends in punctuation is left alone.
                'lead' => $name === '' ? '' : ($text !== '' ? self::lead($name) : $name),
                'text' => $text,
            ];
        }

        return new self(
            page: $page,
            doc: $doc,
            title: 'About ' . trim($doc->company()->name),
            story: Engine::lines($doc->content()->story),
            facts: $facts,
            mission: $doc->trimmed('mission'),
            vision: $doc->trimmed('vision'),
            values: $values,
            // The hours are the row just above the line, so the line goes without them.
            reach: Reach::build($doc, withHours: false),
        );
    }

    private static function lead(string $name): string
    {
        return preg_match('/[.!?:]$/', $name) === 1 ? $name : $name . '.';
    }
}
