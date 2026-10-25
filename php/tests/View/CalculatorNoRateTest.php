<?php

declare(strict_types=1);

namespace Speedat\Tests\View;

use Speedat\Core\Paths;
use Speedat\Tests\TestCase;

/**
 * THE "RATE NOT AVAILABLE" READOUT, held at source level.
 *
 * The owner's skippable weights (2026-09-28) end in two places the PHP suite
 * cannot execute: `pricing.js`'s engine and `calculator.js`'s readout. The
 * engine half is genuinely tested - the golden vectors run through it on every
 * `bin/test.php` (`tests/js/run-vectors.mjs`), and six of them changed for this
 * decision. The readout half is proved in a real browser, at 1366 and 375, both
 * tiers, with a 3 kg hole punched in United Kingdom.
 *
 * This file is the third thing: the guard that fails in `bin/test.php` when
 * somebody deletes the behaviour while tidying up, months after the browser run
 * that proved it. It is the same kind of assertion, and for the same reason, as
 * `SiteStylesheetTest` - a source statement standing in for a measurement
 * nobody will re-take.
 */
final class CalculatorNoRateTest extends TestCase
{
    private function js(string $file): string
    {
        $src = file_get_contents(Paths::publicDir() . '/assets/js/' . $file);

        $this->assertTrue($src !== false && $src !== '', $file . ' is readable');

        return (string) $src;
    }

    /**
     * THE UPWARD WALK IS GONE FROM THE BROWSER ENGINE TOO. The vectors prove
     * the answers; this proves the loop itself is not sitting there about to be
     * re-entered by a "fix" to some other branch.
     */
    public function testTheBrowserEngineNoLongerWalksUpwardForAPrice(): void
    {
        $src = $this->js('pricing.js');

        $this->assertTrue(
            preg_match('/function gridPrice\(grid, kg, maxKg\) \{(.*?)\n  \}/s', $src, $m) === 1,
            'pricing.js still has gridPrice()',
        );
        $body = $m[1];

        $this->assertTrue(!str_contains($body, 'for ('), 'gridPrice() reads ONE box: no loop walks to a heavier one');
        $this->assertStringContains('if (k > top) return null', $body, 'above the cargo threshold there is no box to read');
        $this->assertStringContains('isNum(p) && p > 0', $body, 'a blank or zero box is still not a price');
    }

    /** The message builder exists and is shaped like the cargo one beside it. */
    public function testTheWhatsAppMessageNamesTheDestinationAndTheWeight(): void
    {
        $src = $this->js('calculator.js');

        $this->assertTrue(
            preg_match('/function noRateText\(companyName, from, dest, weightLine\) \{(.*?)\n  \}/s', $src, $m) === 1,
            'calculator.js has noRateText()',
        );
        $body = $m[1];

        $this->assertStringContains('I need a rate.', $body, 'the message says what is being asked for');
        $this->assertStringContains('"\nTo: " + dest', $body, 'and names the country');
        $this->assertStringContains('"\n" + weightLine', $body, 'and carries the weight the caller worked out');
    }

    /**
     * BOTH TIERS USE IT. The quick view tapped a kilogram and the detailed
     * quote typed one; a weight nobody prices has to answer the same way in
     * both, or the same parcel gets two different answers on one page.
     */
    public function testBothTiersSendTheVisitorToWhatsAppWithThatMessage(): void
    {
        $src = $this->js('calculator.js');

        // Occurrences minus the one that is the definition.
        $this->assertSame(
            2,
            substr_count($src, 'noRateText(') - substr_count($src, 'function noRateText('),
            'noRateText() is CALLED twice - once by the quick view, once by the detailed quote',
        );
        // The quick view: the tapped kilogram travels.
        $this->assertStringContains('noRateText(site.company.name, "", dest.name, "Weight: " + kgv + " kg")', $src, 'the quick view names the tapped weight');
        // The detailed quote: the pieces and the chargeable weight travel, as
        // the cargo path's message does.
        $this->assertStringContains('"Pieces: " + piecesText(res.weights, units)', $src, 'the detailed quote names the pieces');
    }

    /** The quick view says the rate is not available, and the button offers the ask. */
    public function testTheQuickViewSaysTheRateIsNotAvailableAndOffersTheAsk(): void
    {
        $src = $this->js('calculator.js');

        $this->assertStringContains('"No rate for " + kgv + " kg to "', $src, 'the sentence names the weight and the country');
        $this->assertStringContains('bookLabel = "Ask for a rate"', $src, 'the green button says what it does');
        // The bar is the same bar in every state: a figure would be a price for
        // a weight that has none.
        $this->assertStringContains('if (state === "live") sumBox.appendChild(h("span.tval"', $src, 'only the live state prints a figure');
    }

    /**
     * "NO RATE FOR THIS WEIGHT" IS NOT "NO SERVICE TO THIS COUNTRY".
     *
     * The two used to be the same test, because before skippable weights a
     * country with no 1 kg price had no prices at all. A country priced from
     * 2 kg up now has no "from PKR …" on its tile and is served perfectly well,
     * so the quick view must not tell that visitor we do not ship there.
     */
    public function testTheSentenceTellsAnUnpricedWeightApartFromAnUnservedCountry(): void
    {
        $src = $this->js('calculator.js');

        $this->assertStringContains('function servesAnyWeight(site, destId)', $src, 'the question has its own function');
        $this->assertStringContains('note = served[destId()]', $src, 'and the sentence asks it, not fromPrice()');
        $this->assertStringNotContains('note = froms[destId()]', $src, 'the 1 kg price is no longer a proxy for "we ship there"');
        $this->assertStringContains('"No service to "', $src, 'the unserved country still gets its own sentence');

        // It asks the ENGINE what "priced" means, so an engine rule change
        // cannot leave this sentence behind.
        $this->assertTrue(
            preg_match('/function servesAnyWeight\(site, destId\) \{(.*?)\n  \}/s', $src, $m) === 1,
            'servesAnyWeight() is readable',
        );
        $this->assertStringContains('P.gridPrice(rate.grid', $m[1], 'grid mode asks gridPrice(), it does not read boxes itself');
        $this->assertStringContains('P.gridWeights(site.settings)', $m[1], 'over the card\'s own kilograms');
    }

    /** The detailed quote hides an empty "choose a service" rather than instructing the impossible. */
    public function testTheDetailedQuoteDropsTheServiceStepWhenNothingIsPriced(): void
    {
        $src = $this->js('calculator.js');

        $this->assertStringContains('var nothingPriced = res.ok && offered.length === 0;', $src, 'the state has a name');
        $this->assertStringContains('svcsHead.hidden = nothingPriced;', $src, 'the step head goes with the cards');
        $this->assertStringContains('svcsBox.hidden = nothingPriced;', $src);
        // And the cards that DO render are only the priced ones: a service with
        // no price at this weight is not mentioned at all - no dash, no zero.
        $this->assertStringContains('(res.ok ? offered : site.services).forEach(', $src, 'only priced services get a card once the shipment is priced');
    }

    /**
     * NOTHING PRINTS A ZERO OR A DASH IN PLACE OF A MISSING PRICE. The rule
     * predates this change; it is asserted here because a "not available" state
     * is exactly where somebody reaches for an em dash.
     */
    public function testNoPlaceholderStandsInForAMissingPrice(): void
    {
        $src = $this->js('calculator.js');

        foreach (['"PKR 0"', '": —"', '"—"', '" - "'] as $placeholder) {
            $this->assertStringNotContains(
                'svc-price", { text: ' . $placeholder,
                $src,
                'a missing price prints nothing, not ' . $placeholder,
            );
        }
        $this->assertStringContains('text: sp ? fmtMoney(sp.total, cur) : ""', $src, 'an unpriced card prints an empty figure');
    }
}
