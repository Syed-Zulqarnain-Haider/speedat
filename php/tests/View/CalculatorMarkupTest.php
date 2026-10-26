<?php

declare(strict_types=1);

namespace Speedat\Tests\View;

use Speedat\View\CalculatorData;
use Speedat\View\HoldData;
use Speedat\View\HomeView;
use Speedat\View\PageView;

/**
 * The home page's markup contract with `public/assets/js/calculator.js`
 * (php/docs/assets.md section 6), the first screen without JavaScript, and the
 * hold state's one promise: no price reaches the browser.
 */
final class CalculatorMarkupTest extends ViewTestCase
{
    /** @param array<string,mixed> $hold */
    private function home(array $hold = [], array $overrides = []): string
    {
        $doc  = $this->doc($overrides, $hold);
        $page = $this->pageView($doc, '/', 'Get a price', ['scripts' => PageView::CALCULATOR_SCRIPTS]);

        return $this->view()->render('site/home', ['view' => HomeView::build($page)]);
    }

    public function testTheMountAndTheBlobAreWhatTheScriptLooksFor(): void
    {
        $html = $this->home();

        $this->assertStringContains('<div id="quote-instrument" class="instrument">', $html);
        $this->assertStringContains('<div data-calculator="speedat-calculator">', $html);
        $this->assertStringContains('<script type="application/json" id="speedat-calculator">', $html);
    }

    public function testTheScriptsAreInTheOneOrderThatWorks(): void
    {
        $html = $this->home();

        $pricing    = strpos($html, 'js/pricing.js');
        $ui         = strpos($html, 'js/ui.js');
        $calculator = strpos($html, 'js/calculator.js');

        $this->assertTrue(is_int($pricing) && is_int($ui) && is_int($calculator), 'all three scripts are on the page');
        $this->assertTrue($pricing < $ui, 'pricing.js must run before ui.js');
        $this->assertTrue($ui < $calculator, 'ui.js must run before calculator.js - calculator.js boots through UI.ready');
        $this->assertStringContains('<script defer src="/assets/js/calculator.js', $html, 'the scripts must be deferred');
    }

    public function testTheBlobParsesAndCarriesTheDocumentTheScriptNeeds(): void
    {
        $raw = $this->jsonBlob($this->home(), 'speedat-calculator');
        $this->assertNotNull($raw, 'the calculator blob is missing');

        /** @var array<string,mixed>|null $blob */
        $blob = json_decode((string) $raw, true);
        $this->assertTrue(is_array($blob), 'the calculator blob did not parse as JSON');

        $this->assertSame('/assets/flags/', $blob['flagsBase'] ?? null);
        $this->assertSame('/api/quotes', $blob['quotesUrl'] ?? null);
        $this->assertSame(['Lahore'], $blob['cities'] ?? null);
        // Every destination id in site.destinations must have a flags entry, or
        // a country that has a flag file gets the two-letter badge instead.
        $ids = array_map(static fn (array $d): string => (string) $d['id'], $blob['site']['destinations']);
        foreach ($ids as $id) {
            $this->assertTrue(array_key_exists($id, $blob['flags']), 'no flag entry for ' . $id);
        }
        $this->assertSame('ca', $blob['flags']['ca'] ?? null);
        $this->assertTrue(array_key_exists('zz', $blob['flags']), 'zz has no flag entry');
        $this->assertNull($blob['flags']['zz'], 'an unknown country must map to null, which means the badge');
        // Inactive destinations are left out: the engine ignores them and the
        // smaller blob is the whole benefit.
        $this->assertFalse(in_array('de', $ids, true), 'an inactive destination reached the browser');
        // Numbers must be numbers. A grid price as a string prices wrong.
        $this->assertTrue(is_int($blob['site']['settings']['maxKg']) || is_float($blob['site']['settings']['maxKg']), 'maxKg must be a number');
    }

    /**
     * The encoder is the escaper. A destination named "</script>" must not be
     * able to end the block, which is what JSON_HEX_TAG buys.
     */
    public function testAHostileDocumentCannotEndTheScriptBlock(): void
    {
        $doc  = $this->hostileDoc();
        $page = $this->pageView($doc, '/', 'Get a price', ['scripts' => PageView::CALCULATOR_SCRIPTS]);
        $html = $this->view()->render('site/home', ['view' => HomeView::build($page)]);

        $raw = $this->jsonBlob($html, 'speedat-calculator');
        $this->assertNotNull($raw, 'the blob was cut short - something closed the tag');

        $blob = json_decode((string) $raw, true);
        $this->assertTrue(is_array($blob), 'the hostile blob did not parse');
        // JSON_HEX_TAG turns every `<` and `>` into < / >, so not one
        // angle bracket survives into the block - that is what stops a
        // destination called "</script>" ending it.
        $this->assertStringNotContains('<', (string) $raw, 'JSON_HEX_TAG did not run');
        $this->assertStringNotContains('>', (string) $raw, 'JSON_HEX_TAG did not run');
        $this->assertStringNotContains('&', (string) $raw, 'JSON_HEX_AMP did not run');
        $this->assertStringNotContains("'", (string) $raw, 'JSON_HEX_APOS did not run');
        // ... and nothing was dropped on the way: the script still receives the
        // name it has to print, hex-escaped and whole.
        $this->assertSame(self::HOSTILE, $blob['site']['company']['name'] ?? null, 'the value did not survive the encoder');
        $this->assertNoInjection($html, 'home with a hostile document');
    }

    public function testTheFirstScreenIsServerRenderedDownToThePrices(): void
    {
        $html = $this->home();

        // The headline is generated from the rate document. Its sub-line is
        // NOT: the owner had the automatic "1 kg from PKR X · N to M days"
        // promise removed (2026-10-11, app/View/HomeView.php), so with no
        // heroSub typed in Admin there is no lede at all.
        $this->assertStringContains('<h1>Lahore to Canada, Australia and Narnia.</h1>', $html);
        $this->assertStringNotContains('1 kg from PKR', $html);
        $this->assertStringNotContains('<p class="lede">', $html);
        // The proof row: the owner's `Label | Value` lines as "Value Label",
        // joined by a middle dot - and nothing at all when he set none, because
        // the one thing the brief forbids outright is an invented count.
        $this->assertStringContains('<p class="stats">3 countries · 0.5 kg minimum</p>', $html);
        // Step 1: the tiles, with the flag, the name and the from-price.
        $this->assertStringContains('<div class="ctiles" role="radiogroup" aria-label="Country">', $html);
        // Each tile states its country and from-price outright for screen
        // readers, the days range read as "to" rather than "minus".
        $this->assertStringContains('<button type="button" role="radio" class="ctile" aria-checked="false" aria-label="Canada, from PKR 4,600, 8 to 12 days">', $html);
        $this->assertStringContains('src="/assets/flags/ca.svg', $html);
        $this->assertStringContains('<span class="ctile-name">Canada</span>', $html);
        $this->assertStringContains('<span class="ctile-from">from PKR 4,600', $html);
        $this->assertStringContains("<span class=\"ctile-days\"> \u{00B7} 8\u{2013}12 days</span>", $html);
        // A country with no flag file gets the badge, not a broken image.
        $this->assertStringContains('<span class="flag flag-badge" style="width:32px;height:24px" aria-hidden="true">NA</span>', $html);
        // Step 2: the weight buttons the rate card can actually price.
        $this->assertStringContains('<div class="kgs" role="group" aria-label="Weight">', $html);
        $this->assertStringContains('<button type="button" class="kg" aria-pressed="false"><strong>1</strong><span>kg</span></button>', $html);
        $this->assertStringContains('<button type="button" class="kg more" aria-pressed="false">Over 25 kg</button>', $html);
        // The way out to the detailed quote. It read "Documents · box size" until
        // the document rate left the product on 2026-09-27; box sizes and several
        // pieces are what is behind it now, and public/assets/js/calculator.js
        // must print the SAME label or the row reflows when the script boots.
        $this->assertStringContains('<button type="button" class="kg other">Box size · many pieces</button>', $html);
        // Step 3's slot, reserved so nothing moves when the price lands.
        $this->assertStringContains('<div class="result" data-state="country" data-n="2">', $html);
        $this->assertStringContains('<p class="wait" aria-live="polite">Tap a country</p>', $html);
        // The fine print the quick view carries.
        $this->assertStringContains('<p class="sample">Sample prices for now.', $html);
        $this->assertStringContains('<p class="promise">We confirm every price on WhatsApp before we collect.</p>', $html);
    }

    public function testTheBoardAndTheTilesAgreeToTheRupee(): void
    {
        $html = $this->home();

        // Canada, Economy, 1 kg: 4,100 + the 500 pickup add-on = PKR 4,600 -
        // the same figure the tile prints as "from".
        $this->assertStringContains('<span class="ctile-from">from PKR 4,600', $html);
        $this->assertStringContains("<b>PKR 4,600</b><span>8\u{2013}12 days</span>", $html);
        $this->assertStringContains('<caption><strong>Rates</strong>', $html);
        $this->assertStringContains('pickup and service charges PKR', $html);

        // No Documents column and no document clause in the caption: the flat
        // document rate left the product on 2026-09-27 (owner).
        $this->assertStringNotContains('<th scope="col">Documents</th>', $html);
        $this->assertStringNotContains('documents up to', $html);
    }

    public function testWithoutJavascriptThePageOffersWhatsappAndThePhoneInsteadOfDeadControls(): void
    {
        $html = $this->home();

        // The way out is inside <noscript>, so it costs a scripted visitor nothing.
        $this->assertStringContains('<noscript>', $html);
        $this->assertStringContains('Send us the country and the weight and we will price it by hand.', $html);
        $this->assertStringContains('<a class="btn book giant" href="https://wa.me/923157667076"', $html);
        $this->assertStringContains('Or call <a href="tel:+924235771234">', $html);
        // The controls that would do nothing are taken off, and the tiles - which
        // are real information - are only made inert.
        $this->assertStringContains('.instrument .kgs,.instrument [data-step="weight"]', $html);
        $this->assertStringContains('.instrument .ctile{pointer-events:none', $html);
        // A rule whose selector matches nothing is a rule that does nothing, so
        // every hook those selectors name must be in the instrument - including
        // `.result > .wait` as a DIRECT child, which is what the `>` needs.
        $instrument = $this->instrument($html);
        foreach (['class="kgs"', 'data-step="weight"', 'class="ctile"'] as $hook) {
            $this->assertStringContains($hook, $instrument, 'the no-script stylesheet names a hook the markup does not have');
        }
        // The fifth hook only exists on a document with more countries than the
        // grid has cells, so it is checked against one.
        $wide = [];
        for ($i = 0; $i < 12; $i++) {
            $wide[] = ['id' => 'x' . $i, 'name' => 'Country ' . $i, 'active' => true, 'rates' => ['exp' => ['days' => '5', 'grid' => ['1' => 6000]]]];
        }
        $this->assertStringContains('class="ctile-more"', $this->instrument($this->home([], ['destinations' => $wide])));
        $this->assertTrue(
            preg_match('#<div class="result"[^>]*>\s*<p class="wait"#', $instrument) === 1,
            '.result > .wait must be a direct child, or the no-script rule hides nothing'
        );
        // And the way out is INSIDE the instrument, so it lands where the dead
        // controls were rather than somewhere else on the page.
        $this->assertStringContains('<noscript>', $instrument);
    }

    /** Just `#quote-instrument`'s markup, so a rule about it cannot pass on the rest of the page. */
    private function instrument(string $html): string
    {
        $start = strpos($html, '<div id="quote-instrument"');
        $this->assertTrue($start !== false, 'the instrument is missing');
        $end = strpos($html, '<table class="rates">', (int) $start);

        return substr($html, (int) $start, $end === false ? strlen($html) : $end - (int) $start);
    }

    /* ------------------------------------------------------------- hold */

    public function testTheHoldPanelReplacesTheCalculatorEntirely(): void
    {
        $html = $this->home(['on' => true, 'message' => 'We are updating our airline rates today.']);

        $this->assertStringContains('<div data-hold-panel="speedat-hold">', $html);
        $this->assertStringContains('class="panel quick hold-panel"', $html);
        $this->assertStringContains('Prices are being updated', $html);
        $this->assertStringContains('We are updating our airline rates today.', $html);
        // The apostrophe is the plain one, because HoldPanel.tsx writes `&apos;`
        // and a curly quote would be this port inventing typography.
        $this->assertStringContains('Ask for today&#039;s rate on WhatsApp', $html);
        $this->assertStringNotContains('data-calculator=', $html);
        $this->assertStringNotContains('id="speedat-calculator"', $html);
        $this->assertStringNotContains('class="ctiles"', $html);
        $this->assertStringNotContains('class="kgs"', $html);
    }

    /**
     * The one promise of the hold: NO price may appear anywhere in the HTML.
     *
     * Not hidden, not nulled after the fact - absent. The check is deliberately
     * blunt: the currency code must not appear next to a number anywhere in the
     * document, and none of the figures the rate card holds may be findable.
     */
    public function testNoPriceAppearsAnywhereInTheHtmlWhilePricesAreHeld(): void
    {
        $html = $this->home(['on' => true, 'message' => 'Back this afternoon.']);

        $this->assertSame(
            0,
            preg_match_all('/PKR\s*[0-9]/', $html),
            'a currency figure reached the page while prices were on hold'
        );

        foreach (['5,220', '4,100', '5220', '4100', '3,200', '43000'] as $figure) {
            $this->assertStringNotContains($figure, $html, 'a rate from the document reached the page under hold');
        }

        // The rate document itself must not be in the blob either.
        $raw = $this->jsonBlob($html, 'speedat-hold');
        $this->assertNotNull($raw, 'the hold blob is missing');
        $blob = json_decode((string) $raw, true);
        $this->assertTrue(is_array($blob), 'the hold blob did not parse');
        $this->assertFalse(array_key_exists('site', $blob), 'the rate document reached the browser under hold');
        $this->assertFalse(array_key_exists('settings', $blob), 'settings reached the browser under hold');
        $this->assertSame(['au', 'ca', 'zz'], array_map(
            static fn (array $d): string => (string) $d['id'],
            array_values(array_filter($blob['destinations'], static fn (array $d): bool => in_array($d['id'], ['ca', 'au', 'zz'], true)))
        ));
    }

    public function testTheBoardStillPrintsTheDaysUnderHoldAndSaysWhyThePricesAreGone(): void
    {
        $html = $this->home(['on' => true, 'message' => 'Back this afternoon.']);

        $this->assertStringContains('<table class="rates">', $html);
        $this->assertStringContains("<span>8\u{2013}12 days</span>", $html);
        $this->assertStringContains('Working days per service · prices are being updated', $html);
        // No Documents column - there is no such column at any time since
        // 2026-09-27, held or not.
        $this->assertStringNotContains('<th scope="col">Documents</th>', $html);
        // And no "Sample prices" caveat - there is nothing on screen to caveat.
        $this->assertStringNotContains('Sample prices for now', $html);
    }

    /**
     * The hero sub-line used to be generated - a price, or under hold "Pickup
     * in Lahore · 3 to 12 days". The owner had the generated line removed
     * (2026-10-11, app/View/HomeView.php): only a heroSub typed in Admin shows.
     * So with prices held and nothing typed there is no lede and no promise.
     */
    public function testTheHeroHasNoGeneratedLineWhenPricesAreHeld(): void
    {
        $html = $this->home(['on' => true, 'message' => 'Back soon.']);

        $this->assertStringNotContains('<p class="lede">', $html);
        $this->assertStringNotContains('Pickup in Lahore · 3 to 12 days', $html);
        $this->assertStringNotContains('from PKR', $html, 'nothing priced is printed under hold');
    }

    public function testAHeroSubTheOwnerTypedStillShows(): void
    {
        $html = $this->home([], ['content' => ['heroSub' => 'Parcels to family abroad, collected from your door.']]);

        $this->assertStringContains('<p class="lede">Parcels to family abroad, collected from your door.</p>', $html);
    }

    /* --------------------------------------------------- the tile split */

    public function testTheGridKeepsItsHeightWhenThereAreMoreCountriesThanCells(): void
    {
        $extra = [];
        for ($i = 0; $i < 12; $i++) {
            $extra[] = [
                'id'     => 'x' . $i,
                'name'   => 'Country ' . $i,
                'active' => true,
                'rates'  => ['exp' => ['days' => '5', 'grid' => ['1' => 6000]]],
            ];
        }

        $doc   = $this->doc(['destinations' => $extra]);
        $split = CalculatorData::tiles($doc);

        $this->assertSame(7, count($split['tiles']), 'the last cell becomes the picker, so only seven tiles');
        $this->assertTrue($split['more']);
    }

    public function testTheMountIdsAreTheOnesTheScriptDefaultsTo(): void
    {
        $this->assertSame('speedat-calculator', CalculatorData::MOUNT_ID);
        $this->assertSame('speedat-hold', HoldData::MOUNT_ID);
    }
}
