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
     * The CHEAPEST service, deliberately.
     *
     * The home page's country tiles print a "from" price, which is the lowest 1
     * kg price across the services - so a test that edited Express would publish
     * a change the public page does not show, and would prove nothing about the
     * round trip. Every price these tests set is therefore below the other
     * service's, whose 1 kg box is 5,600.
     */
    private const SERVICE = 'normal';

    /**
     * WHAT THE TILE PRINTS IS NOT THE BOX THE OWNER TYPED, and every figure
     * below is the printed one.
     *
     * `View\Copy::fromPrice()` is the cheapest service at 1 kg PLUS the add-ons
     * that are on by default, and the seed's `settings.addons` reads "Pickup
     * and service charges | 500 | on". So the seed's 1 kg Normal box of 4,050
     * reaches the home page as 4,550, and a price these tests type reaches it
     * as that figure plus 500. `View\CopyTest` is where that rule is pinned;
     * this test only has to spell the arithmetic out instead of re-deriving it.
     *
     * The typed prices are multiples of `settings.roundTo` (10) on purpose, so
     * the add-on is the ONLY difference between the two numbers and a failure
     * here can never be an argument about rounding.
     */
    private const SEED_FROM = '4,550';

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
        // 3,110 typed + the 500 add-on.
        $this->assertStringContains('3,610', $home->body(), 'the published price is not on the home page');
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

        $this->assertStringContains('name="expectedBase" value="2"', $body, 'the card does not publish against the version it compared with');
        $this->assertStringContains('started from version 1, and version 2 has gone live since', $body, 'the card does not say the draft is behind');

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
        $this->assertStringContains(self::SEED_FROM, $this->get('/')->body(), 'the seed prices are not on the home page to begin with');

        $answer = $this->adminPost(Routes::HOLD, ['on' => '1', 'message' => 'New rates on Monday.']);

        $this->assertRedirects(Routes::RATES, $answer, 'holding prices');
        $this->assertSame(1, $this->audited('hold_on'), 'the hold was not audited');

        $home = $this->get('/')->body();

        $this->assertStringContains('data-hold-panel', $home, 'the hold panel is not on the public page');
        $this->assertStringContains('New rates on Monday.', $home, 'the message the owner wrote is not shown');
        $this->assertStringNotContains(self::SEED_FROM, $home, 'a price survived the hold');
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
        // 3,330 typed + the 500 add-on.
        $this->assertStringContains('3,830', $home, 'the new price is not on the home page');
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
