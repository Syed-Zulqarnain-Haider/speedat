<?php

declare(strict_types=1);

namespace Speedat\Tests\View;

use Speedat\Domain\Seo\SiteFacts;
use Speedat\Domain\SiteDoc\Validate;
use Speedat\View\BoardView;
use Speedat\View\Copy;
use Speedat\View\Doc;
use Speedat\View\HomeView;
use Speedat\View\PageView;

/**
 * THE RATES SHOW THE COURIER RATE ONLY - owner, 2026-10-26: "the rates shown
 * everywhere should only show the actual rate, not the pickup charges
 * included. At the bottom just add a drill down and there show 1000 pickup
 * charges". Owner: rates show the courier rate only; the pickup charge is
 * shown under the quote, never added.
 *
 * The server-rendered half: the country tiles and the headline (Copy::
 * fromPrice), the rates board and its caption, the structured-data price
 * floors, the publish checks and the calculator's first paint. The fixture's
 * Canada 1 kg is Express 5,220 / Economy 4,100; its own add-on line ("Pickup
 * and service charges | 500 | on") is swapped for the line under test, so
 * every figure below is the bare rate unless an "on" line is present.
 */
final class SeparateChargesViewTest extends ViewTestCase
{
    private function withAddons(string $addons): Doc
    {
        return $this->doc(['settings' => ['addons' => $addons]]);
    }

    private function home(Doc $doc): string
    {
        $page = $this->pageView($doc, '/', 'Get a price', ['scripts' => PageView::CALCULATOR_SCRIPTS]);

        return $this->view()->render('site/home', ['view' => HomeView::build($page)]);
    }

    public function testTheTileAndTheHeadlineAreTheBareRate(): void
    {
        $doc = $this->withAddons('Pickup | 1000 | separate');

        $this->assertSame(4100.0, Copy::fromPrice($doc, 'ca')?->total, 'the pickup charge reached the tile');
        $this->assertSame(0.0, Copy::addonsOnTotal($doc));

        $tiles = HomeView::build($this->pageView($doc))->tiles;
        foreach ($tiles as $tile) {
            if ($tile->id === 'ca') {
                $this->assertSame('PKR 4,100', $tile->fromMoney);
            }
        }
    }

    public function testTheBoardIsTheBareRate(): void
    {
        $board = BoardView::build($this->withAddons('Pickup | 1000 | separate'));

        foreach ($board->rows as $row) {
            if ($row->id !== 'ca') {
                continue;
            }
            $prices = [];
            foreach ($row->cells as $cell) {
                $prices[$cell->serviceId] = $cell->price;
            }
            $this->assertSame(['exp' => 5220.0, 'eco' => 4100.0], $prices);
        }
    }

    public function testASeparateOnlyCaptionSaysNothingIsIncluded(): void
    {
        $note = Copy::boardNote($this->withAddons('Pickup | 1000 | separate'));

        $this->assertSame("1\u{00A0}kg parcel", $note);
        $this->assertStringNotContains('included', $note);
        $this->assertStringNotContains('pickup', $note);
    }

    public function testBesideAnOnLineTheCaptionNamesOnlyTheOnLine(): void
    {
        $doc  = $this->withAddons("Delivery | 1000 | on\nPickup | 1000 | separate");
        $note = Copy::boardNote($doc);

        // Exactly what a document with only the Delivery line has always said.
        $this->assertSame(Copy::boardNote($this->withAddons('Delivery | 1000 | on')), $note);
        $this->assertStringContains("delivery PKR\u{00A0}1,000 included", $note);
        $this->assertStringNotContains('pickup', $note);
        $this->assertSame(5100.0, Copy::fromPrice($doc, 'ca')?->total, 'on still adds, separate still does not');
    }

    public function testTheStructuredDataFloorsAreTheBareRate(): void
    {
        $doc   = $this->withAddons('Pickup | 1000 | separate');
        $facts = new SiteFacts($doc->data(), $doc->version(), $doc->publishedAt());

        foreach ($facts->priceFloors() as $floor) {
            if ($floor['country'] === 'Canada') {
                $this->assertSame(4100.0, (float) $floor['amount']);
            }
        }
    }

    public function testTheNewKeywordPublishesWithoutAnErrorOrAWarning(): void
    {
        $data = $this->withAddons("Delivery | 1000 | on\nPickup | 1000 | separate\nCustoms | 500 | info")->data();

        $addonMessages = static fn (array $msgs): array => array_values(array_filter(
            $msgs,
            static fn (string $m): bool => str_contains($m, 'Pickup') || str_contains($m, 'Customs') || str_contains($m, 'Optional charge'),
        ));

        $this->assertSame([], $addonMessages(Validate::errors($data)));
        $this->assertSame([], $addonMessages(Validate::warnings($data)));
    }

    public function testTheFirstPaintReservesTheDrillDownRowOnlyWhenThereIsOne(): void
    {
        $with    = $this->home($this->withAddons('Pickup | 1000 | separate'));
        $without = $this->home($this->withAddons('Delivery | 1000 | on'));

        $this->assertStringContains('<div class="result" data-state="country" data-n="2" data-sep="1">', $with);
        $this->assertStringContains('<div class="result" data-state="country" data-n="2">', $without);
        // The first paint names no price, so it names no pickup charge either:
        // the drill-down comes with the price, from calculator.js.
        $this->assertStringNotContains('<details class="charges"', $with);
    }

    public function testTheCalculatorBlobCarriesTheLineSoTheBrowserCanTellIt(): void
    {
        $raw = $this->jsonBlob($this->home($this->withAddons('Pickup | 1000 | separate')), 'speedat-calculator');
        /** @var array<string,mixed> $blob */
        $blob = json_decode((string) $raw, true);

        $this->assertSame('Pickup | 1000 | separate', $blob['site']['settings']['addons'] ?? null);
    }
}
