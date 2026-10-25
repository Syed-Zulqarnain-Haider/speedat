<?php

declare(strict_types=1);

namespace Speedat\View;

use Speedat\Core\Money;
use Speedat\Domain\Pricing\Engine;

/**
 * /services. Port of `src/app/(site)/services/page.tsx`.
 *
 * The h1 and its line, every `content.services` line as a row of a plain list,
 * one paragraph of facts the page computes from the settings, then the rates
 * board in the same 720px column as the list, then the contact line. No cards,
 * no icons, nothing that moves.
 */
final class ServicesView
{
    /** @param list<array{title:string,text:string}> $items */
    private function __construct(
        public readonly PageView $page,
        public readonly Doc $doc,
        public readonly string $lede,
        public readonly array $items,
        /** The facts paragraph, already assembled; "" when the document sets none of them. */
        public readonly string $facts,
        /** True when the facts paragraph ends with the sentence that links the home page. */
        public readonly bool $factsLinkHome,
        /** The sentence after the home-page link: "; heavier by quote." */
        public readonly string $factsTail,
        public readonly BoardView $board,
        public readonly Reach $reach,
    ) {
    }

    /**
     * The icon token in `icon | Title | text` is parsed and dropped: nothing on
     * the page is an icon unless it is the affordance. A line without a known
     * token reads as `Title | text`.
     */
    private const CARD_ICONS = ['plane', 'globe', 'doc', 'box', 'truck', 'shield', 'clock', 'phone'];

    public static function build(PageView $page): self
    {
        $doc      = $page->doc;
        $settings = $doc->settings();

        $items = [];
        foreach (Engine::lines($doc->content()->services) as $line) {
            $p     = Engine::parts($line, 3);
            $named = in_array(strtolower($p[0]), self::CARD_ICONS, true);

            $items[] = [
                'title' => $named ? $p[1] : $p[0],
                'text'  => $named ? $p[2] : ($p[1] !== '' ? $p[1] : $p[2]),
            ];
        }

        $cities = Text::originCities($doc->company()->originCities);
        $cutoff = Text::cutoffFact($settings->cutoffHour);

        $facts = '';
        if ($cities !== []) {
            $facts .= 'Pickup in ' . implode(' and ', $cities) . '. ';
        }
        if ($cutoff !== '') {
            $facts .= $cutoff . '. ';
        }
        // The TypeScript adds "Documents up to 0.5 kg." here. It is gone with
        // the document rate (owner, 2026-09-27): there is no weight at which a
        // different price starts, so there is no fact left to state.
        $linkHome = $settings->maxKg > 0;
        if ($linkHome) {
            $facts .= 'Parcels up to ' . Money::numberToString($settings->maxKg) . ' kg priced on the ';
        }

        return new self(
            page: $page,
            doc: $doc,
            lede: $doc->content()->servicesLede,
            items: $items,
            facts: $facts,
            factsLinkHome: $linkHome,
            factsTail: $linkHome ? '; heavier by quote.' : '',
            board: BoardView::build($doc),
            reach: Reach::build($doc),
        );
    }

    public function hasFacts(): bool
    {
        return $this->facts !== '';
    }
}
