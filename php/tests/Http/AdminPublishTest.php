<?php

declare(strict_types=1);

namespace Speedat\Tests\Http;

use Speedat\Core\Db;
use Speedat\Core\Money;
use Speedat\Domain\SiteDoc\Live;
use Speedat\Domain\SiteDoc\Repo;
use Speedat\View\Admin\Routes;

/**
 * Publishing, and the price hold - the two actions a customer can see.
 *
 * WHAT MAKES THESE THE MOST IMPORTANT TESTS IN THE ADMIN. Everything else writes
 * a draft nobody outside the office can see. These two change what a customer is
 * quoted, immediately, so each one is checked BY LOOKING AT THE PUBLIC PAGE
 * rather than at the admin's own answer: the price the owner typed appears on the
 * home page after a publish, and disappears from it when prices are held.
 *
 * THE REFUSALS ARE CHECKED THE SAME WAY. A publish on top of a version that moved
 * underneath the review is refused with the sentence the TypeScript used, and
 * nothing is written; a document that cannot price a parcel is refused with the
 * reason; and an editor cannot do either (`AdminGuardTest`).
 */
final class AdminPublishTest extends AdminTestCase
{
    private const DEST = 'gb';

    /**
     * The service these tests edit. Every price they set is below Express's
     * 1 kg box (5,600), so it is also the cheapest service - which keeps the
     * test meaningful whichever cheapest-price summary the home page carries.
     */
    private const SERVICE = 'normal';

    /**
     * WHERE THE ROUND TRIP IS READ. The home page used to be checked for the
     * country tile's "from" price; the live site no longer prints one there,
     * so these tests read the two things the public page is built from:
     *
     *   1. THE RATES BOARD (`View\BoardView`) - the United Kingdom row's
     *      Normal cell, server-rendered and visible without JavaScript.
     *   2. THE CALCULATOR DATA - the published document in
     *      <script id="speedat-calculator">, which is what pricing.js quotes
     *      every customer from. Its 1 kg box must be exactly the typed price.
     *
     * WHAT THE BOARD PRINTS IS NOT THE BOX THE OWNER TYPED: it is the 1 kg
     * price PLUS the add-ons that are on by default, and the seed's
     * `settings.addons` reads "Delivery | 1000 | on". So the seed's 1 kg
     * Normal box of 4,050 reaches the board as 5,050, and a price these tests
     * type reaches it as that figure plus 1,000.
     *
     * The typed prices are multiples of `settings.roundTo` (10) on purpose, so
     * the add-on is the ONLY difference between the two numbers and a failure
     * here can never be an argument about rounding.
     */
    private const SEED_BOX = 4050;

    private const ADDON_ON = 1000;

    public function setUp(): void
    {
        parent::setUp();

        $this->admin();
        $this->signIn();
    }

    public function testPublishingPutsTheNewPriceOnThePublicSite(): void
    {
        $this->editPrice('3110');

        $answer = $this->adminPost(Routes::PUBLISH, ['expectedBase' => '1', 'goLive' => '1']);

        $this->assertRedirects(Routes::RATES, $answer, 'the publish');
        $this->assertStringContains('published=2', $this->location($answer), 'the redirect does not name the version');
        $this->assertSame(2, $this->liveVersion(), 'no version was published');

        // The customer's page, read through the real routing table.
        $home = $this->get('/');

        $this->assertSame(200, $home->response->status, 'the home page after a publish');
        $this->assertPublicPrice(3110, $home->body(), 'after the publish');
        $this->assertStringNotContains('Sample prices for now', $home->body(), 'the sample notice survived "Rates are live"');

        $detail = $this->auditDetail('publish');

        $this->assertSame(2, $detail['version'] ?? null, 'the audit line does not name the version');
        $this->assertTrue(is_array($detail['changes'] ?? null) && $detail['changes'] !== [], 'the audit line carries no change list');
        $this->assertTrue(is_string($detail['summary'] ?? null) && $detail['summary'] !== '', 'the audit line carries no summary');
    }

    public function testTheEditorSaysWhichVersionIsLiveAfterAPublish(): void
    {
        $this->editPrice('3222');
        $this->adminPost(Routes::PUBLISH, ['expectedBase' => '1']);

        $body = $this->adminGet(Routes::RATES, ['published' => '2'])->body();

        $this->assertStringContains('Version 2 is live', $body, 'the editor does not say what happened');
        $this->assertStringContains('No unpublished changes', $body, 'the draft still looks dirty after a publish');
    }

    /**
     * The state speedat.co was stuck in from 2026-10-16: a draft row branched
     * from version 1 while version 2 is live. Every fresh sign-in opened the
     * editor "at" 1, so every publish was refused as a conflict and reloading
     * changed nothing. The review card must offer a publish that goes through.
     */
    public function testADraftBranchedFromAnOlderVersionCanStillBePublished(): void
    {
        Repo::publishVersion(new \Speedat\Domain\SiteDoc\PublishArgs(
            data: Repo::getLatestVersion()->data,
            by: 'someone.else@speedat.test',
            source: 'admin',
            summary: 'Their change',
            changeCount: 1,
        ));
        Live::forget();
        $this->editPrice('3333');
        Db::exec('UPDATE draft SET base_version = 1');

        $body = $this->adminGet(Routes::REVIEW)->body();

        // Opening the review caught the draft up onto version 2
        // (`Repo::catchUpDraft()`), so the editor says what it did instead of
        // warning about a draft that is no longer behind.
        $this->assertStringContains('name="expectedBase" value="2"', $body, 'the card does not publish against the version it compared with');
        $this->assertStringContains('Version 2 went live after this draft was started from version 1', $body, 'the editor does not say the draft was carried onto version 2');
        $this->assertStringNotContains('has gone live since', $body, 'the card still warns about a draft that was just caught up');
        $this->assertSame(2, (int) Db::value('SELECT base_version FROM draft'), 'opening the editor did not catch the draft up');

        $answer = $this->adminPost(Routes::PUBLISH, ['expectedBase' => '2']);

        $this->assertRedirects(Routes::RATES, $answer, 'the publish');
        $this->assertSame(3, $this->liveVersion(), 'the publish from a behind draft did not land');
        $this->assertSame(3, (int) Db::value('SELECT base_version FROM draft'), 'the draft was not re-based onto the new version');
    }

    public function testPublishingOnTopOfSomebodyElsesVersionIsRefusedWithTheSameSentence(): void
    {
        // Somebody else published while this review was on screen.
        //
        // THEIR PUBLISH GOES FIRST, and that ordering is the point. There is one
        // draft row for the whole office, and `Repo::publishVersion()` resets it
        // to what was just published - so an edit made BEFORE their publish is
        // not in the draft any more, and the publish that follows is refused for
        // having nothing to say rather than for being out of date. The sequence
        // that actually reaches the conflict is the real one: they publish, this
        // admin's autosave writes the draft again, and the publish bar still
        // carries the `expectedBase` the screen was rendered with.
        Repo::publishVersion(new \Speedat\Domain\SiteDoc\PublishArgs(
            data: Repo::getLatestVersion()->data,
            by: 'someone.else@speedat.test',
            source: 'admin',
            summary: 'Their change',
            changeCount: 1,
        ));
        Live::forget();

        $this->assertSame(2, $this->liveVersion(), 'the other publish did not land');

        $this->editPrice('20001');

        $answer = $this->adminPost(Routes::PUBLISH, ['expectedBase' => '1']);

        $this->assertRedirects(Routes::REVIEW, $answer, 'the refused publish');
        $this->assertStringContains('conflict=2', $this->location($answer), 'the redirect does not carry the conflict');
        $this->assertSame(2, $this->liveVersion(), 'a conflicting publish wrote a version anyway');

        $body = $this->adminGet(Routes::REVIEW, ['conflict' => '2'])->body();

        $this->assertStringContains(
            'Version 2 was published meanwhile. Reload to see it, then apply your changes again.',
            $body,
            'the conflict is not worded the way the rest of the product words it',
        );
    }

    public function testPublishingNothingIsRefused(): void
    {
        $answer = $this->adminPost(Routes::PUBLISH, ['expectedBase' => '1']);

        $this->assertStringContains('nochange=1', $this->location($answer), 'the redirect');
        $this->assertSame(1, $this->liveVersion(), 'an empty publish wrote a version');
        $this->assertSame(0, $this->audited('publish'), 'an empty publish was audited');

        $this->assertStringContains(
            'There is nothing to publish.',
            $this->adminGet(Routes::REVIEW, ['nochange' => '1'])->body(),
            'the review card does not say why',
        );
    }

    public function testADocumentThatCannotPriceAParcelIsNotPublished(): void
    {
        // An empty WhatsApp number is one of `Validate::errors()`'s blocking
        // problems: the whole site's Book button is a wa.me link.
        $this->saveDraftWith(['company' => ['whatsapp' => '']]);

        $answer = $this->adminPost(Routes::PUBLISH, ['expectedBase' => '1']);

        $this->assertRedirects(Routes::REVIEW, $answer, 'the refused publish');
        $this->assertSame(1, $this->liveVersion(), 'an invalid document was published');
        $this->assertSame(0, $this->audited('publish'), 'a refused publish was audited');

        $body = $this->adminGet(Routes::REVIEW)->body();

        $this->assertStringContains('Fix these before publishing', $body, 'the review card does not block');
        $this->assertStringContains('WhatsApp number must be', $body, 'the review card does not say what is wrong');
        $this->assertStringNotContains('Publish version', $body, 'a blocked review still offers a Publish button');
    }

    /* --------------------------------------------------------------- the hold */

    public function testHoldingPricesTakesThemOffThePublicPageAtOnce(): void
    {
        $this->assertPublicPrice(self::SEED_BOX, $this->get('/')->body(), 'with the seed, before any hold');

        $answer = $this->adminPost(Routes::HOLD, ['on' => '1', 'message' => 'New rates on Monday.']);

        $this->assertRedirects(Routes::RATES, $answer, 'holding prices');
        $this->assertSame(1, $this->audited('hold_on'), 'the hold was not audited');

        $home = $this->get('/')->body();

        $this->assertStringContains('data-hold-panel', $home, 'the hold panel is not on the public page');
        $this->assertStringContains('New rates on Monday.', $home, 'the message the owner wrote is not shown');
        $this->assertStringNotContains($this->boardFigure(self::SEED_BOX), $home, 'a price survived the hold');
        $this->assertStringNotContains('id="speedat-calculator"', $home, 'the calculator data survived the hold');
        $this->assertStringNotContains('assets/js/pricing.js', $home, 'the rate document still reaches the browser while prices are held');

        // And the editor says so, with the way back.
        $editor = $this->adminGet(Routes::RATES)->body();
        $this->assertStringContains('Prices are on hold.', $editor, 'the editor does not say prices are held');
        $this->assertStringContains('Resume showing prices', $editor, 'an owner is not offered the way back');
    }

    public function testPublishingWithTheTickPutsPricesBack(): void
    {
        $this->adminPost(Routes::HOLD, ['on' => '1', 'message' => 'Updating.']);
        $this->editPrice('3330');

        $answer = $this->adminPost(Routes::PUBLISH, ['expectedBase' => '1', 'resume' => '1']);

        $this->assertStringContains('resumed=1', $this->location($answer), 'the redirect does not say prices are back');
        $this->assertSame(1, $this->audited('hold_off'), 'lifting the hold with a publish was not audited');

        $home = $this->get('/')->body();

        $this->assertStringNotContains('data-hold-panel', $home, 'prices are still held after a publish that lifted it');
        $this->assertPublicPrice(3330, $home, 'after the publish that lifted the hold');
    }

    public function testPublishingWithoutTheTickLeavesPricesHeld(): void
    {
        $this->adminPost(Routes::HOLD, ['on' => '1', 'message' => 'Updating.']);
        $this->editPrice('3444');

        $this->adminPost(Routes::PUBLISH, ['expectedBase' => '1']);

        $this->assertStringContains('data-hold-panel', $this->get('/')->body(), 'the publish lifted a hold nobody ticked');
        $this->assertSame(0, $this->audited('hold_off'), 'the hold was recorded as lifted');
    }

    public function testResumingPricesShowsThemAgainWithoutPublishing(): void
    {
        $this->adminPost(Routes::HOLD, ['on' => '1', 'message' => 'Back shortly.']);
        $this->assertStringContains('data-hold-panel', $this->get('/')->body(), 'prices were not held');

        $this->adminPost(Routes::HOLD, ['on' => '0']);

        $this->assertStringNotContains('data-hold-panel', $this->get('/')->body(), 'prices did not come back');
        $this->assertSame(1, $this->liveVersion(), 'resuming prices published something');

        // The message is kept for the next time, as the domain layer promises.
        $this->assertSame('Back shortly.', (string) (Db::unjson(
            Db::value('SELECT v FROM app_settings WHERE k = ?', ['hold'])
        )['message'] ?? ''), 'resuming wiped the message the owner wrote');
    }

    /* ------------------------------------------------------------ internals */

    /** Save a draft whose first grid box for the first service is $price. */
    /** "PKR 5,050": the board's figure for a typed 1 kg box. */
    private function boardFigure(int $box): string
    {
        return 'PKR ' . number_format($box + self::ADDON_ON);
    }

    /**
     * The typed 1 kg price is what the public page shows and quotes from: the
     * board's United Kingdom row prints it (plus the on-by-default add-on) in
     * the Normal column, and the calculator data carries the box itself.
     */
    private function assertPublicPrice(int $box, string $home, string $when): void
    {
        // The board: header order decides which cell is Normal.
        $this->assertSame(1, preg_match('#<table class="rates">.*?<thead>(.*?)</thead>#s', $home, $head), 'no rates board on the home page ' . $when);
        preg_match_all('#<th scope="col">([^<]*)</th>#', $head[1], $cols);
        $column = array_search('Normal', $cols[1], true);
        $this->assertTrue(is_int($column) && $column > 0, 'the board has no Normal column ' . $when);

        $this->assertSame(
            1,
            preg_match('#<span>United Kingdom</span></th>((?:\s*<td>.*?</td>)+)\s*</tr>#s', $home, $row),
            'no United Kingdom row on the board ' . $when
        );
        preg_match_all('#<td>(.*?)</td>#s', $row[1], $cells);
        $this->assertStringContains(
            '<b>' . $this->boardFigure($box) . '</b>',
            (string) ($cells[1][$column - 1] ?? ''),
            'the published price is not on the rates board ' . $when
        );

        // The calculator data the browser quotes from.
        $this->assertSame(
            1,
            preg_match('#<script type="application/json" id="speedat-calculator">(.*?)</script>#s', $home, $blob),
            'no calculator data on the home page ' . $when
        );
        $data = json_decode($blob[1], true);
        $this->assertTrue(is_array($data), 'the calculator data is not JSON ' . $when);
        $found = null;
        foreach ($data['site']['destinations'] ?? [] as $dest) {
            if (($dest['id'] ?? null) === self::DEST) {
                $found = $dest['rates'][self::SERVICE]['grid']['1'] ?? null;
            }
        }
        $this->assertTrue($found !== null && (float) $found === (float) $box, 'the calculator data does not carry the published 1 kg box ' . $when);
    }

    private function editPrice(string $price): void
    {
        $this->saveDraftWith([], $price);
    }

    /**
     * Save a draft directly through the repository, then check the editor agrees.
     *
     * Directly, rather than through the form, because these tests are about
     * publishing rather than about the parser - `AdminRatesTest` covers the form.
     *
     * @param array<string,array<string,string>> $company
     */
    private function saveDraftWith(array $company = [], string $price = ''): void
    {
        $document = Repo::getDraft()->data->toArray();

        if ($price !== '') {
            foreach ($document['destinations'] as $index => $row) {
                if ((string) $row['id'] === self::DEST) {
                    $document['destinations'][$index]['rates'][self::SERVICE]['grid']['1'] = (float) $price;
                }
            }
        }

        foreach ($company['company'] ?? [] as $key => $value) {
            $document['company'][$key] = $value;
        }

        Repo::saveDraft(\Speedat\Domain\SiteDoc\Migrate::migrate($document), self::OWNER);

        if ($price !== '') {
            $this->assertStringContains(
                Money::numberToString((float) $price),
                $this->adminGet(Routes::RATES)->body(),
                'the edited price is not in the editor',
            );
        }
    }
}
