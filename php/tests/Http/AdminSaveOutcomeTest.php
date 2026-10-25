<?php

declare(strict_types=1);

namespace Speedat\Tests\Http;

use Speedat\Domain\SiteDoc\Hold;
use Speedat\Domain\SiteDoc\Live;
use Speedat\Domain\SiteDoc\Repo;
use Speedat\Http\Admin\Notice;
use Speedat\View\Admin\Routes;

/**
 * WHAT THE EDITOR SAYS ABOUT A SAVE THAT DID NOT FULLY LAND.
 *
 * Three ways of not being told, all of them found on the working editor and
 * all of them about the same thing - a price the owner typed that is not in
 * the document, with nothing on the screen admitting it:
 *
 *   1. EVERY OUTCOME WAS GREEN. The one-off line under the table was printed
 *      inside `notice ok` whatever it said, so "that was more than the server
 *      will accept in one go, so nothing was saved" arrived in the same box,
 *      the same colour, as "version 12 is live". A refusal the colour of a
 *      success is read as a success, and the owner walks away.
 *
 *   2. THE REVIEW SCREEN DROPPED IT ENTIRELY. "Review and publish" saves and
 *      redirects carrying `?kept=N` - the count of price boxes that did not
 *      hold a number - and the template printed that line only as the ELSE of
 *      the review card. So the one press that most needs the warning was the
 *      one press that never showed it: the owner was given a diff (of the
 *      stored draft, without his prices in it) and a Publish button, and no
 *      word about what had just not saved.
 *
 *   3. THE AUTOSAVE NEVER MENTIONED IT AT ALL. The plain submit carries the
 *      count in the URL; the autosave answers JSON and carried nothing, so
 *      with JavaScript on - which is every owner, every day - the answer was
 *      `{"ok":true}` and the line read "Draft saved". `Notice::kept()` has
 *      said since it was written that a price which silently did not save is
 *      the worst outcome on this screen; on the path everybody uses, that is
 *      exactly what happened.
 *
 * The count itself is not new and is not in question here (`DraftForm::price`
 * keeps the stored value rather than writing NaN over it). What these tests
 * hold down is that the owner is TOLD, on every one of the three paths, in
 * one set of words.
 */
final class AdminSaveOutcomeTest extends AdminTestCase
{
    private const DEST = 'gb';

    public function setUp(): void
    {
        parent::setUp();

        $this->admin();
        $this->signIn();
    }

    /* ------------------------------------------------- 1. the wrong colour */

    /**
     * A refusal is not printed in the box the page uses for good news.
     *
     * `err=toobig` is the truncation guard's own code and the one an owner is
     * most likely to meet, but the branch is shared by every refusal the
     * editor redirects with.
     */
    public function testARefusalIsNotPaintedAsASuccess(): void
    {
        $body = $this->adminGet(Routes::RATES, ['err' => 'toobig'])->body();

        $this->assertStringContains(
            '<div class="notice err">That was more than the server will accept',
            $body,
            'the refusal is not in an error box',
        );
        $this->assertStringNotContains(
            '<div class="notice ok">That was more than the server will accept',
            $body,
            'nothing was saved, and the page says so in the green box it uses for "version 12 is live"',
        );
    }

    /** The count of boxes that did not save is a warning, not a celebration. */
    public function testTheCountOfBoxesThatDidNotSaveIsAWarning(): void
    {
        $body = $this->adminGet(Routes::RATES, ['kept' => '2'])->body();

        $this->assertStringContains('<div class="notice warn">2 boxes did not hold a number', $body);
        $this->assertStringNotContains('<div class="notice ok">2 boxes did not hold a number', $body);
    }

    /** A publish that worked still gets the green box. */
    public function testAPublishThatWorkedIsStillGood(): void
    {
        $body = $this->adminGet(Routes::RATES, ['published' => '4'])->body();

        $this->assertStringContains('<div class="notice ok">Version 4 is live', $body, 'good news lost its colour');
    }

    /**
     * A ROLL-BACK WHILE PRICES ARE HELD IS NOT SIMPLY GOOD NEWS.
     *
     * `Repo::rollbackTo()` never lifts the hold, so the sentence has to end
     * "customers see no prices until you resume them" - and a caveat printed
     * in the success box is a caveat nobody reads. The words are already
     * `Notice::rolledBack()`'s; this is about which box they land in.
     */
    public function testARollBackUnderAPriceHoldIsNotPaintedAsPlainGoodNews(): void
    {
        Hold::set(true, self::OWNER, 'Rates being updated');
        Live::forget();

        $body = $this->adminGet(Routes::RATES, ['rolledback' => '3'])->body();

        $this->assertStringContains('Prices are still on hold', $body, 'the notice claims customers can see them');
        $this->assertStringContains('<div class="notice warn">Version 3 is live', $body, 'the caveat is in the success box');
    }

    /**
     * A DRAFT CONFLICT IS SAID IN TWO PLACES AND BOTH OF THEM ARE RED.
     *
     * The box names who has the draft and carries the Reload; the outcome line
     * is where every other refusal arrives, so this one belongs there too. It
     * was green: a page that had written nothing at all repeated itself in the
     * same box the same page uses for "version 12 is live".
     */
    public function testADraftConflictIsNotAlsoAnnouncedInAGreenBox(): void
    {
        $body = $this->adminGet(Routes::RATES, ['err' => 'draftmoved'])->body();

        $this->assertStringContains('data-conflict-box', $body, 'the conflict box is not on the page at all');
        $this->assertStringContains(
            '<div class="notice err">Somebody else changed the draft',
            $body,
            'the refusal is not in an error box',
        );
        $this->assertStringNotContains(
            '<div class="notice ok">Somebody else changed the draft',
            $body,
            'a refusal that saved nothing is in the box used for "version 12 is live"',
        );
    }

    /* --------------------------------------------- 2. the review screen */

    /**
     * "Review and publish" with a box that did not hold a number: the page
     * that follows says so.
     *
     * Driven end to end rather than from a hand-made URL, because the two
     * halves each failed on their own account - the redirect has always
     * carried the count, and the screen has never printed it.
     */
    public function testThePricesThatDidNotSaveAreReportedOnTheReviewScreen(): void
    {
        $answer = $this->adminPost(Routes::REVIEW, $this->rowPost(['1' => 'twelve fifty', '2' => 'abc']));

        $this->assertRedirects(Routes::REVIEW, $answer, 'review after save');
        $this->assertStringContains('kept=2', $this->location($answer), 'the redirect does not carry what did not save');

        $body = $this->adminGet(Routes::REVIEW, ['kept' => '2'])->body();

        $this->assertStringContains('2 boxes did not hold a number', $body, 'the review screen never mentions them');
        $this->assertStringContains('Publishing version', $body, 'and the review card itself went missing');
    }

    /** With nothing to report, the review screen is exactly as it was. */
    public function testAnUntroubledReviewScreenSaysNothingExtra(): void
    {
        $body = $this->adminGet(Routes::REVIEW)->body();

        $this->assertStringNotContains('did not hold a number', $body);
        $this->assertStringContains('Publishing version', $body);
    }

    /* ----------------------------------------------------- 3. the autosave */

    /**
     * The autosave says how many boxes it left alone, in the same words the
     * redirect prints.
     */
    public function testTheAutosaveSaysHowManyBoxesDidNotHoldANumber(): void
    {
        $json = $this->adminPost(
            Routes::SAVE,
            $this->rowPost(['1' => 'twelve fifty', '2' => 'abc']),
            ['x-requested-with' => 'fetch'],
        )->json();

        $this->assertTrue(($json['ok'] ?? false) === true, 'the save itself was refused');
        $this->assertSame(2, $json['kept'] ?? -1, 'the autosave does not say how many boxes were left as they were');
        $this->assertSame(
            Notice::keptText(2),
            (string) ($json['message'] ?? ''),
            'the autosave words it differently from the page',
        );
    }

    /**
     * ONE REFUSED BOX IS TALKED ABOUT IN THE SINGULAR.
     *
     * "1 box did not hold a number, so it was left exactly as it was. Check
     * THEM and save again." was survivable while the sentence only appeared
     * after a plain submit. The autosave prints it on every keystroke group,
     * and one refused box is its ordinary case rather than its rare one.
     */
    public function testOneRefusedBoxIsDescribedInTheSingular(): void
    {
        $this->assertSame(
            '1 box did not hold a number, so it was left exactly as it was. Check it and save again.',
            Notice::keptText(1),
        );
        $this->assertStringContains('Check them and save again.', Notice::keptText(2), 'and more than one still is not');
    }

    /** And a clean save says nothing, so the browser knows to take the warning down. */
    public function testACleanAutosaveReportsNothingLeftBehind(): void
    {
        $json = $this->adminPost(
            Routes::SAVE,
            $this->rowPost(['1' => '7300']),
            ['x-requested-with' => 'fetch'],
        )->json();

        $this->assertSame(0, $json['kept'] ?? -1, 'a clean save claims boxes were left behind');
        $this->assertSame('', (string) ($json['message'] ?? 'x'), 'and carries a sentence about them');
    }

    /**
     * THE FINGERPRINT IN THE ANSWER IS THE ONE THIS WRITE MADE.
     *
     * It used to be read back out of the table after the write, which is a
     * different question: between the two, somebody else's save can land, and
     * the page would then adopt a fingerprint it did not make - so its next
     * autosave would be accepted and would write over that colleague's prices,
     * automatically, by the very field that exists to stop it. The race cannot
     * be staged in this harness; what is pinned here is that the answer comes
     * from the write.
     */
    public function testTheAutosaveFingerprintIsTheOneItsOwnWriteMade(): void
    {
        $json = $this->adminPost(Routes::SAVE, $this->rowPost(['1' => '7301']), ['x-requested-with' => 'fetch'])->json();

        $this->assertSame(
            Repo::getDraft()->revision,
            (string) ($json['revision'] ?? ''),
            'the autosave was handed a fingerprint that is not the stored draft',
        );

        // And the next autosave, carrying it, is accepted rather than refused
        // by the one before it.
        $next = $this->adminPost(
            Routes::SAVE,
            $this->rowPost(['1' => '7302']) + ['revision' => (string) $json['revision']],
            ['x-requested-with' => 'fetch'],
        )->json();

        $this->assertTrue(($next['ok'] ?? false) === true, 'the second autosave of a typing session was refused by its own first');
    }

    /**
     * An empty form is refused in the words this application keeps in one
     * place, not in a second set written at the call site.
     */
    public function testTheEmptyFormRefusalIsTheSentenceTheRedirectPathUses(): void
    {
        $json = $this->adminPost(Routes::SAVE, [], ['x-requested-with' => 'fetch'])->json();

        $this->assertTrue(($json['ok'] ?? true) === false, 'an empty form was called a save');
        $this->assertSame(Notice::text('nothing'), (string) ($json['message'] ?? ''), 'two sentences for one refusal');
    }

    /* ------------------------------------------------------------ internals */

    /**
     * One country's post, which is what the editor sends now: the row's name,
     * whether it is shown, and the per-kilogram boxes named here.
     *
     * @param  array<string,string> $grid
     * @return array<string,mixed>
     */
    private function rowPost(array $grid): array
    {
        $name = '';
        foreach (Repo::getDraft()->data->toArray()['destinations'] as $row) {
            if ((string) ($row['id'] ?? '') === self::DEST) {
                $name = (string) ($row['name'] ?? '');
            }
        }

        return ['dest' => [self::DEST => [
            'name'     => $name,
            'position' => '0',
            'active'   => '1',
            'rates'    => ['normal' => ['grid' => $grid]],
        ]]];
    }
}
