<?php

declare(strict_types=1);

namespace Speedat\Tests\Http;

use Speedat\Domain\SiteDoc\Repo;
use Speedat\Domain\SiteDoc\Schema;
use Speedat\View\Admin\Routes;

/**
 * THE SHAPE CHECK RUNS ON THE DOCUMENT THE FORM SENT, AT THE MOMENT IT IS SENT.
 *
 * `Repo::publishVersion()` takes a RAW document and checks that before the
 * normalised one, for a good reason written out there: `SiteData::fromArray()`
 * coerces, so checking the value object is checking the answer rather than the
 * question. The admin never had a raw document to hand it - it publishes what
 * is already stored in the draft row - so that branch was dead for every
 * publish the owner has ever made.
 *
 * What survived normalisation still bit. A price past the money cap saved into
 * the draft quite happily and then made the whole document unpublishable: the
 * owner met it three steps later as "That could not be published... Try again
 * in a moment", about a box he had stopped looking at, with no way to find out
 * which one.
 *
 * So the check moved to where the raw document exists - the save - and this
 * file drives it from the HTTP side: the refusal happens before the write,
 * nothing is stored, the editor says what to do, and the draft the owner had is
 * exactly as he left it.
 */
final class AdminDocumentShapeTest extends AdminTestCase
{
    private const DEST = 'gb';

    /** Ten times the largest money value the schema will take. */
    private const ABSURD = '1000000000';

    public function setUp(): void
    {
        parent::setUp();

        $this->admin();
        $this->signIn();
    }

    /** The cap this test leans on is the schema's, not a number invented here. */
    public function testTheMoneyCapIsTheSchemasOwn(): void
    {
        $this->assertTrue(
            (float) self::ABSURD > Schema::MONEY_MAX,
            'the fixture price is inside the money cap, so it proves nothing',
        );
    }

    public function testAPriceTheSiteCannotStoreIsRefusedBeforeItIsWritten(): void
    {
        $before = $this->price(1);

        $answer = $this->adminPost(Routes::SAVE, $this->editorPost(['1' => self::ABSURD]));

        $this->assertStringContains('err=badshape', $this->location($answer), 'an unstorable price was accepted');
        $this->assertSame($before, $this->price(1), 'it was written anyway');
        $this->assertSame(0, $this->audited('draft_saved'), 'a refused save was recorded as a save');
    }

    /** And the editor says so in words the owner can act on. */
    public function testTheOwnerIsToldWhatHappened(): void
    {
        $body = $this->adminGet(Routes::RATES, ['err' => 'badshape'])->body();

        $this->assertStringContains('nothing was saved', $body, 'the editor does not say the draft is untouched');
        $this->assertStringContains('Check what you just typed', $body, 'nor what to do about it');
        // The field path is for the log. A URL is not a place for one.
        $this->assertStringNotContains('destinations.', $body, 'the field path reached the screen');
    }

    /** The autosave is refused in the same words, on the same code path. */
    public function testTheAutosaveIsRefusedWithTheSameSentence(): void
    {
        $json = $this->adminPost(
            Routes::SAVE,
            $this->editorPost(['1' => self::ABSURD]),
            ['x-requested-with' => 'fetch'],
        )->json();

        $this->assertTrue(($json['ok'] ?? true) === false, 'the autosave stored an unstorable price');
        $this->assertStringContains('nothing was saved', (string) ($json['message'] ?? ''), 'the autosave was refused without a reason');
    }

    /**
     * THE DRAFT IS STILL PUBLISHABLE AFTERWARDS, which is the whole point:
     * before this check the bad price went in and the next publish - possibly
     * days later, by somebody else - was the thing that failed.
     */
    public function testTheDraftIsLeftInAStateThatCanStillBePublished(): void
    {
        $this->adminPost(Routes::SAVE, $this->editorPost(['1' => self::ABSURD]));

        $this->assertNull(
            Schema::firstIssue(Repo::getDraft()->data->toArray()),
            'the refused save left the draft in a shape that cannot be published',
        );
    }

    /** An ordinary price is not caught by any of this. */
    public function testAnOrdinaryPriceSavesExactlyAsBefore(): void
    {
        $answer = $this->adminPost(Routes::SAVE, $this->editorPost(['1' => '7300']));

        $this->assertStringNotContains('err=', $this->location($answer), 'an ordinary price was refused');
        $this->assertSame(7300.0, $this->price(1), 'it did not save');
    }

    /* ------------------------------------------------------------ internals */

    /**
     * One country's post, which is what the editor sends now.
     *
     * @param  array<string,string> $grid
     * @return array<string,mixed>
     */
    private function editorPost(array $grid): array
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
}
