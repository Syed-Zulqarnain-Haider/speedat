<?php

declare(strict_types=1);

namespace Speedat\Tests\Http;

use Speedat\Domain\SiteDoc\DraftConflict;
use Speedat\Domain\SiteDoc\Live;
use Speedat\Domain\SiteDoc\PublishArgs;
use Speedat\Domain\SiteDoc\Repo;
use Speedat\Domain\SiteDoc\SiteData;
use Speedat\Http\Admin\Notice;
use Speedat\View\Admin\Csv;
use Speedat\View\Admin\Routes;

/**
 * The rates admin's publish, roll-back, restore, CSV and notice paths - the
 * defects found in the 2026-10-25 review, each pinned where it was found.
 *
 * Every one of these was a screen that did something other than what it said:
 * a Publish that put live more than the card listed, a roll-back that undid a
 * colleague's publish it was never asked about, a Restore that dropped thirty
 * edits on one press, a Copy button that handed out the draft as the rates,
 * and a notice about price boxes that was really about a name. The tests read
 * the outcome out of the database and the redirect, never off the template
 * alone.
 */
final class B4RatesAdminTest extends AdminTestCase
{
    /** The seed's first destination, and the service the roll-back tests watch. */
    private const DEST    = 'gb';
    private const SERVICE = 'normal';

    public function setUp(): void
    {
        parent::setUp();

        $this->admin();
        $this->signIn();
    }

    /* ---------------------------------------------------------- publishing */

    /**
     * THE DRAFT A COLLEAGUE SAVED AFTER THE REVIEW WAS DRAWN DOES NOT GO LIVE.
     *
     * `expectedBase` only watches the live version, and an autosave does not
     * move it - so the owner read three changes and published twenty-three.
     */
    public function testPublishRefusesADraftThatChangedAfterTheReview(): void
    {
        $mine = $this->saveDraftPrice(1111.0, self::OWNER);

        // The colleague, in another browser, after the owner's card was drawn.
        $this->saveDraftPrice(2222.0, self::EDITOR);

        $answer = $this->adminPost(Routes::PUBLISH, ['expectedBase' => '1', 'revision' => $mine]);

        $this->assertStringContains(Routes::REVIEW, $this->location($answer), 'the refusal does not go back to the review');
        $this->assertStringContains('err=reviewmoved', $this->location($answer), 'the refusal has no reason');
        $this->assertSame(1, $this->liveVersion(), 'a draft the card did not list was published');

        $this->assertStringContains(
            'The draft changed after this review was drawn',
            $this->adminGet(Routes::REVIEW, ['err' => 'reviewmoved'])->body(),
            'the review screen does not say why nothing was published',
        );
    }

    /** The fingerprint the card was drawn with is the one that publishes. */
    public function testPublishWithTheReviewedRevisionGoesLive(): void
    {
        $mine = $this->saveDraftPrice(1111.0, self::OWNER);

        $answer = $this->adminPost(Routes::PUBLISH, ['expectedBase' => '1', 'revision' => $mine]);

        $this->assertStringContains('published=2', $this->location($answer), 'the reviewed draft did not publish');
        $this->assertSame(2, $this->liveVersion());
    }

    /** The card prints the fingerprint, once more than the editor without it does. */
    public function testTheReviewCardCarriesTheDraftRevision(): void
    {
        $revision = $this->saveDraftPrice(1111.0, self::OWNER);
        $field    = 'name="revision" value="' . $revision . '" data-revision';

        $editor = substr_count($this->adminGet(Routes::RATES)->body(), $field);
        $review = substr_count($this->adminGet(Routes::REVIEW)->body(), $field);

        $this->assertSame($editor + 1, $review, 'the publish form does not carry the revision it was drawn from');
    }

    /**
     * THE REPOSITORY ASKS AGAIN UNDER THE LOCK, so a save that lands between
     * the controller's comparison and the write is caught too.
     */
    public function testPublishVersionRefusesAStaleRevisionAndWritesNothing(): void
    {
        $draft = Repo::getDraft();

        $this->assertThrows(DraftConflict::class, static fn () => Repo::publishVersion(new PublishArgs(
            data: $draft->data,
            by: self::OWNER,
            source: 'admin',
            summary: 'stale',
            changeCount: 1,
            expectedBase: 1,
            expectedRevision: 'not-the-stored-one',
        )));

        $this->assertSame(1, $this->liveVersion(), 'a refused publish wrote a version');
    }

    /* ---------------------------------------------------------- rolling back */

    /** The question is many screens down; the redirect has to land on it. */
    public function testTheRollBackQuestionLandsOnTheHistory(): void
    {
        $this->publishSecondVersion();

        $answer = $this->adminPost(Routes::ROLLBACK, ['version' => '1']);

        $this->assertStringContains('rollback=1#sec-history', $this->location($answer), 'the question opens at the top of the page');
    }

    /**
     * A CONFIRM DRAWN AGAINST VERSION 2 DOES NOT UNDO VERSION 3.
     *
     * Owner A reads "roll back to version 1" while version 2 is live; owner B
     * publishes a hand-corrected version 3; A confirms. It used to re-read
     * the live version at confirm time and undo B's work in silence.
     */
    public function testAConfirmAgainstAVersionThatIsNoLongerLiveIsRefused(): void
    {
        $this->publishSecondVersion();

        $card = $this->adminGet(Routes::RATES, ['rollback' => '1'])->body();
        $this->assertStringContains('name="expectedLive" value="2"', $card, 'the card does not say which live version it is about');

        // Owner B's publish, while A is reading.
        $this->publishSecondVersion(7777.0);
        $this->assertSame(3, $this->liveVersion());

        $answer = $this->adminPost(Routes::ROLLBACK, ['version' => '1', 'confirm' => '1', 'expectedLive' => '2']);

        $this->assertStringContains('err=rollmoved', $this->location($answer), 'the refusal says nothing');
        $this->assertStringContains('rollback=1', $this->location($answer), 'the question is not asked again');
        $this->assertSame(3, $this->liveVersion(), 'the roll-back undid a publish it was never asked about');
        $this->assertSame(0, $this->audited('rates_rolled_back'), 'a refused roll-back was recorded as one');

        $body = $this->adminGet(Routes::RATES, ['err' => 'rollmoved', 'rollback' => '1'])->body();
        $this->assertStringContains('nothing was rolled back', $body, 'the editor does not print the refusal');
        $this->assertStringContains('name="expectedLive" value="3"', $body, 'the question is not redrawn against the live version');
    }

    /** With the field present and still right, the roll-back goes through as before. */
    public function testAConfirmAgainstTheLiveVersionStillRollsBack(): void
    {
        $this->publishSecondVersion();

        $answer = $this->adminPost(Routes::ROLLBACK, ['version' => '1', 'confirm' => '1', 'expectedLive' => '2']);

        $this->assertStringContains('rolledback=3', $this->location($answer));
        $this->assertSame(3, $this->liveVersion());
    }

    /** "Nothing is lost" is not said over the top of unpublished edits. */
    public function testTheQuestionSaysUnpublishedEditsWillBeReplaced(): void
    {
        $this->publishSecondVersion();
        $this->saveDraftPrice(1234.0, self::OWNER);

        $body = $this->adminGet(Routes::RATES, ['rollback' => '1'])->body();

        $this->assertStringContains('in the editor will be replaced by version 1', $body, 'the card does not say the edits go');
        $this->assertStringNotContains('nothing is lost', $body, 'the card promises nothing is lost over unpublished edits');
    }

    /* ------------------------------------------------------------- restoring */

    /** A colleague's newer draft is refused, not overwritten. */
    public function testRestoreWithAStaleRevisionIsRefused(): void
    {
        $this->publishSecondVersion();
        $this->saveDraftPrice(4321.0, self::EDITOR);

        $answer = $this->adminPost(Routes::RESTORE, ['version' => '1', 'revision' => 'drawn-before-the-colleague-saved']);

        $this->assertStringContains('err=draftmoved', $this->location($answer), 'the stale restore was not refused');
        $this->assertSame(4321.0, $this->draftPrice(), 'the colleague\'s draft was overwritten');
    }

    /** One press over unpublished edits asks first, and the toast says what went. */
    public function testRestoreOverEditsAsksFirstAndSaysItReplacedThem(): void
    {
        $this->publishSecondVersion();
        $this->saveDraftPrice(4321.0, self::OWNER);

        $editor = $this->adminGet(Routes::RATES)->body();
        $this->assertStringContains('unpublished change', $editor);
        $this->assertTrue(
            preg_match('/data-confirm="Replace your \d+ unpublished changes? with version 1\?"/', $editor) === 1,
            'Restore does not ask before replacing unpublished edits',
        );

        $answer = $this->adminPost(Routes::RESTORE, ['version' => '1']);

        $this->assertStringContains('replaced=1', $this->location($answer), 'the redirect does not say edits were replaced');
        $this->assertStringContains(
            'it replaced your earlier unpublished edits',
            $this->adminGet(Routes::RATES, ['restored' => '1', 'replaced' => '1'])->body(),
        );
    }

    /* -------------------------------------------------------------------- CSV */

    /** Windows Excel reads the file as UTF-8 only with the BOM. */
    public function testTheDownloadStartsWithAUtf8Bom(): void
    {
        $body = $this->adminGet(Routes::EXPORT_CSV)->body();

        $this->assertSame(Csv::BOM, substr($body, 0, 3), 'the download has no BOM');
        $this->assertStringContains('# Speedat published rates - version 1', $body);
    }

    /** Copy gives what Download gives - the live prices, introduced - and no BOM. */
    public function testCopyAsCsvIsTheLivePricesWithTheirVersionLine(): void
    {
        $this->saveDraftPrice(4545.0, self::OWNER);

        $body = $this->adminGet(Routes::RATES)->body();
        $start = strpos($body, '<pre id="rates-csv" hidden>');
        $this->assertTrue($start !== false, 'no copy buffer');

        $buffer = substr($body, (int) $start, (int) strpos($body, '</pre>', (int) $start) - (int) $start);

        $this->assertStringContains('# Speedat published rates - version 1', $buffer, 'the copied text does not say what it is');
        $this->assertStringNotContains('4545', $buffer, 'the unpublished draft price was copied as the rates');
        $this->assertStringNotContains(Csv::BOM, $buffer, 'a BOM was put on the clipboard');
    }

    /** "7-10" would open in Excel as 10-Jul. */
    public function testDaysAreNeverAHyphenExcelReadsAsADate(): void
    {
        $this->assertSame("7\u{2013}10", Csv::days('7-10'));
        $this->assertSame("3\u{2013}5", Csv::days(' 3 - 5 '));
        $this->assertSame('on request', Csv::days('on request'));

        $name = $this->draftName();
        $this->adminPost(Routes::SAVE, ['dest' => [self::DEST => ['name' => $name, 'rates' => [self::SERVICE => ['days' => '7-10']]]]]);

        $rate = $this->draftRow()['rates'][self::SERVICE] ?? [];
        $this->assertSame("7\u{2013}10", (string) ($rate['days'] ?? ''), 'the hyphen was stored');
    }

    /* ---------------------------------------------------------------- notices */

    /** A blank name is a sentence about the name, not "a box that did not hold a number". */
    public function testABlankNameIsNotReportedAsAPriceBox(): void
    {
        $answer = $this->adminPost(Routes::SAVE, ['dest' => [self::DEST => ['name' => '']]]);

        $this->assertStringContains('noname=1', $this->location($answer), 'the blank name is not reported');
        $this->assertStringNotContains('kept=', $this->location($answer), 'the blank name was counted as a price box');
        $this->assertSame('', Notice::keptText(0));

        $this->assertStringContains(
            'has no name',
            $this->adminGet(Routes::RATES, ['noname' => '1'])->body(),
            'the editor does not say what is wrong',
        );
    }

    /** Bulk adjust reports in its own section, so the page has to land there. */
    public function testBulkAdjustLandsOnItsOwnSection(): void
    {
        $refused = $this->adminPost(Routes::BULK, ['fields' => ['first'], 'mode' => 'pct', 'value' => '5']);
        $this->assertStringContains('bulkerr=services#sec-bulk', $this->location($refused), 'the refusal opens at the top');

        $done = $this->adminPost(Routes::BULK, ['services' => [self::SERVICE], 'fields' => ['first'], 'mode' => 'pct', 'value' => '5']);
        $this->assertStringContains('#sec-bulk', $this->location($done), 'the result opens at the top');
    }

    /* -------------------------------------------------------------- internals */

    /** Store one price in the draft as somebody, and hand back the new fingerprint. */
    private function saveDraftPrice(float $price, string $by): string
    {
        $document = Repo::getDraft()->data->toArray();

        foreach ($document['destinations'] as $index => $row) {
            if ((string) ($row['id'] ?? '') === self::DEST) {
                $document['destinations'][$index]['rates'][self::SERVICE]['grid']['1'] = $price;
            }
        }

        return Repo::saveDraft(SiteData::fromArray($document), $by)->revision;
    }

    /** A new published version with one price only it has. */
    private function publishSecondVersion(float $price = 6789.0): void
    {
        $live = Repo::getLatestVersion();
        $this->assertNotNull($live, 'the seed did not publish');

        $document = $live->data->toArray();

        foreach ($document['destinations'] as $index => $row) {
            if ((string) ($row['id'] ?? '') === self::DEST) {
                $document['destinations'][$index]['rates'][self::SERVICE]['grid']['1'] = $price;
            }
        }

        Repo::publishVersion(new PublishArgs(
            data: SiteData::fromArray($document),
            by: self::OWNER,
            source: 'admin',
            summary: 'A publish',
            changeCount: 1,
        ));

        Live::forget();
    }

    /** @return array<string,mixed> */
    private function draftRow(): array
    {
        foreach (Repo::getDraft()->data->toArray()['destinations'] as $row) {
            if ((string) ($row['id'] ?? '') === self::DEST) {
                return $row;
            }
        }

        return [];
    }

    private function draftName(): string
    {
        return (string) ($this->draftRow()['name'] ?? '');
    }

    private function draftPrice(): ?float
    {
        $value = $this->draftRow()['rates'][self::SERVICE]['grid']['1'] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }
}
