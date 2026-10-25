<?php

declare(strict_types=1);

namespace Speedat\Tests\Http;

use Speedat\Core\Db;
use Speedat\Core\Money;
use Speedat\Domain\SiteDoc\Repo;
use Speedat\View\Admin\Routes;

/**
 * The rates editor: what each control writes, and what it refuses to write.
 *
 * Every test here posts THE WHOLE EDITOR FORM, because that is what the page
 * does - each button in the table is a submit of the same form to its own action
 * - and it is the only way to catch the bug that matters on this screen: an
 * action that saves the button's own change and loses everything the owner had
 * typed into the other forty rows.
 *
 * THE PRICES ARE READ BACK OUT OF THE DATABASE, never off the page. A test that
 * checks the rendered HTML is checking the templates (which `tests/ViewAdmin`
 * already does); these check that the number the owner typed is the number that
 * got stored, in the document shape the pricing engine reads.
 */
final class AdminRatesTest extends AdminTestCase
{
    /** The first destination in the seed, and a service it prices. */
    private const DEST    = 'gb';
    private const SERVICE = 'express';

    public function setUp(): void
    {
        parent::setUp();

        $this->admin();
        $this->signIn();
    }

    /* --------------------------------------------------------------- reading */

    public function testTheEditorRendersTheTableAndTheOwnersOwnControls(): void
    {
        $answer = $this->adminGet(Routes::RATES);

        $this->assertSame(200, $answer->response->status, 'the editor');
        $this->assertContentType('text/html; charset=utf-8', $answer, 'the editor');
        $this->assertOneH1($answer, 'the editor');

        $body = $answer->body();

        $this->assertStringContains('id="rates-table"', $body, 'no rates table');
        $this->assertStringContains('name="dest[' . self::DEST . '][name]"', $body, 'no destination name box');
        $this->assertStringContains('formaction="' . Routes::ADD_DESTINATION . '"', $body, 'no Add country');
        $this->assertStringContains('formaction="' . Routes::REVIEW . '"', $body, 'no Review and publish');
        $this->assertStringContains(self::OWNER, $body, 'the page does not say who is signed in');
        // The owner may publish, so the bar offers it rather than explaining.
        $this->assertStringNotContains('An owner publishes this.', $body, 'the owner was told an owner publishes');
    }

    public function testTheReviewScreenListsTheChangeAndOffersTheTwoTicks(): void
    {
        $this->postForm(Routes::SAVE, $this->form([self::DEST => ['grid' => ['1' => '9999']]]));

        $body = $this->adminGet(Routes::REVIEW)->body();

        $this->assertStringContains('Publishing version 2', $body, 'the review card');
        $this->assertStringContains('9,999', $body, 'the pending change is not in the diff');
        $this->assertStringContains('name="expectedBase" value="1"', $body, 'the publish form does not carry the base version');
        $this->assertStringContains('Rates are live', $body, 'the go-live tick is not offered on a sample document');
        $this->assertStringContains('name="' . \Speedat\Core\Csrf::FIELD . '"', $body, 'the publish form has no token');
    }

    public function testTheCsvExportIsAnAttachmentWithNoFormulaInIt(): void
    {
        // A country whose name Excel would execute if it were pasted in raw. It
        // is typed into the draft and then PUBLISHED, because the plain export
        // is the published rates - see the two tests below.
        $this->postForm(Routes::SAVE, $this->form([self::DEST => ['name' => '=cmd|calc']]));
        $this->adminPost(Routes::PUBLISH, ['expectedBase' => '1', 'goLive' => '1']);

        $answer = $this->adminGet(Routes::EXPORT_CSV);

        $this->assertSame(200, $answer->response->status, 'the export');
        $this->assertStringContains('text/csv', $answer->header('Content-Type'), 'the content type');
        $this->assertStringContains('attachment;', $answer->header('Content-Disposition'), 'not sent as an attachment');
        $this->assertStringContains("'=cmd|calc", $answer->body(), 'the formula was not neutralised');
        $this->assertSame(1, $this->audited('rates_exported'), 'the export was not audited');

        // The alias the runbook and the harness use answers the same bytes.
        $this->assertSame($answer->body(), $this->adminGet('/admin/rates/export')->body(), 'the two export URLs disagree');
    }

    /**
     * THE FILE CALLED "RATES" IS THE RATES THE WEBSITE IS QUOTING.
     *
     * It used to be the DRAFT: every half-typed price in the editor, under a
     * filename and a download button that said nothing about it. Once such a
     * file has left the building there is no way to tell it from the published
     * sheet, and the office is asked to honour whichever one the carrier is
     * holding.
     */
    public function testThePlainExportIsThePublishedRatesAndNotTheDraft(): void
    {
        $this->postForm(Routes::SAVE, $this->form([self::DEST => ['grid' => ['1' => '4242']]]));

        $answer = $this->adminGet(Routes::EXPORT_CSV);

        $this->assertStringNotContains('4242', $answer->body(), 'the unpublished draft price was exported as "rates"');
        $this->assertStringContains('# Speedat published rates - version 1', $answer->body(), 'the file does not say which version it is');
        $this->assertStringContains('-v1.csv', $answer->header('Content-Disposition'), 'the filename does not name the version');
    }

    /** The draft is still exportable - and it introduces itself twice when it is. */
    public function testTheDraftExportSaysItIsADraftInTheFilenameAndInTheFile(): void
    {
        $this->postForm(Routes::SAVE, $this->form([self::DEST => ['grid' => ['1' => '4343']]]));

        $answer = $this->adminGet(Routes::EXPORT_CSV, ['draft' => '1']);

        $this->assertSame(200, $answer->response->status, 'the draft export');
        $this->assertStringContains('4343', $answer->body(), 'the draft export does not carry the draft price');
        $this->assertStringContains('# UNPUBLISHED DRAFT', $answer->body(), 'the draft file does not say it is a draft');
        $this->assertStringContains('branched from published version 1', $answer->body(), 'the draft file does not say what it is based on');
        $this->assertStringContains('-draft-from-v1.csv', $answer->header('Content-Disposition'), 'the filename does not say draft');

        // The comment row is ONE cell: a comma in it would shift every column of
        // the header row that follows when the file is opened in Excel.
        $first = strtok($answer->body(), "\r\n");
        $this->assertStringNotContains(',', (string) $first, 'the comment row would break the columns');
    }

    /* --------------------------------------------------------------- saving */

    public function testTheAutosaveAnswersJsonAndStoresTheDraft(): void
    {
        $answer = $this->postForm(
            Routes::SAVE,
            $this->form([self::DEST => ['grid' => ['1' => '6100']]]),
            ['x-requested-with' => 'fetch'],
        );

        $this->assertSame(200, $answer->response->status, 'the autosave');
        $this->assertContentType('application/json; charset=utf-8', $answer, 'the autosave');

        $json = $answer->json();

        $this->assertSame(true, $json['ok'] ?? null, 'the autosave did not say ok');
        $this->assertTrue(is_string($json['savedAt'] ?? null) && $json['savedAt'] !== '', 'no savedAt for admin.js to print');
        $this->assertSame(1, $json['changes'] ?? null, 'the change count admin.js prints');

        $this->assertSame(6100.0, $this->price(self::DEST, self::SERVICE, 1), 'the price was not stored');
        $this->assertSame(self::OWNER, Repo::getDraft()->updatedBy, 'the draft does not record who saved it');
        $this->assertSame(1, $this->audited('draft_saved'), 'the save was not audited');
    }

    public function testAPlainSubmitSavesTheSameWayAndRedirects(): void
    {
        $answer = $this->postForm(Routes::SAVE, $this->form([self::DEST => ['grid' => ['2' => '8000']]]));

        $this->assertRedirects(Routes::RATES, $answer, 'the no-JavaScript save');
        $this->assertStringContains('saved=1', $this->location($answer), 'the redirect says nothing about the save');
        $this->assertSame(8000.0, $this->price(self::DEST, self::SERVICE, 2), 'the price was not stored');
    }

    public function testASaveThatChangesNothingIsNotAuditedTwice(): void
    {
        $this->postForm(Routes::SAVE, $this->form());
        $this->postForm(Routes::SAVE, $this->form());

        $this->assertSame(0, $this->audited('draft_saved'), 'two identical autosaves wrote audit rows');
    }

    public function testABlankPriceMeansNotOfferedAndIsStoredAsNothing(): void
    {
        $this->postForm(Routes::SAVE, $this->form([self::DEST => ['grid' => ['3' => '']]]));

        $this->assertNull($this->price(self::DEST, self::SERVICE, 3), 'a cleared box became a price');
    }

    public function testAPriceThatIsNotANumberKeepsTheStoredOneAndSaysSo(): void
    {
        $before = $this->price(self::DEST, self::SERVICE, 1);

        $answer = $this->postForm(Routes::SAVE, $this->form([self::DEST => ['grid' => ['1' => 'abc']]]));

        $this->assertSame($before, $this->price(self::DEST, self::SERVICE, 1), 'a typo overwrote a real price');
        $this->assertStringContains('kept=1', $this->location($answer), 'the owner was not told a box did not save');

        // And the page says which way round it happened, in words.
        $this->assertStringContains(
            'did not hold a number',
            $this->adminGet(Routes::RATES, ['kept' => '1'])->body(),
            'the editor does not explain the refused box',
        );
    }

    public function testASearchWordHidesRowsWithoutLosingThem(): void
    {
        $body = $this->adminGet(Routes::RATES, ['q' => 'canada'])->body();

        $this->assertStringContains('name="dest[ca][name]"', $body, 'the matching row is not in the form');
        $this->assertStringContains('name="dest[' . self::DEST . '][name]"', $body, 'a filtered row was dropped from the form');
        $this->assertSame(1, preg_match('/<tr class="[^"]*" data-name="united kingdom"[^>]* hidden>/', $body), 'the filtered row is not hidden');
    }

    /* ------------------------------------------------------- the table actions */

    public function testAddingACountryKeepsWhatWasAlreadyTyped(): void
    {
        $before = count($this->destinations());

        $answer = $this->postForm(
            Routes::ADD_DESTINATION,
            $this->form([self::DEST => ['grid' => ['1' => '7777']]]) + ['add' => '1'],
        );

        $this->assertStringContains('added=1', $this->location($answer), 'the redirect');
        $this->assertCount($before + 1, $this->destinations(), 'no country was added');
        $this->assertSame(7777.0, $this->price(self::DEST, self::SERVICE, 1), 'the half-typed price was lost');
        $this->assertSame(1, $this->audited('destination_added'), 'the addition was not audited');

        // The new row is off the website until somebody names it and ticks it on.
        $added = $this->destinations()[$before] ?? [];
        $this->assertSame('', (string) ($added['name'] ?? 'x'), 'the new row arrived named');
        $this->assertSame(false, $added['active'] ?? null, 'the new row is on the website already');
    }

    public function testRemovingACountryRemovesExactlyOne(): void
    {
        $before = count($this->destinations());

        $answer = $this->postForm(Routes::REMOVE_DESTINATION, $this->form() + ['remove' => 'my']);

        $this->assertStringContains('removed=1', $this->location($answer), 'the redirect');
        $this->assertCount($before - 1, $this->destinations(), 'the count after removing one');
        $this->assertNull($this->destination('my'), 'Malaysia is still there');
        $this->assertSame('my', $this->auditDetail('destination_removed')['id'] ?? '', 'the audit line does not name the row');
    }

    public function testRemovingACountryThatIsNotThereChangesNothing(): void
    {
        $before = $this->destinations();

        $answer = $this->postForm(Routes::REMOVE_DESTINATION, $this->form() + ['remove' => 'nowhere']);

        $this->assertStringContains('err=nodest', $this->location($answer), 'the refusal');
        $this->assertCount(count($before), $this->destinations(), 'a stale remove changed the table');
        $this->assertSame(0, $this->audited('destination_removed'), 'a refused remove was audited');
    }

    public function testSortingPutsTheCountriesInAlphabeticalOrder(): void
    {
        $answer = $this->postForm(Routes::SORT_DESTINATIONS, $this->form() + ['sort' => 'az']);

        $this->assertStringContains('sorted=1', $this->location($answer), 'the redirect');

        $names = array_map(static fn (array $d): string => (string) $d['name'], $this->destinations());
        $sorted = $names;
        usort($sorted, static fn (string $a, string $b): int => strcmp(\Speedat\Core\Str::nameKey($a), \Speedat\Core\Str::nameKey($b)));

        $this->assertSame($sorted, $names, 'the table is not in alphabetical order');
        $this->assertSame('Australia', $names[0] ?? '', 'the first country after sorting');
    }

    public function testUndoPutsOneRowBackToTheLiveVersionAndLeavesTheRestAlone(): void
    {
        $live = $this->price(self::DEST, self::SERVICE, 1);

        $this->postForm(Routes::SAVE, $this->form([
            self::DEST => ['grid' => ['1' => '9100']],
            'ca'       => ['grid' => ['1' => '9200']],
        ]));

        $answer = $this->postForm(Routes::UNDO_ROW, $this->form() + ['undo' => self::DEST]);

        $this->assertStringContains('undone=1', $this->location($answer), 'the redirect');
        $this->assertSame($live, $this->price(self::DEST, self::SERVICE, 1), 'the row was not put back');
        $this->assertSame(9200.0, $this->price('ca', self::SERVICE, 1), 'undoing one row changed another');
        $this->assertSame(1, $this->audited('row_undone'), 'the undo was not audited');
    }

    public function testUndoRemovesARowTheLiveVersionNeverHad(): void
    {
        $this->postForm(Routes::ADD_DESTINATION, $this->form() + ['add' => '1']);

        $added = $this->destinations();
        $newId = (string) ($added[count($added) - 1]['id'] ?? '');

        $this->assertTrue($newId !== '', 'no row was added');

        $this->postForm(Routes::UNDO_ROW, $this->form() + ['undo' => $newId]);

        $this->assertNull($this->destination($newId), 'undo left a row the live version never had');
    }

    public function testQuickFillWritesTheBlankBoxesOnly(): void
    {
        // Clear two boxes, then fill the blanks from 1000 in steps of 100.
        $this->postForm(Routes::SAVE, $this->form([self::DEST => ['grid' => ['2' => '', '3' => '']]]));

        $one = $this->price(self::DEST, self::SERVICE, 1);

        $post = $this->form([self::DEST => ['fill' => ['start' => '1000', 'step' => '100']]]);

        $answer = $this->postForm(Routes::FILL, $post + ['fill' => 'blanks:' . self::DEST . ':' . self::SERVICE]);

        $this->assertStringContains('filled=1', $this->location($answer), 'the redirect');
        $this->assertStringContains('open=' . self::DEST, $this->location($answer), 'the fold did not stay open');
        $this->assertSame($one, $this->price(self::DEST, self::SERVICE, 1), 'fill blanks overwrote a price that was there');
        $this->assertSame(1100.0, $this->price(self::DEST, self::SERVICE, 2), 'the 2 kg box');
        $this->assertSame(1200.0, $this->price(self::DEST, self::SERVICE, 3), 'the 3 kg box');
        $this->assertSame(1, $this->audited('row_filled'), 'the fill was not audited');
    }

    public function testQuickFillAllOverwritesEveryBox(): void
    {
        $post = $this->form([self::DEST => ['fill' => ['start' => '2000', 'step' => '10']]]);

        $this->postForm(Routes::FILL, $post + ['fill' => 'all:' . self::DEST . ':' . self::SERVICE]);

        $this->assertSame(2000.0, $this->price(self::DEST, self::SERVICE, 1), 'the 1 kg box');
        $this->assertSame(2010.0, $this->price(self::DEST, self::SERVICE, 2), 'the 2 kg box');
    }

    public function testQuickFillWithNoStartPriceIsRefused(): void
    {
        $before = $this->price(self::DEST, self::SERVICE, 1);

        $answer = $this->postForm(Routes::FILL, $this->form() + ['fill' => 'all:' . self::DEST . ':' . self::SERVICE]);

        $this->assertStringContains('err=novalue', $this->location($answer), 'the refusal');
        $this->assertSame($before, $this->price(self::DEST, self::SERVICE, 1), 'a fill with no start price changed the prices');
    }

    /* ------------------------------------------------------------ bulk adjust */

    public function testBulkAdjustRaisesEveryTickedPrice(): void
    {
        $before = $this->price(self::DEST, self::SERVICE, 1);

        $answer = $this->adminPost(Routes::BULK, [
            'services' => [self::SERVICE],
            // A 'doc' field is posted too, as a form cached before 2026-09-27
            // would: it is filtered out rather than refused, and the two real
            // fields still move.
            'fields'   => ['first', 'addl', 'doc'],
            'mode'     => 'pct',
            'value'    => '10',
            'roundTo'  => '10',
        ]);

        $this->assertStringContains('bulk=', $this->location($answer), 'the redirect');
        $this->assertSame(round($before * 1.1 / 10) * 10, $this->price(self::DEST, self::SERVICE, 1), 'the grid box was not raised');
        $this->assertSame(1, $this->audited('rates_bulk_adjusted'), 'the bulk change was not audited');

        // The other service was not ticked and must not have moved.
        $this->assertSame(
            $this->livePrice(self::DEST, 'normal', 1),
            $this->price(self::DEST, 'normal', 1),
            'a service nobody ticked was changed',
        );
    }

    public function testBulkAdjustRefusesAnEmptyTickListAndAnEmptyValue(): void
    {
        $before = $this->price(self::DEST, self::SERVICE, 1);

        $noService = $this->adminPost(Routes::BULK, ['fields' => ['first'], 'mode' => 'pct', 'value' => '5']);
        $this->assertStringContains('bulkerr=services', $this->location($noService), 'no service ticked');

        $noValue = $this->adminPost(Routes::BULK, ['services' => [self::SERVICE], 'fields' => ['first'], 'mode' => 'pct', 'value' => '']);
        $this->assertStringContains('bulkerr=value', $this->location($noValue), 'no value given');

        $this->assertSame($before, $this->price(self::DEST, self::SERVICE, 1), 'a refused bulk change moved a price');
        $this->assertSame(0, $this->audited('rates_bulk_adjusted'), 'a refused bulk change was audited');
    }

    /* ---------------------------------------------------------- test a price */

    public function testTestAPricePricesAgainstTheDraftWithTheServersOwnEngine(): void
    {
        $this->postForm(Routes::SAVE, $this->form([self::DEST => ['grid' => ['1' => '12345']]]));

        $answer = $this->adminPost(Routes::TEST, [
            'destId' => self::DEST,
            'type'   => 'pkg',
            'kg'     => '1',
            'pieces' => '1',
        ]);

        $this->assertSame(200, $answer->response->status, 'the test panel');
        $this->assertStringContains('12,345', $answer->body(), 'the unpublished price was not used');
    }

    /* ------------------------------------------------------- draft lifecycle */

    public function testDiscardingTheDraftPutsTheLiveVersionBack(): void
    {
        $live = $this->price(self::DEST, self::SERVICE, 1);

        $this->postForm(Routes::SAVE, $this->form([self::DEST => ['grid' => ['1' => '4242']]]));
        $this->assertSame(4242.0, $this->price(self::DEST, self::SERVICE, 1), 'the edit did not save');

        $answer = $this->adminPost(Routes::DISCARD);

        $this->assertStringContains('discarded=1', $this->location($answer), 'the redirect');
        $this->assertSame($live, $this->price(self::DEST, self::SERVICE, 1), 'the draft was not thrown away');
        $this->assertSame(1, $this->audited('draft_discarded'), 'the discard was not audited');
    }

    public function testRestoringAnOlderVersionCopiesItIntoTheDraftOnly(): void
    {
        $original = $this->price(self::DEST, self::SERVICE, 1);

        // Publish a second version with a different price.
        $this->postForm(Routes::SAVE, $this->form([self::DEST => ['grid' => ['1' => '5555']]]));
        $this->adminPost(Routes::PUBLISH, ['expectedBase' => '1', 'goLive' => '1']);
        $this->assertSame(2, $this->liveVersion(), 'the second version was not published');

        $answer = $this->adminPost(Routes::RESTORE, ['version' => '1']);

        $this->assertStringContains('restored=1', $this->location($answer), 'the redirect');
        $this->assertSame($original, $this->price(self::DEST, self::SERVICE, 1), 'the draft does not hold version 1');
        $this->assertSame(2, $this->liveVersion(), 'a restore published something');
        $this->assertSame(1, $this->auditDetail('draft_restored')['fromVersion'] ?? null, 'the audit line does not name the version');
    }

    public function testRestoringAVersionThatDoesNotExistIsRefused(): void
    {
        $answer = $this->adminPost(Routes::RESTORE, ['version' => '99']);

        $this->assertStringContains('err=', $this->location($answer), 'the refusal');
        $this->assertSame(0, $this->audited('draft_restored'), 'a refused restore was audited');
    }

    /* ------------------------------------------------------------ the fixture */

    /**
     * The editor form, as the page would post it, with overrides by destination.
     *
     * An override is `['<destId>' => ['name' => …, 'grid' => ['<kg>' => …],
     * 'fill' => ['start' => …, 'step' => …]]]`, applied to the first service, so
     * a test says what it is changing and nothing else.
     *
     * @param  array<string,array<string,mixed>> $changes
     * @return array<string,mixed>
     */
    private function form(array $changes = []): array
    {
        $document = Repo::getDraft()->data->toArray();

        $post = ['dest' => [], 'company' => [], 'settings' => [], 'content' => []];

        foreach ($document['destinations'] as $index => $row) {
            $id    = (string) $row['id'];
            $entry = [
                'name'     => (string) $row['name'],
                'position' => (string) $index,
                'active'   => ($row['active'] ?? false) === true ? '1' : '0',
            ];

            foreach ($row['rates'] as $serviceId => $rate) {
                // No 'doc' box: the editor stopped rendering one when the document
                // rate left the product on 2026-09-27 (owner), so a post that
                // carried it would not be a post the form can make.
                $cell = [
                    'first' => self::box($rate['first'] ?? null),
                    'addl'  => self::box($rate['addl'] ?? null),
                    'days'  => (string) ($rate['days'] ?? ''),
                ];

                if (is_array($rate['grid'] ?? null)) {
                    foreach ($rate['grid'] as $kg => $price) {
                        $cell['grid'][(string) $kg] = self::box($price);
                    }
                }

                $entry['rates'][(string) $serviceId] = $cell;
            }

            $override = $changes[$id] ?? [];

            if (isset($override['name'])) {
                $entry['name'] = (string) $override['name'];
            }
            if (isset($override['active'])) {
                $entry['active'] = (string) $override['active'];
            }
            if (is_array($override['grid'] ?? null)) {
                foreach ($override['grid'] as $kg => $price) {
                    $entry['rates'][self::SERVICE]['grid'][(string) $kg] = (string) $price;
                }
            }
            if (is_array($override['fill'] ?? null)) {
                $entry['rates'][self::SERVICE]['fill'] = [
                    'start' => (string) ($override['fill']['start'] ?? ''),
                    'step'  => (string) ($override['fill']['step'] ?? ''),
                ];
            }

            $post['dest'][$id] = $entry;
        }

        foreach ($document['company'] as $key => $value) {
            $post['company'][(string) $key] = is_scalar($value) ? (string) $value : '';
        }

        foreach ($document['settings'] as $key => $value) {
            $post['settings'][(string) $key] = match (true) {
                is_bool($value)  => $value ? '1' : '0',
                $value === null  => '',
                is_float($value), is_int($value) => Money::numberToString((float) $value),
                is_scalar($value) => (string) $value,
                default           => '',
            };
        }

        foreach ($document['content'] as $key => $value) {
            $post['content'][(string) $key] = is_scalar($value) ? (string) $value : '';
        }

        return $post;
    }

    /** A price as the browser posts it: JavaScript's String(n), or "" for nothing. */
    private static function box(mixed $value): string
    {
        return is_numeric($value) ? Money::numberToString((float) $value) : '';
    }

    /**
     * Post the editor form.
     *
     * @param array<string,mixed>  $post
     * @param array<string,string> $headers
     */
    private function postForm(string $path, array $post, array $headers = []): Answer
    {
        return $this->adminPost($path, $post, $headers);
    }

    /** One stored draft price, or null when the box is empty. */
    private function price(string $destId, string $serviceId, int $kg): ?float
    {
        $rate = $this->destination($destId)['rates'][$serviceId]['grid'][(string) $kg] ?? null;

        return is_numeric($rate) ? (float) $rate : null;
    }

    /** The same price in the published version. */
    private function livePrice(string $destId, string $serviceId, int $kg): ?float
    {
        $version = Repo::getLatestVersion();

        if ($version === null) {
            return null;
        }

        foreach ($version->data->toArray()['destinations'] as $row) {
            if ((string) ($row['id'] ?? '') === $destId) {
                $price = $row['rates'][$serviceId]['grid'][(string) $kg] ?? null;

                return is_numeric($price) ? (float) $price : null;
            }
        }

        return null;
    }

    /** @return list<array<string,mixed>> */
    private function destinations(): array
    {
        $rows = Repo::getDraft()->data->toArray()['destinations'];

        /** @var list<array<string,mixed>> $rows */
        return $rows;
    }

    /** @return array<string,mixed>|null */
    private function destination(string $id): ?array
    {
        foreach ($this->destinations() as $row) {
            if ((string) ($row['id'] ?? '') === $id) {
                return $row;
            }
        }

        return null;
    }
}
