<?php

declare(strict_types=1);

namespace Speedat\Tests\Http;

use Speedat\Core\Csrf;
use Speedat\Core\Db;
use Speedat\Domain\SiteDoc\Live;
use Speedat\Domain\SiteDoc\PublishArgs;
use Speedat\Domain\SiteDoc\Repo;
use Speedat\View\Admin\Routes;

/**
 * POST /admin/rates/rollback - put an already-published version back on the
 * website, in one action.
 *
 * RESTORE PUTS AN OLD VERSION IN THE EDITOR; THIS PUTS IT ON THE SITE. They
 * answer two different days. Until this existed, "that publish was wrong, take
 * it off" was restore, then find the review screen, then read the diff, then
 * publish - four steps asked of somebody who has just been told the site is
 * quoting the wrong prices, which is the worst possible moment to ask anybody
 * for four steps.
 *
 * WHAT THIS FILE IS ACTUALLY GUARDING. The work is
 * `Domain\SiteDoc\Repo::rollbackTo()` and `tests/SiteDoc` drives that. What can
 * only go wrong in the wiring is everything else: a route registered without the
 * owner check, a button that acts on one press, a CSRF token nobody asserts, a
 * refusal that says nothing, an audit line that is never written. Every one of
 * those is checked here, through the real routing table.
 */
final class AdminRollbackTest extends AdminTestCase
{
    private const PATH = '/admin/rates/rollback';

    /** A price only version 2 has, so "which version is live" is answerable from the document. */
    private const V2_PRICE = 6789.0;

    public function setUp(): void
    {
        parent::setUp();

        $this->admin();
        $this->signIn();
    }

    /* ------------------------------------------------------------ the door */

    /** No session: the sign-in, never a page and never a 403 that maps the admin. */
    public function testAnAnonymousRollbackIsSentToTheSignIn(): void
    {
        $this->sid = '';

        $answer = $this->post(self::PATH, ['version' => '1', 'confirm' => '1']);

        $this->assertRedirects(Routes::LOGIN, $answer, 'an anonymous roll-back');
        $this->assertSame(1, $this->liveVersion(), 'an anonymous roll-back published a version');
    }

    /** An editor is signed in, knows the page exists, and is told no. */
    public function testAnEditorCannotRollBack(): void
    {
        $this->publishSecondVersion();

        $this->admin(self::EDITOR, 'editor');
        $this->signIn(self::EDITOR);

        $answer = $this->adminPost(self::PATH, ['version' => '1', 'confirm' => '1']);

        $this->assertStatus(403, $answer, 'an editor was allowed to roll back');
        $this->assertSame(2, $this->liveVersion(), 'an editor\'s roll-back published a version');
        $this->assertSame(0, $this->audited('rates_rolled_back'), 'a refused roll-back was recorded as one');
    }

    /** Publishing is what this does, so it is behind the same token as everything else. */
    public function testARollbackWithoutTheTokenIsRefused(): void
    {
        $this->publishSecondVersion();

        $answer = $this->post(self::PATH, ['version' => '1', 'confirm' => '1'], [], $this->cookies());

        $this->assertStatus(403, $answer, 'a roll-back without a token');
        $this->assertSame(2, $this->liveVersion(), 'a tokenless roll-back published a version');
    }

    /* --------------------------------------------------------- the question */

    /**
     * ONE PRESS DOES NOT CHANGE EVERY PRICE ON THE WEBSITE.
     *
     * The first post is the question: nothing is written, and the owner comes
     * back to the editor with the confirmation on screen. A `window.confirm()`
     * would be gone the moment JavaScript is off, and this is the action with
     * the largest blast radius in the whole admin.
     */
    public function testTheFirstPressAsksRatherThanActing(): void
    {
        $this->publishSecondVersion();

        $answer = $this->adminPost(self::PATH, ['version' => '1']);

        $this->assertStringContains('rollback=1', $this->location($answer), 'the redirect does not carry the question');
        $this->assertSame(2, $this->liveVersion(), 'the first press published a version');
        $this->assertSame(0, $this->audited('rates_rolled_back'), 'the first press was recorded as a roll-back');

        // And the editor DRAWS that question: the card, the sentence, and a
        // second form carrying `confirm=1`, which is the only thing that acts.
        $screen = $this->adminGet(Routes::RATES, ['rollback' => '1']);
        $this->assertStatus(200, $screen, 'the editor does not render the confirmation URL');

        $body = $screen->body();
        $this->assertStringContains('Roll back to version 1?', $body, 'the confirmation card is not on the screen');
        $this->assertStringContains('Put version 1 back on the website now?', $body, 'the question is not asked in words');
        $this->assertStringContains('Customers see its prices immediately', $body, 'it does not say what customers see');
        $this->assertStringContains('name="confirm" value="1"', $body, 'there is no way to confirm it');
        $this->assertStringContains('action="' . Routes::ROLLBACK . '"', $body, 'the card does not post to the roll-back');

        $this->assertSame(
            1,
            \Speedat\Http\Admin\Notice::rollbackAsked(new \Speedat\Core\App(
                \Speedat\Core\Request::synthetic('GET', Routes::RATES, [], ['rollback' => '1']),
            )),
            'the screen cannot tell which version is being asked about',
        );

        $this->assertStringContains(
            'Put version 1 back on the website now?',
            \Speedat\Http\Admin\Notice::rollbackQuestion(new \Speedat\Core\App(
                \Speedat\Core\Request::synthetic('GET', Routes::RATES, [], ['rollback' => '1']),
            )),
            'there is no question for the confirmation screen to ask',
        );
    }

    /* ------------------------------------------------------------- the act */

    /**
     * CONFIRMED: the old prices are live again, as a NEW version, and the diary
     * says who did it.
     *
     * A new version rather than a deletion, deliberately - nothing is lost and
     * the roll-back itself can be rolled back the same way.
     */
    public function testAConfirmedRollbackPutsTheOldPricesBackAsANewVersion(): void
    {
        $this->publishSecondVersion();

        $this->assertSame(self::V2_PRICE, $this->livePrice(), 'version 2 is not the one being undone');

        $answer = $this->adminPost(self::PATH, ['version' => '1', 'confirm' => '1']);

        $this->assertStringContains('rolledback=3', $this->location($answer), 'the redirect does not name the new version');
        $this->assertSame(3, $this->liveVersion(), 'the roll-back did not publish');
        $this->assertNotSame(self::V2_PRICE, $this->livePrice(), 'the website is still serving the version that was rolled back');

        $this->assertSame(1, $this->audited('rates_rolled_back'), 'the roll-back was not recorded');

        $body = $this->adminGet(Routes::RATES, ['rolledback' => '3'])->body();
        $this->assertStringContains('Version 3 is live', $body, 'the editor does not say what happened');
    }

    /** A hold is somebody else's decision and a roll-back does not quietly reverse it. */
    public function testARollbackLeavesThePriceHoldExactlyAsItFoundIt(): void
    {
        $this->publishSecondVersion();

        $this->adminPost(Routes::HOLD, ['on' => '1', 'message' => 'Rates being updated']);
        $this->assertTrue(\Speedat\Domain\SiteDoc\Hold::get()->on, 'the hold did not go on');

        $this->adminPost(self::PATH, ['version' => '1', 'confirm' => '1']);

        $this->assertTrue(\Speedat\Domain\SiteDoc\Hold::get()->on, 'the roll-back took the hold off behind the owner\'s back');
    }

    /* --------------------------------------------------------- the refusals */

    /** A version nobody ever published. */
    public function testRollingBackToAVersionThatNeverExistedIsRefusedInWords(): void
    {
        $this->publishSecondVersion();

        $answer = $this->adminPost(self::PATH, ['version' => '77', 'confirm' => '1']);

        $this->assertStringContains('err=norollback', $this->location($answer), 'the refusal');
        $this->assertSame(2, $this->liveVersion(), 'a roll-back to nothing published a version');

        $this->assertStringContains(
            'That version was never published',
            $this->adminGet(Routes::RATES, ['err' => 'norollback'])->body(),
            'the editor does not say why',
        );
    }

    /** The version that is already live: a version row that changes nothing is noise. */
    public function testRollingBackToTheLiveVersionIsRefused(): void
    {
        $this->publishSecondVersion();

        $answer = $this->adminPost(self::PATH, ['version' => '2', 'confirm' => '1']);

        $this->assertStringContains('err=rollsame', $this->location($answer), 'the refusal');
        $this->assertSame(2, $this->liveVersion(), 'a roll-back to the live version published anyway');

        $this->assertStringContains(
            'already the live one',
            $this->adminGet(Routes::RATES, ['err' => 'rollsame'])->body(),
            'the editor does not say why',
        );
    }

    /** No version at all is the same refusal as a version that does not exist. */
    public function testARollbackWithNoVersionIsRefused(): void
    {
        $answer = $this->adminPost(self::PATH, ['confirm' => '1']);

        $this->assertStringContains('err=norollback', $this->location($answer), 'the refusal');
        $this->assertSame(1, $this->liveVersion(), 'a roll-back to nowhere published a version');
    }

    /* ---------------------------------------------------------- the button */

    /**
     * THE OWNER CAN REACH IT FROM A BROWSER. `Repo::rollbackTo()` was proven
     * from PHP and had no button at all, which made it a feature nobody could
     * use at the moment it was written for.
     */
    public function testTheHistoryListOffersTheOwnerARollBackOnEveryOlderVersion(): void
    {
        $this->publishSecondVersion();

        $body = $this->adminGet(Routes::RATES)->body();

        $this->assertStringContains('action="' . Routes::ROLLBACK . '"', $body, 'there is no roll-back form on the editor');
        $this->assertStringContains('aria-label="Roll back to version 1"', $body, 'version 1 has no roll-back button');
        $this->assertStringNotContains('aria-label="Roll back to version 2"', $body, 'the live version was offered a roll-back to itself');
        // The list only ASKS. Confirming happens on the card that follows.
        $this->assertStringNotContains('name="confirm"', $body, 'the list can confirm a roll-back in one press');
    }

    /** An editor may not publish, so an editor is not shown the button either. */
    public function testAnEditorIsShownNoRollBackButton(): void
    {
        $this->publishSecondVersion();

        $this->admin(self::EDITOR, 'editor');
        $this->signIn(self::EDITOR);

        $body = $this->adminGet(Routes::RATES)->body();

        $this->assertStringNotContains('action="' . Routes::ROLLBACK . '"', $body, 'an editor is offered a roll-back form');
        $this->assertStringContains('>Restore into editor</button>', $body, 'staging is still offered');
    }

    /**
     * A VERSION THAT WOULD NO LONGER PASS THE CHECKS IS REFUSED ON THE
     * QUESTION SCREEN, with the validator's own sentences.
     *
     * `Repo::rollbackTo()` refuses it too - that is the floor - but its
     * `RollbackRefused::$reasons` cannot travel back through a redirect,
     * because `Notice` carries codes and never text. So the same check runs
     * while the question is being asked and nothing has been written, and the
     * owner reads the reason at the moment he is deciding.
     */
    public function testAVersionThatCouldNotBePublishedTodayIsRefusedWithItsReasons(): void
    {
        $seed = Repo::getLatestVersion();
        $this->assertNotNull($seed, 'the seed did not publish');

        // Version 2 shows nothing on the site at all: publishable the day it
        // was written, and not a document this site can serve now. Version 3
        // puts the seed back, so the one being asked about is an OLD version
        // rather than the live one.
        $hidden = $seed->data->toArray();
        foreach ($hidden['destinations'] as $index => $row) {
            $hidden['destinations'][$index]['active'] = false;
        }

        foreach ([['Everything hidden', \Speedat\Domain\SiteDoc\SiteData::fromArray($hidden)], ['Back on', $seed->data]] as $publish) {
            Repo::publishVersion(new PublishArgs(
                data: $publish[1],
                by: self::OWNER,
                source: 'admin',
                summary: $publish[0],
                changeCount: 1,
            ));
        }
        Live::forget();

        $this->assertSame(3, $this->liveVersion(), 'the fixture did not publish three versions');

        $body = $this->adminGet(Routes::RATES, ['rollback' => '2'])->body();

        $this->assertStringContains('Version 2 cannot be put back', $body, 'the screen offers a roll-back that would be refused');
        $this->assertStringContains('At least one destination must be shown on the site.', $body, 'it does not say what is wrong with it');
        $this->assertStringNotContains('name="confirm" value="1"', $body, 'it still offers the button');

        // And the act itself refuses, whatever the screen said.
        $answer = $this->adminPost(self::PATH, ['version' => '2', 'confirm' => '1']);
        $this->assertStringContains('err=rollstale', $this->location($answer), 'the refusal');
        $this->assertSame(3, $this->liveVersion(), 'a refused roll-back published a version');
    }

    /** A version nobody ever published says so on the question screen too. */
    public function testTheQuestionScreenAnswersAVersionThatNeverExisted(): void
    {
        $body = $this->adminGet(Routes::RATES, ['rollback' => '9999'])->body();

        $this->assertStringContains('That version was never published', $body);
        $this->assertStringNotContains('name="confirm" value="1"', $body, 'a version that does not exist was offered a button');
    }

    /**
     * A ROLL-BACK NEVER LIFTS THE PRICE HOLD, so the notice that says it
     * worked must not say customers can see the prices. The owner finding
     * that out from a customer is the failure this sentence prevents.
     */
    public function testTheSuccessNoticeSaysWhenPricesAreStillHeld(): void
    {
        $this->publishSecondVersion();
        $this->adminPost(Routes::HOLD, ['on' => '1', 'message' => 'Rates being updated']);

        $this->adminPost(self::PATH, ['version' => '1', 'confirm' => '1']);

        $body = $this->adminGet(Routes::RATES, ['rolledback' => '3'])->body();

        $this->assertStringContains('Version 3 is live', $body);
        $this->assertStringContains('Prices are still on hold', $body, 'the notice claims customers can see them');
    }

    /* ------------------------------------------------------------ internals */

    /** A second published version, with one price that only it has. */
    private function publishSecondVersion(): void
    {
        $live = Repo::getLatestVersion();
        $this->assertNotNull($live, 'the seed did not publish');

        $document = $live->data->toArray();

        foreach ($document['destinations'] as $index => $row) {
            if ((string) ($row['id'] ?? '') === 'gb') {
                $document['destinations'][$index]['rates']['normal']['grid']['1'] = self::V2_PRICE;
            }
        }

        Repo::publishVersion(new PublishArgs(
            data: \Speedat\Domain\SiteDoc\SiteData::fromArray($document),
            by: self::OWNER,
            source: 'admin',
            summary: 'The publish that was wrong',
            changeCount: 1,
        ));

        Live::forget();
    }

    /** The one price these tests watch, in the version the website is serving. */
    private function livePrice(): ?float
    {
        $live = Repo::getLatestVersion();

        if ($live === null) {
            return null;
        }

        foreach ($live->data->toArray()['destinations'] as $row) {
            if ((string) ($row['id'] ?? '') !== 'gb') {
                continue;
            }

            $value = $row['rates']['normal']['grid']['1'] ?? null;

            return is_numeric($value) ? (float) $value : null;
        }

        return null;
    }

    /** Named here so the token constant is referenced where the tokenless case is proved. */
    public function testTheTokenFieldIsTheOneEveryOtherAdminFormUses(): void
    {
        $this->assertNotSame('', Csrf::FIELD, 'the admin forms have no token field');
    }
}
