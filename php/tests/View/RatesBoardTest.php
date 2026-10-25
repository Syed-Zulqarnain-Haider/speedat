<?php

declare(strict_types=1);

namespace Speedat\Tests\View;

use Speedat\View\Board;
use Speedat\View\BoardView;
use Speedat\View\Doc;

/**
 * The rates board under SKIPPABLE WEIGHT PRICES (owner, 2026-09-28).
 *
 * The board is the 1 kg parcel per service, priced by the same engine the
 * calculator uses. A blank 1 kg box used to be charged at the next priced
 * kilogram, so a country whose Express started at 2 kg printed its 2 KG PRICE
 * IN THE 1 KG COLUMN - under a caption that says "1 kg parcel". That is the
 * failure this file pins: the number nobody typed must not come back.
 *
 * THE DAYS GO WITH THE PRICE (2026-09-29). The first port of this rule left a
 * blank cell's transit days behind, and that is wrong in the way the owner's
 * own sentence forbids - "show those that do and say nothing about the others".
 * A cell reading "4-6 days" with no figure is not nothing: it is the board
 * promising a delivery time for a service the calculator, two inches down the
 * same page, refuses to quote at that same 1 kg. It was live and not
 * hypothetical - Germany's Express Saver carries days and no rate card at all,
 * so its column advertised "3-5 days" at a price that does not exist.
 *
 * What did NOT change, and is asserted so a future tidy-up does not take it:
 * the cell still prints nothing at all for a null price (no dash, no "PKR 0"),
 * and UNDER HOLD every days line survives. Hold is the one state where the
 * engine is never ASKED, so the missing price says nothing about the service
 * and the board stays useful as a timetable. That is why the rule is "asked and
 * refused", not "price is null" - the two look identical from the cell.
 */
final class RatesBoardTest extends ViewTestCase
{
    /**
     * The seed with one hole punched in it: Canada's Express has no 1 kg box,
     * but still has 2 kg (PKR 6,700) and still has "4-6" days. Nothing else
     * moves.
     *
     * Built with `Doc::fromArray` and not with `ViewTestCase::doc()`, because
     * that one merges overrides with `array_replace_recursive` - which can add
     * a key and change one, but cannot REMOVE one, so the 1 kg box it is meant
     * to delete comes straight back from the base seed.
     *
     * @param array<string,mixed> $hold
     */
    private function docWithHoleAtOneKg(array $hold = []): Doc
    {
        $seed = self::seed();
        unset($seed['destinations'][0]['rates']['exp']['grid']['1']);

        return Doc::fromArray($seed, $hold);
    }

    /** @return array<string,\Speedat\View\BoardCell> serviceId => cell, for one destination */
    private function cells(BoardView $board, string $destId): array
    {
        foreach ($board->rows as $row) {
            if ($row->id === $destId) {
                $out = [];
                foreach ($row->cells as $cell) {
                    $out[$cell->serviceId] = $cell;
                }

                return $out;
            }
        }

        $this->fail('the board has no row for ' . $destId);
    }

    /**
     * THE HEAVIER PRICE DOES NOT COME DOWN A COLUMN. Before the engine change
     * this cell printed PKR 7,200 - the 2 kg box (6,700) plus the 500 pickup
     * add-on - in a column headed "1 kg parcel". It now prints nothing.
     */
    public function testAServiceWithNoOneKilogramPriceDoesNotBorrowTheTwoKilogramOne(): void
    {
        $cells = $this->cells(BoardView::build($this->docWithHoleAtOneKg()), 'ca');

        $this->assertNull($cells['exp']->price, 'Express has no 1 kg box, so the board has no 1 kg Express price');

        // AND ITS DAYS GO WITH IT. The rate card still says "4-6", and the cell
        // still must not: a transit time for a service that cannot be bought at
        // this weight is the dash the owner's rule forbids, written in words.
        $this->assertNull($cells['exp']->days, 'a service not offered at 1 kg does not advertise a delivery time either');

        // The service that DOES price 1 kg is untouched, to the rupee: 4,100
        // plus the 500 pickup add-on that is on by default in the fixture. This
        // is the second half of the owner's sentence - show the ones that have
        // it - and it is asserted beside the first so a fix for one cannot
        // quietly blank the other.
        $this->assertSame(4600.0, $cells['eco']->price, 'Economy still prices 1 kg');
        $this->assertSame("8\u{2013}12", $cells['eco']->days);
    }

    /**
     * THE SHAPE THAT WAS ACTUALLY LIVE: a rate card holding days and NO grid at
     * all, which is what the owner leaves behind after adding a service to a
     * country and not yet typing its prices. Germany's Express Saver is exactly
     * this, and the board printed "3-5 days" in its column on the deployed
     * site while the calculator refused to quote Express Saver at any weight.
     *
     * It is a separate case from the hole above because it reaches the cell by
     * a different route - `Rate::grid` is null rather than missing one key - and
     * a fix that only handled the hole would leave this one advertising.
     */
    public function testAServiceWithDaysAndNoPricesAtAllAdvertisesNothing(): void
    {
        $seed = self::seed();
        // Australia gains an Economy card with a timetable and no numbers.
        $seed['destinations'][1]['rates']['eco'] = ['days' => '7-10'];

        $cells = $this->cells(BoardView::build(Doc::fromArray($seed, [])), 'au');

        $this->assertNull($cells['eco']->price, 'a card with no grid prices nothing');
        $this->assertNull($cells['eco']->days, 'and so says nothing about how long it would take');
        $this->assertSame(5500.0, $cells['exp']->price, 'the priced service beside it is untouched');
        $this->assertSame("3\u{2013}5", $cells['exp']->days);
    }

    /** With no hole, both services print exactly what they did before. */
    public function testAFullyPricedCountryIsUnchanged(): void
    {
        $cells = $this->cells(BoardView::build($this->doc()), 'ca');

        $this->assertSame(5720.0, $cells['exp']->price, 'Express 1 kg is 5,220 + the 500 add-on');
        $this->assertSame("4\u{2013}6", $cells['exp']->days);
        $this->assertSame(4600.0, $cells['eco']->price);
        $this->assertSame("8\u{2013}12", $cells['eco']->days);
    }

    /**
     * UNDER HOLD THE ENGINE IS NEVER ASKED, so nothing rate-shaped is in the
     * HTML at all - and the days survive, because they are not a price. This is
     * the rule that makes "drop the days whenever the price is null" the wrong
     * simplification.
     */
    public function testUnderHoldEveryPriceIsNullAndEveryDaysLineSurvives(): void
    {
        $cells = $this->cells(BoardView::build($this->doc([], ['on' => true])), 'ca');

        foreach (['exp' => "4\u{2013}6", 'eco' => "8\u{2013}12"] as $serviceId => $days) {
            $this->assertNull($cells[$serviceId]->price, $serviceId . ' has no price under hold');
            $this->assertSame($days, $cells[$serviceId]->days, $serviceId . ' keeps its days under hold');
        }
    }

    /** A service the country does not carry at all has neither, and always did. */
    public function testAServiceTheCountryDoesNotCarryIsStillBlank(): void
    {
        $cells = $this->cells(BoardView::build($this->doc()), 'zz');

        $this->assertNull($cells['eco']->price, 'Narnia has no Economy rate');
        $this->assertNull($cells['eco']->days);
        $this->assertSame(7500.0, $cells['exp']->price, 'and its Express is untouched');
    }

    /**
     * The template prints the cell as the view model has it: no dash, no zero,
     * no borrowed figure. Asserted on CANADA'S OWN ROW, not on the table - the
     * fixture's Narnia has an empty cell for a different reason (no Economy
     * rate at all), and a whole-table search would pass on that one whatever
     * Canada did.
     */
    public function testTheRenderedCellCarriesNoPriceAtAllForTheBlankKilogram(): void
    {
        $board = BoardView::build($this->docWithHoleAtOneKg());
        $html  = $this->view()->render('site/partials/rates-board', ['board' => $board]);

        $from = strpos($html, '>Canada<');
        $this->assertTrue($from !== false, 'the board has a Canada row');
        $to  = strpos($html, '</tr>', (int) $from);
        $row = substr($html, (int) $from, (int) $to - (int) $from);

        $this->assertStringNotContains('PKR 7,200', $row, 'the 2 kg price must not appear in the 1 kg column');
        $this->assertStringNotContains('PKR 0', $row, 'and never a zero');
        $this->assertStringNotContains('&#8212;', $row, 'and never an em dash standing in for a price');
        // What the unpriced cell carries is NOTHING - an empty cell, not a
        // timetable for a price that does not exist. The assertion is on the
        // literal empty `<td>` rather than on the absence of "4-6 days",
        // because Australia's Express in the same table legitimately says
        // "3-5 days" and a whole-row search would be looking at the wrong cell.
        $this->assertStringContains('<td></td>', $row, 'the unpriced Express cell is empty');
        $this->assertStringNotContains("4\u{2013}6 days", $row, 'and carries no delivery time of its own');
        // And the service that IS priced at 1 kg still prints both.
        $this->assertStringContains('PKR 4,600', $row, 'Economy 1 kg to Canada');
        $this->assertStringContains("8\u{2013}12 days", $row, 'with its days beside it');
    }

    /**
     * The HELD board is still a timetable: every cell empty of price and every
     * cell carrying days. Rendered, not just as a view model, because the empty
     * `<td>` above is the thing a careless fix would produce here too.
     */
    public function testTheRenderedHeldBoardIsAllDaysAndNoPrices(): void
    {
        $board = BoardView::build($this->doc([], ['on' => true]));
        $html  = $this->view()->render('site/partials/rates-board', ['board' => $board]);

        $this->assertStringNotContains('PKR', $html, 'a held board carries no figure at all');
        $this->assertStringContains("4\u{2013}6 days", $html, 'Canada Express keeps its timetable under hold');
        $this->assertStringContains("8\u{2013}12 days", $html, 'and so does Economy');
    }

    /** `Board::rows()` still answers for every active destination. */
    public function testEveryActiveDestinationStillGetsARow(): void
    {
        $rows = Board::rows($this->docWithHoleAtOneKg());

        $this->assertSame(['ca', 'au', 'zz'], array_map(static fn (object $r): string => $r->id, $rows));
    }
}
