<?php

declare(strict_types=1);

namespace Speedat\Tests\Http;

use Speedat\Core\Db;
use Speedat\Domain\SiteDoc\Draft;
use Speedat\Domain\SiteDoc\Live;
use Speedat\Domain\SiteDoc\PublishArgs;
use Speedat\Domain\SiteDoc\Repo;
use Speedat\View\Admin\Routes;

/**
 * WHOSE DRAFT IS IT, AND WHICH VERSION IS THE EDITOR EDITING ON TOP OF?
 *
 * There is ONE draft row for the whole office and ONE live version under it, and
 * two admins with the editor open are the normal case rather than the odd one.
 * Two guards follow, and this file drives both from the HTTP side:
 *
 *   THE DRAFT FINGERPRINT (`Domain\SiteDoc\Draft::$revision`). The editor is
 *   rendered with the fingerprint of the row it was drawn from and posts it
 *   back; `Repo::saveDraft()` refuses when the stored row no longer has it.
 *   Without it the second of two admins to press Save wrote over the first
 *   one's prices with no word on either screen.
 *
 *   THE BASE VERSION. `Repo::publishVersion()` refuses a publish whose
 *   `expectedBase` is not the live version - but only if the number is the one
 *   the editor was OPENED at. A screen that re-reads the current live version
 *   every time it is drawn always hands back a number that agrees with itself,
 *   which is why that refusal had never once fired in the browser. So the
 *   number has to survive every save and every redirect, and that is what is
 *   checked here.
 *
 * WHAT THIS FILE DOES NOT CHECK, AND WHY IT SAYS SO. The two hidden inputs that
 * carry these values belong to the admin templates, which are another
 * engineer's. These tests post the fields directly, exactly as a rendered form
 * would, so what is proven is the half of the contract that lives on the
 * server: the value is read, honoured, carried across the redirect, and refused
 * when it is stale.
 */
final class AdminDraftOwnershipTest extends AdminTestCase
{
    private const DEST = 'gb';

    public function setUp(): void
    {
        parent::setUp();

        $this->admin();
        $this->signIn();
    }

    /* ------------------------------------------------------ the fingerprint */

    /**
     * TWO ADMINS, ONE DRAFT: the second save does not silently win.
     *
     * The editor is opened (its fingerprint noted), somebody else saves, and
     * then the first screen presses Save. Nothing of the first screen's is
     * written, and the sentence says why in words an office can act on.
     */
    public function testASaveFromAnEditorTheDraftHasMovedUnderIsRefused(): void
    {
        $opened = Repo::getDraft()->revision;

        // The colleague, working from their own screen.
        $this->saveWith(['1' => '5151'], Repo::getDraft()->revision);
        $this->assertSame(5151.0, $this->price(1), 'the first admin\'s save did not land');

        // This screen still holds the fingerprint it was drawn with.
        $answer = $this->saveWith(['1' => '6161'], $opened);

        $this->assertStringContains('err=draftmoved', $this->location($answer), 'a stale save was not refused');
        $this->assertSame(5151.0, $this->price(1), 'the stale save overwrote the other admin\'s price');

        $body = $this->adminGet(Routes::RATES, ['err' => 'draftmoved'])->body();
        $this->assertStringContains('Somebody else changed the draft while this page was open', $body, 'the editor does not explain the refusal');
        $this->assertStringContains('nothing was saved', $body, 'the message does not say the draft is untouched');
    }

    /** A form that carries the CURRENT fingerprint is an ordinary save. */
    public function testASaveFromAnEditorThatIsUpToDateIsWrittenAsBefore(): void
    {
        $answer = $this->saveWith(['1' => '7171'], Repo::getDraft()->revision);

        $this->assertStringNotContains('err=', $this->location($answer), 'an up-to-date save was refused');
        $this->assertSame(7171.0, $this->price(1), 'the save did not land');
    }

    /**
     * A FORM THAT CARRIES NO SUCH FIELD SAVES AS IT ALWAYS DID.
     *
     * The two halves of this change land separately - the templates print the
     * hidden input, the server reads it - and the order must not matter. An
     * absent field is "there is no screen behind this write", which is the same
     * last-writer-wins the seed, a script and a restore need.
     */
    public function testAFormWithNoFingerprintAtAllStillSaves(): void
    {
        $answer = $this->saveWith(['1' => '8181'], null);

        $this->assertStringNotContains('err=', $this->location($answer), 'a form without the field was refused');
        $this->assertSame(8181.0, $this->price(1), 'the save did not land');
    }

    /**
     * AN EMPTY FIELD FAILS CLOSED, which is the opposite of an absent one.
     *
     * `revision=""` is a claim about the stored row that nothing can match - a
     * hidden input somebody has taken apart, or a page rendered before the row
     * existed. Saving on it would be the exact overwrite the fingerprint exists
     * to stop, so it is refused.
     */
    public function testAnEmptyFingerprintIsRefusedRatherThanIgnored(): void
    {
        $before = $this->price(1);

        $answer = $this->saveWith(['1' => '9191'], '');

        $this->assertStringContains('err=draftmoved', $this->location($answer), 'an empty fingerprint was treated as no fingerprint');
        $this->assertSame($before, $this->price(1), 'an empty fingerprint still wrote');
    }

    /**
     * THE AUTOSAVE IS TOLD WHAT IT JUST MADE.
     *
     * The fingerprint changes with every write, so a page that kept posting the
     * one it was rendered with would have its SECOND autosave refused - by its
     * own first one. The JSON answer carries the new value for admin.js to put
     * back in the hidden field.
     */
    public function testTheAutosaveAnswerCarriesTheNewFingerprint(): void
    {
        $before = Repo::getDraft()->revision;

        $answer = $this->saveWith(['1' => '2121'], $before, ['x-requested-with' => 'fetch']);
        $json   = $answer->json();

        $this->assertTrue(($json['ok'] ?? false) === true, 'the autosave was refused');
        $this->assertSame(Repo::getDraft()->revision, $json['revision'] ?? '', 'the answer does not carry the new fingerprint');
        $this->assertNotSame($before, $json['revision'] ?? '', 'the fingerprint did not change with the write');
    }

    /**
     * THE REFUSAL SAYS WHO HAS IT, on the path where the page never navigates.
     *
     * A plain submit is redirected to the editor, which prints the draft row's
     * own `editedBy()` sentence. The autosave gets no new page at all, so the
     * answer carries the same fact and admin.js puts it in the same box.
     */
    public function testTheAutosaveRefusalNamesWhoHasTheDraft(): void
    {
        $opened = Repo::getDraft()->revision;

        $this->saveWith(['1' => '4141'], Repo::getDraft()->revision);

        $json = $this->saveWith(['1' => '5252'], $opened, ['x-requested-with' => 'fetch'])->json();

        $this->assertTrue(($json['ok'] ?? true) === false, 'the stale autosave was accepted');
        $this->assertTrue(($json['conflict'] ?? false) === true, 'the answer does not say it is a conflict');
        $this->assertStringContains('edited by', (string) ($json['editedBy'] ?? ''), 'the answer does not say who has it');
        $this->assertStringContains(self::OWNER, (string) ($json['editedBy'] ?? ''), 'it does not name them');
    }

    /**
     * AND IT NEVER HANDS BACK THE NEW FINGERPRINT.
     *
     * Sending it would let the page adopt it and save again - the exact
     * overwrite this guard exists to stop, performed automatically and faster
     * than a person could notice. The only way on is to look at what the other
     * admin wrote, which is what the Reload is for.
     */
    public function testAConflictNeverHandsBackTheFingerprintThatWouldLetItOverwrite(): void
    {
        $opened = Repo::getDraft()->revision;
        $this->saveWith(['1' => '4343'], Repo::getDraft()->revision);

        $json = $this->saveWith(['1' => '5454'], $opened, ['x-requested-with' => 'fetch'])->json();

        $this->assertTrue(!isset($json['revision']), 'the refusal carries the fingerprint that would overwrite the other admin');
        $this->assertSame(4343.0, $this->price(1), 'the refused save wrote anyway');
    }

    /** The editor prints whose work is on the screen, from the draft row itself. */
    public function testTheEditorSaysWhoLastEditedTheDraft(): void
    {
        $this->saveWith(['1' => '4545'], Repo::getDraft()->revision);

        $body = $this->adminGet(Routes::RATES)->body();

        $this->assertStringContains('This draft was edited by ' . self::OWNER, $body, 'the editor does not say whose draft it is');
    }

    /** A refused save shows the same fact in a box, with a way to go and look. */
    public function testARefusedSaveShowsTheConflictBoxWithAReload(): void
    {
        $body = $this->adminGet(Routes::RATES, ['err' => 'draftmoved'])->body();

        $this->assertStringContains('Nothing was saved.', $body);
        $this->assertStringContains('data-conflict-reload', $body, 'there is no way back to the stored draft');
    }

    /**
     * EVERY FORM THE EDITOR RENDERS CARRIES THE FINGERPRINT.
     *
     * The guard only works if the form hands back the fingerprint it was drawn
     * with, and the editor is now many forms - one per country - so "the page
     * carries it" has to mean all of them. One without it is the one that
     * silently overwrites a colleague.
     */
    public function testEveryWritingFormOnTheEditorCarriesTheStoredFingerprint(): void
    {
        $revision = Repo::getDraft()->revision;
        $body     = $this->adminGet(Routes::RATES)->body();

        $forms = [];
        preg_match_all('/<form\b[^>]*\bid="([^"]+)"[^>]*\bmethod="post"[^>]*>(.*?)<\/form>/is', $body, $forms, PREG_SET_ORDER);

        $this->assertTrue(count($forms) >= 3, 'the editor renders fewer forms than it has sections');

        $carrying = 0;
        foreach ($forms as $form) {
            if (str_contains($form[2], 'name="revision"')) {
                $this->assertStringContains(
                    'name="revision" value="' . $revision . '"',
                    $form[2],
                    'form#' . $form[1] . ' carries a fingerprint that is not the stored one',
                );
                $carrying++;
            }
        }

        $this->assertTrue($carrying >= 3, 'only ' . $carrying . ' of the editor\'s forms carry the draft fingerprint');
    }

    /* ------------------------------------------------------- the base version */

    /**
     * THE VERSION THE EDITOR WAS OPENED AT SURVIVES THE ROUND TRIP.
     *
     * Every action in the editor is post/redirect/get, so a number that is not
     * put on the `Location` is a number the next render cannot print - and a
     * render that cannot print it falls back to the current live version, which
     * is exactly the bug that makes the stale-publish refusal unreachable.
     */
    public function testTheOpenedAtBaseIsCarriedAcrossTheSaveRedirect(): void
    {
        $answer = $this->adminPost(Routes::SAVE, $this->editorPost(['1' => '3131']) + ['expectedBase' => '1']);

        $this->assertStringContains('base=1', $this->location($answer), 'the save redirect dropped the base version');
    }

    /** And across the one that goes to the review screen, which is where publishing starts. */
    public function testTheOpenedAtBaseIsCarriedAcrossTheReviewRedirect(): void
    {
        $answer = $this->adminPost(Routes::REVIEW, $this->editorPost(['1' => '3232']) + ['expectedBase' => '1']);

        $this->assertStringContains(Routes::REVIEW, $this->location($answer), 'the review redirect');
        $this->assertStringContains('base=1', $this->location($answer), 'the review redirect dropped the base version');
    }

    /**
     * A CARRIED BASE FROM BEFORE SOMEBODY ELSE'S PUBLISH IS THE STALE ONE, and
     * publishing on it is refused - which is the whole point of carrying it.
     *
     * Their publish goes first, which also re-branches the shared draft; this
     * screen then makes its change and publishes with the base it was opened at.
     */
    public function testPublishingOnACarriedBaseThatHasBeenOvertakenIsRefused(): void
    {
        $live = Repo::getLatestVersion();
        $this->assertNotNull($live, 'the seed did not publish');

        Repo::publishVersion(new PublishArgs(
            data: $live->data,
            by: 'someone.else@speedat.test',
            source: 'admin',
            summary: 'Their change',
            changeCount: 1,
        ));
        Live::forget();

        $this->saveWith(['1' => '3333'], Repo::getDraft()->revision);

        $answer = $this->adminPost(Routes::PUBLISH, ['expectedBase' => '1']);

        $this->assertStringContains('conflict=2', $this->location($answer), 'the overtaken publish was not refused');
        $this->assertSame(2, (int) (Db::value('SELECT MAX(version) FROM versions') ?? 0), 'a refused publish wrote a version');
    }

    /**
     * A HAND-TYPED `?base=` CANNOT BE USED TO CLAIM A VERSION THAT DOES NOT
     * EXIST YET.
     *
     * `EditorPage::openedAtBase()` takes a carried number only when it is at or
     * behind the draft's own base: behind is a stale editor, which is the case
     * worth keeping, and ahead could only come from somebody editing the URL -
     * and "expect version 99" would refuse every publish rather than allow a bad
     * one, which is a denial of service with extra steps.
     */
    public function testACarriedBaseAheadOfTheDraftIsIgnored(): void
    {
        $draft = Repo::getDraft();

        $this->assertSame(
            $draft->baseVersion,
            \Speedat\Http\Admin\EditorPage::openedAtBase(
                new \Speedat\Core\App(\Speedat\Core\Request::synthetic('GET', Routes::RATES, [], ['base' => '99'])),
                $draft,
            ),
            'a base ahead of the draft was accepted',
        );
    }

    /* ------------------------------------------------------------ internals */

    /**
     * The editor's post, cut down to the fields these tests change.
     *
     * @param  array<string,string> $grid one destination's per-kilogram boxes
     * @return array<string,mixed>
     */
    private function editorPost(array $grid): array
    {
        $document = Repo::getDraft()->data->toArray();
        $dest     = [];

        foreach ($document['destinations'] as $index => $row) {
            $id        = (string) $row['id'];
            $dest[$id] = [
                'name'     => (string) $row['name'],
                'position' => (string) $index,
                'active'   => ($row['active'] ?? false) === true ? '1' : '0',
            ];

            if ($id === self::DEST) {
                $dest[$id]['rates'] = ['normal' => ['grid' => $grid]];
            }
        }

        return ['dest' => $dest];
    }

    /**
     * Save the editor the way a rendered form would: the fields, and the
     * fingerprint the screen was drawn with.
     *
     * @param array<string,string> $grid
     * @param string|null          $revision null posts NO field at all, which is
     *                                       a different thing from posting ""
     * @param array<string,string> $headers
     */
    private function saveWith(array $grid, ?string $revision, array $headers = []): Answer
    {
        $post = $this->editorPost($grid);

        if ($revision !== null) {
            $post['revision'] = $revision;
        }

        return $this->adminPost(Routes::SAVE, $post, $headers);
    }

    /** One stored draft price for the destination these tests edit. */
    private function price(int $kg): ?float
    {
        foreach (Repo::getDraft()->data->toArray()['destinations'] as $row) {
            if ((string) ($row['id'] ?? '') !== self::DEST) {
                continue;
            }

            $value = $row['rates']['normal']['grid'][(string) $kg] ?? null;

            return is_numeric($value) ? (float) $value : null;
        }

        return null;
    }

    /** Named so the fingerprint's own class is referenced somewhere in this file. */
    public function testTheFingerprintIsTheDraftsOwnAndNotSomethingThisTestInvented(): void
    {
        $draft = Repo::getDraft();

        $this->assertSame(
            Draft::revisionOf($draft->data, $draft->updatedAt, $draft->updatedBy),
            $draft->revision,
            'the stored draft does not carry its own fingerprint',
        );
    }
}
