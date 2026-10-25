<?php

declare(strict_types=1);

namespace Speedat\View;

use Speedat\Core\Money;
use Speedat\Domain\Pricing\Destination;
use Speedat\Domain\Pricing\Engine;
use Speedat\Domain\Pricing\Format;
use Speedat\Domain\Pricing\Service;

/**
 * The home page. Port of `src/app/(site)/page.tsx` plus the server-renderable
 * half of `Calculator.tsx`.
 *
 * One section: the headline names the route and the lowest 1 kg price, the
 * calculator is the page, the rates board is the reference under it.
 *
 * WHAT IS SERVER-RENDERED AND WHY. The headline, the sub, the country tiles
 * with their from-prices, the weight buttons, the board and the fine print are
 * all printed by PHP. Two reasons: the largest element on screen is then text
 * that is in the first byte (the quality bar's performance section), and when
 * calculator.js replaces the mount's children it replaces them with the same
 * structure, so nothing moves. The interactive price is the one thing that
 * needs the script, and with JavaScript off the panel says so and hands the
 * visitor WhatsApp and the phone instead of an empty frame.
 *
 * UNDER HOLD none of that happens: `calculator` is null, `hold` carries names
 * only, and no price is computed anywhere on the page.
 */
final class HomeView
{
    /** @param list<Tile> $tiles */
    private function __construct(
        public readonly PageView $page,
        public readonly Doc $doc,
        /** The h1. */
        public readonly string $title,
        /** The line under it; "" hides it. */
        public readonly string $sub,
        /** `content.stats` as printed points ("12 countries"); empty = no line. @var list<string> */
        public readonly array $stats,
        /** One plain line under the calculator; "" hides it. */
        public readonly string $promise,
        public readonly ?CalculatorData $calculator,
        public readonly ?HoldData $hold,
        public readonly array $tiles,
        /** True when the last grid cell is the "More countries" picker instead of a tile. */
        public readonly bool $moreCountries,
        /** @var list<int> */
        public readonly array $weights,
        public readonly BoardView $board,
    ) {
    }

    public static function build(PageView $page, string $basePath = ''): self
    {
        $doc = $page->doc;

        $stats = [];
        foreach (Engine::lines($doc->content()->stats) as $line) {
            $parts   = Engine::parts($line, 2);
            $stats[] = trim($parts[1] . ' ' . $parts[0]);
        }

        $split = CalculatorData::tiles($doc);
        $tiles = [];
        foreach ($split['tiles'] as $dest) {
            $tiles[] = self::tile($doc, $dest);
        }

        return new self(
            page: $page,
            doc: $doc,
            title: $doc->trimmed('heroTitle') !== '' ? $doc->trimmed('heroTitle') : Copy::heroTitleAuto($doc),
            // The hero sub-line is the owner's to write, or nothing. It used to
            // fall back to Copy::heroSubAuto - "1 kg from PKR X · N to M days" -
            // but the owner had it removed (2026-10-11): an auto price promise
            // nobody typed is not wanted under the headline. A subtitle the
            // owner DOES type in Admin still shows; heroSubAuto stays alive for
            // the SEO sentence and the test that pins its wording.
            sub: $doc->trimmed('heroSub'),
            stats: $stats,
            promise: $doc->trimmed('promise'),
            calculator: $doc->holdOn() ? null : new CalculatorData($doc, $basePath),
            hold: $doc->holdOn() ? new HoldData($doc, $basePath) : null,
            tiles: $tiles,
            moreCountries: $split['more'],
            weights: CalculatorData::weightButtons($doc),
            board: BoardView::build($doc),
        );
    }

    private static function tile(Doc $doc, Destination $dest): Tile
    {
        // Under hold no price is computed, so none can be printed.
        $from = $doc->pricesVisible() ? Copy::fromPrice($doc, $dest->id) : null;

        return new Tile(
            id: $dest->id,
            name: $dest->name,
            // The tiles are the first screen, so their flags are not lazy.
            flag: Flag::for($dest->name, $dest->id, 32, false),
            from: $from,
            fromMoney: $from === null ? '' : Format::fmtMoney($from->total, $doc->currency()),
        );
    }

    /**
     * Destinations that only live in the "More countries" picker: the whole
     * active list A-Z, as `DestOptions` renders it.
     *
     * @return list<Destination>
     */
    public function pickerOptions(): array
    {
        return $this->doc->activeAlpha();
    }

    /** @return list<Service> */
    public function services(): array
    {
        return $this->doc->services();
    }

    /**
     * `data-n` on the result block: how many service rows the CSS must reserve
     * room for, capped at four. With `data-state` it is what keeps the block
     * 200px tall in every state, so the price lands in a slot that was already
     * there.
     */
    public function resultRows(): int
    {
        return min(count($this->doc->services()), 4);
    }

    /** "Over 25 kg" - the cargo button's label, or "" when the document sets no limit. */
    public function overMaxLabel(): string
    {
        $maxKg = $this->doc->settings()->maxKg;

        return $maxKg > 0 ? 'Over ' . Money::numberToString($maxKg) . ' kg' : '';
    }

    /** The hold panel's weight placeholder: "1 to 25, or more for cargo". */
    public function holdWeightHint(): string
    {
        $maxKg = $this->doc->settings()->maxKg;

        return $maxKg > 0 ? '1 to ' . Money::numberToString($maxKg) . ', or more for cargo' : 'e.g. 2.5';
    }
}
