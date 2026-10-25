<?php

declare(strict_types=1);

namespace Speedat\Tests\SiteDoc;

use Speedat\Core\Config;
use Speedat\Core\Db;
use Speedat\Core\Paths;
use Speedat\Domain\SiteDoc\DraftConflict;
use Speedat\Domain\SiteDoc\Hold;
use Speedat\Domain\SiteDoc\PublishArgs;
use Speedat\Domain\SiteDoc\PublishConflict;
use Speedat\Domain\SiteDoc\Repo;
use Speedat\Domain\SiteDoc\RollbackRefused;
use Speedat\Domain\SiteDoc\Schema;
use Speedat\Domain\SiteDoc\Seed;
use Speedat\Domain\SiteDoc\SiteData;

/**
 * The version store, against a real MariaDB. Port of the cases in
 * src/lib/site/repo.test.ts.
 *
 * Each test starts from an empty store rather than carrying state from the one
 * before it: a suite where test 7 fails because test 3 was filtered out is a
 * suite people learn to ignore.
 */
final class RepoTest extends SiteDocTestCase
{
    /**
     * The actor the roll-back audit test signs with, so its own rows can be
     * found and removed without touching anybody else's. `audit_log` is
     * append-only in the application; a test's own tear-down is not the
     * application (see tests/Core/AuditTest.php).
     */
    private const ROLLBACK_ACTOR = 'rollback-audit@test';

    public function setUp(): void
    {
        $this->useScratchCache();
        $this->needDatabase();
        $this->emptyStore();
    }

    public function tearDown(): void
    {
        if (Db::healthy()) {
            $this->emptyStore();
            Db::exec('DELETE FROM audit_log WHERE actor = ?', [self::ROLLBACK_ACTOR]);
        }

        $this->dropScratchCache();
    }

    public function testAnEmptyStoreHasNoLiveVersionAndSaysWhatToDo(): void
    {
        $this->assertNull(Repo::getLatestVersion());
        $this->assertNull(Repo::getVersion(1));
        $this->assertSame([], Repo::listVersions());

        $e = $this->assertThrows(\RuntimeException::class, static fn () => Repo::getDraft());
        $this->assertStringContains('run the seed first', $e->getMessage());
    }

    public function testPublishesVersionOneAndBranchesTheDraftFromIt(): void
    {
        $v1 = Repo::publishVersion($this->args(Seed::site(), 'seed', 'seed'));

        $this->assertSame(1, $v1->version);
        $this->assertSame(1, preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $v1->publishedAt));

        $draft = Repo::getDraft();
        $this->assertSame(1, $draft->baseVersion);
        $this->assertSame(10, count($draft->data->card->destinations));
        $this->assertSame('seed', $draft->updatedBy);

        $live = Repo::getLatestVersion();
        $this->assertSame(1, $live?->version);
        $this->assertSame(Seed::site()->toArray(), $live?->data->toArray(), 'the document survives the round trip byte for byte');
    }

    public function testSavesTheDraftAndPublishesItAsVersionTwoRebasingTheDraft(): void
    {
        Repo::publishVersion($this->args(Seed::site(), 'seed', 'seed'));

        $doc = Seed::data();
        $doc['destinations'][0]['rates']['express']['first'] = 9999;
        $edited = $this->site($doc);

        Repo::saveDraft($edited, 'editor@x');

        $again = Repo::getDraft();
        $this->assertSame(9999.0, $again->data->card->destination('gb')?->rate('express')?->first);
        $this->assertSame('editor@x', $again->updatedBy);
        $this->assertSame(1, $again->baseVersion, 'saving is not re-basing');

        $v2 = Repo::publishVersion($this->args($edited, 'owner@x', 'admin', 1, 1));
        $this->assertSame(2, $v2->version);
        $this->assertSame(2, Repo::getDraft()->baseVersion);
        $this->assertSame(2, Repo::getLatestVersion()?->version);

        // History is immutable: version 1 still holds the old price.
        $this->assertSame(4500.0, Repo::getVersion(1)?->data->card->destination('gb')?->rate('express')?->first);
    }

    /**
     * Every version published before 2026-09-27 holds `settings.docMaxKg` and a
     * `doc` price on most rates. The owner removed the document rate from the
     * product that day, and the version history is a promise: those rows must
     * still LOAD, still be restorable into the draft, and still be publishable
     * forward - dropping the two retired keys on the way, never choking on them.
     *
     * The row is written straight into `versions` rather than through
     * publishVersion(), because publishVersion() takes value objects that no
     * longer have anywhere to put a document price. The raw JSON is the only
     * honest way to reproduce what is really in the table.
     */
    public function testAVersionStoredBeforeTheDocumentRateWasRemovedStillLoadsAndRepublishes(): void
    {
        $legacy = Seed::data();

        $legacy['settings']['docMaxKg']                       = 0.5;
        $legacy['destinations'][0]['rates']['express']['doc']  = 3900;
        $legacy['destinations'][0]['rates']['normal']['doc']   = 2800;
        $legacy['destinations'][1]['rates']['express']['doc']  = 4500;

        Db::exec(
            'INSERT INTO versions (version, published_at, published_by, source, summary, change_count, data)'
            . ' VALUES (1, ?, ?, ?, ?, 0, ?)',
            ['2026-09-20 06:15:00', 'owner@x', 'admin', 'published before the change', Db::json($legacy)],
        );

        // It loads, and the two retired keys are gone from what loaded.
        $live = Repo::getLatestVersion();
        $this->assertSame(1, $live?->version);

        $loaded = $live?->data->toArray() ?? [];
        $this->assertFalse(array_key_exists('docMaxKg', $loaded['settings']), 'the retired weight limit survived the load');

        foreach ($loaded['destinations'] as $dest) {
            foreach ($dest['rates'] as $serviceId => $rate) {
                $this->assertFalse(array_key_exists('doc', $rate), $dest['id'] . ' / ' . $serviceId . ' kept a document price');
            }
        }

        // Not one weight price moved.
        $this->assertSame(4500.0, $live?->data->card->destination('gb')?->rate('express')?->first);
        $this->assertSame(1100.0, $live?->data->card->destination('gb')?->rate('express')?->addl);
        $this->assertSame(Seed::site()->toArray(), $loaded, 'the document is the seed again, minus the document rate');

        // It restores into the draft, and it publishes forward as version 2.
        $restored = Repo::getVersion(1);
        $this->assertNotNull($restored);

        $v2 = Repo::publishVersion($this->args($restored->data, 'owner@x', 'admin', 0, 1));
        $this->assertSame(2, $v2->version);
        $this->assertSame(2, Repo::getDraft()->baseVersion);
        $this->assertSame($loaded, Repo::getLatestVersion()?->data->toArray(), 'republishing changed the document');
    }

    public function testRefusesToPublishOnTopOfAVersionTheCallerHasNotSeen(): void
    {
        Repo::publishVersion($this->args(Seed::site(), 'seed', 'seed'));
        Repo::publishVersion($this->args(Seed::site(), 'owner@x', 'admin'));

        $e = $this->assertThrows(
            PublishConflict::class,
            fn () => Repo::publishVersion($this->args(Seed::site(), 'late@x', 'admin', 1, 1))
        );

        $this->assertSame(2, $e instanceof PublishConflict ? $e->live : 0);
        $this->assertSame(2, Repo::getLatestVersion()?->version, 'the refused publish left nothing behind');
    }

    public function testRefusesADocumentTheAdminsOwnSaveWouldReject(): void
    {
        Repo::publishVersion($this->args(Seed::site(), 'seed', 'seed'));

        $doc                      = Seed::data();
        $doc['content']['phone2'] = str_repeat('x', 81);
        $bad                      = $this->site($doc);

        $e = $this->assertThrows(
            \InvalidArgumentException::class,
            fn () => Repo::publishVersion($this->args($bad, 'script', 'convert'))
        );

        $this->assertStringContains('content.phone2', $e->getMessage());
        $this->assertStringContains('refusing to publish an invalid document', $e->getMessage());
        $this->assertSame(1, Repo::getLatestVersion()?->version);
    }

    public function testDiscardingTheDraftRebranchesFromTheLiveVersion(): void
    {
        Repo::publishVersion($this->args(Seed::site(), 'seed', 'seed'));
        Repo::publishVersion($this->args(Seed::site(), 'owner@x', 'admin'));

        $doc                    = Seed::data();
        $doc['company']['name'] = 'Changed';
        Repo::saveDraft($this->site($doc), 'e');

        $this->assertSame('Changed', Repo::getDraft()->data->company->name);

        Repo::discardDraft();

        $fresh = Repo::getDraft();
        $this->assertSame(Seed::company()['name'], $fresh->data->company->name);
        $this->assertSame(2, $fresh->baseVersion);
        $this->assertSame('system', $fresh->updatedBy);
    }

    public function testRestorePutsAnOldVersionsRatesAndTextBackIntoTheDraft(): void
    {
        Repo::publishVersion($this->args(Seed::site(), 'seed', 'seed'));

        $doc                                                = Seed::data();
        $doc['destinations'][0]['rates']['express']['first'] = 7777;
        $doc['content']['routesTitle']                       = 'Our prices';
        $doc['live']                                         = true;
        Repo::publishVersion($this->args($this->site($doc), 'owner@x', 'admin'));

        $restored = Repo::restore(1, 'owner@x');

        $this->assertNotNull($restored);
        $this->assertSame(4500.0, $restored?->data->card->destination('gb')?->rate('express')?->first);
        $this->assertSame('Rates', $restored?->data->content->routesTitle);
        $this->assertTrue((bool) $restored?->data->live, 'restoring old rates does not take the site back to sample mode');
        $this->assertSame(2, $restored?->baseVersion, 'a restore is a draft edit, not a publish');
        $this->assertSame(2, Repo::getLatestVersion()?->version);

        $this->assertNull(Repo::restore(99, 'owner@x'), 'a version that never existed is not an exception');
    }

    /* ------------------------------------------------------- rolling back */

    /**
     * THE UNDO BUTTON. "Restore into editor" copies an old version into the
     * draft and leaves the bad prices live until somebody reviews and
     * publishes; that is the wrong shape for "that publish was wrong, take it
     * off". `rollbackTo()` restores AND publishes, as one action.
     */
    public function testRollingBackRepublishesAnOldVersionAndRebasesTheDraft(): void
    {
        Repo::publishVersion($this->args(Seed::site(), 'seed', 'seed'));

        $bad                                                = Seed::data();
        $bad['destinations'][0]['rates']['express']['first'] = 7777;
        $bad['content']['routesTitle']                       = 'Our prices';
        Repo::publishVersion($this->args($this->site($bad), 'owner@x', 'admin', 1, 1));

        $this->assertSame(7777.0, Repo::getLatestVersion()?->data->card->destination('gb')?->rate('express')?->first);

        $rolled = Repo::rollbackTo(1, 'owner@x');

        // A NEW version, not a rewritten one: history is still append-only.
        $this->assertSame(3, $rolled->version);
        $this->assertSame(3, Repo::getLatestVersion()?->version);
        $this->assertSame(7777.0, Repo::getVersion(2)?->data->card->destination('gb')?->rate('express')?->first);

        // And what customers now see is version 1, to the byte.
        $this->assertSame(
            Repo::getVersion(1)?->data->toArray(),
            Repo::getLatestVersion()?->data->toArray(),
            'the live document is not the one that was rolled back to',
        );
        $this->assertSame(4500.0, Repo::getLatestVersion()?->data->card->destination('gb')?->rate('express')?->first);
        $this->assertSame('Rates', Repo::getLatestVersion()?->data->content->routesTitle);

        // The draft follows the site, so the next edit starts from what is
        // live rather than from the prices that were just taken off.
        $draft = Repo::getDraft();
        $this->assertSame(3, $draft->baseVersion, 'the draft was not re-based onto the roll-back');
        $this->assertSame(Repo::getVersion(1)?->data->toArray(), $draft->data->toArray());

        // The history says what happened in words, not just a number.
        $history = Repo::listVersions(5);
        $this->assertSame('Rolled back to version 1', $history[0]->summary);
        $this->assertSame('restore:1', $history[0]->source);
        $this->assertSame('owner@x', $history[0]->publishedBy);
        $this->assertTrue($history[0]->changeCount > 0, 'a roll-back that changed prices counted no changes');
    }

    /**
     * The hold is a separate decision from the prices.
     *
     * Somebody switched it on for a reason this document does not know.
     * Resuming prices as a side effect of an undo would be a second decision
     * taken on his behalf, without a word on any screen - and it would put the
     * rolled-back rates in front of customers at the same moment.
     */
    public function testARollbackWhilePricesAreHeldLeavesThemHeld(): void
    {
        Repo::publishVersion($this->args(Seed::site(), 'seed', 'seed'));

        $bad                                                = Seed::data();
        $bad['destinations'][0]['rates']['express']['first'] = 7777;
        Repo::publishVersion($this->args($this->site($bad), 'owner@x', 'admin', 1, 1));

        Hold::set(true, 'owner@x', 'Updating rates');
        $this->assertTrue(Hold::get()->on);

        Repo::rollbackTo(1, 'owner@x');

        $hold = Hold::get();
        $this->assertTrue($hold->on, 'a roll-back silently resumed the prices');
        $this->assertSame('Updating rates', $hold->message);
        $this->assertSame(3, Repo::getLatestVersion()?->version, 'the roll-back itself did not happen');
    }

    /** A year later, "who put the old prices back, and when" has an answer. */
    public function testARollbackWritesItsOwnAuditLine(): void
    {
        Repo::publishVersion($this->args(Seed::site(), 'seed', 'seed'));

        $bad                                                = Seed::data();
        $bad['destinations'][0]['rates']['express']['first'] = 7777;
        Repo::publishVersion($this->args($this->site($bad), 'owner@x', 'admin', 1, 1));

        Hold::set(true, 'owner@x', 'Updating rates');

        $rolled = Repo::rollbackTo(1, self::ROLLBACK_ACTOR);

        $row = Db::one(
            'SELECT action, detail FROM audit_log WHERE actor = ? ORDER BY id DESC LIMIT 1',
            [self::ROLLBACK_ACTOR]
        );

        $this->assertNotNull($row, 'the roll-back left no audit line');
        $this->assertSame('rates_rolled_back', $row['action'] ?? null);

        $detail = Db::unjson($row['detail'] ?? '');
        $this->assertSame(1, $detail['fromVersion'] ?? null);
        $this->assertSame($rolled->version, $detail['version'] ?? null);
        $this->assertSame('Rolled back to version 1', $detail['summary'] ?? null);

        // So the screen can say "the prices are still held" rather than the
        // owner discovering it from a customer.
        $this->assertTrue(($detail['pricesOnHold'] ?? null) === true);
    }

    /**
     * A version can stop being publishable after it was published: the
     * document rate left the product on 2026-09-27, and a version whose only
     * priced service was that one now prices nothing at all.
     *
     * Putting it back would take every price off the site silently - a worse
     * accident than the one being undone - so it is refused, in the owner's
     * own words, with nothing written.
     */
    public function testRefusesToRollBackToAVersionThatNoLongerValidates(): void
    {
        Repo::publishVersion($this->args(Seed::site(), 'seed', 'seed'));

        // A version from before the change: every rate priced ONLY as a
        // document. Written straight into the table, because publishVersion()
        // takes value objects that no longer have anywhere to put one.
        $legacy = Seed::data();
        foreach ($legacy['destinations'] as $i => $dest) {
            foreach (array_keys($dest['rates']) as $serviceId) {
                $legacy['destinations'][$i]['rates'][$serviceId] = ['doc' => 3900, 'days' => '3-5'];
            }
        }
        $legacy['settings']['docMaxKg'] = 0.5;

        Db::exec(
            'INSERT INTO versions (version, published_at, published_by, source, summary, change_count, data)'
            . ' VALUES (2, ?, ?, ?, ?, 0, ?)',
            ['2026-09-20 06:15:00', 'owner@x', 'admin', 'document rates only', Db::json($legacy)],
        );

        Repo::publishVersion($this->args(Seed::site(), 'owner@x', 'admin', 0, 2));
        $this->assertSame(3, Repo::getLatestVersion()?->version);

        $e = $this->assertThrows(
            RollbackRefused::class,
            static fn () => Repo::rollbackTo(2, 'owner@x')
        );

        $this->assertSame(RollbackRefused::INVALID, $e instanceof RollbackRefused ? $e->reason : '');
        $this->assertTrue($e instanceof RollbackRefused && $e->reasons !== [], 'no reason was given to print');
        $this->assertTrue(
            $e instanceof RollbackRefused
            && in_array('United Kingdom is shown on the site but has no prices.', $e->reasons, true),
            'the refusal did not carry the validator\'s own sentence',
        );

        $this->assertSame(3, Repo::getLatestVersion()?->version, 'the refused roll-back published anyway');
    }

    public function testRefusesARollbackThatHasNothingToRollBackTo(): void
    {
        Repo::publishVersion($this->args(Seed::site(), 'seed', 'seed'));

        $never = $this->assertThrows(RollbackRefused::class, static fn () => Repo::rollbackTo(99, 'owner@x'));
        $this->assertSame(
            RollbackRefused::NO_SUCH_VERSION,
            $never instanceof RollbackRefused ? $never->reason : '',
        );

        // Rolling back to what is already live would write a version row that
        // changes nothing, which is the noise the publish screen refuses too.
        $same = $this->assertThrows(RollbackRefused::class, static fn () => Repo::rollbackTo(1, 'owner@x'));
        $this->assertSame(RollbackRefused::NO_CHANGE, $same instanceof RollbackRefused ? $same->reason : '');

        $this->assertSame(1, Repo::getLatestVersion()?->version, 'a refused roll-back wrote a version');
    }

    /**
     * The "nothing to roll back" test that is easy to get wrong.
     *
     * A roll-back carries the "rates are live" tick forward from the SITE, not
     * from the version being put back - otherwise pressing it would drop the
     * site into sample mode. So when the only thing separating version 1 from
     * the live version 2 is that tick, the document that would be published is
     * byte-for-byte the live one and there is nothing to do.
     *
     * Comparing the old version against the live one instead of comparing the
     * document that would actually go live counts one change here, and writes
     * a version row that changes nothing.
     */
    public function testAVersionThatDiffersFromTheLiveOneOnlyByTheLiveTickIsNothingToRollBackTo(): void
    {
        Repo::publishVersion($this->args(Seed::site(), 'seed', 'seed'));

        $doc         = Seed::data();
        $doc['live'] = true;
        Repo::publishVersion($this->args($this->site($doc), 'owner@x', 'admin', 1, 1));

        $this->assertTrue(Repo::getLatestVersion()?->data->live === true);
        $this->assertFalse(Repo::getVersion(1)?->data->live === true);

        $e = $this->assertThrows(RollbackRefused::class, static fn () => Repo::rollbackTo(1, 'owner@x'));

        $this->assertSame(RollbackRefused::NO_CHANGE, $e instanceof RollbackRefused ? $e->reason : '');
        $this->assertSame(2, Repo::getLatestVersion()?->version, 'a roll-back that changes nothing wrote a version');
    }

    /* ---------------------------------------------- the shared draft row */

    /** The editor prints whose prices are on the screen, and from when. */
    public function testTheDraftSaysWhoWroteItAndWhen(): void
    {
        Repo::publishVersion($this->args(Seed::site(), 'seed', 'seed'));

        $doc                    = Seed::data();
        $doc['company']['name'] = 'Changed';
        Repo::saveDraft($this->site($doc), 'first@x');

        $draft = Repo::getDraft();

        $this->assertSame('first@x', $draft->updatedBy);
        $this->assertStringContains('edited by first@x at ', $draft->editedBy());
        $this->assertSame(1, preg_match('/ at \d{1,2} \w+ \d{4}, \d{2}:\d{2}$/', $draft->editedBy()), $draft->editedBy());
    }

    /**
     * ONE DRAFT ROW, SEVERAL ADMINS. Before this, the second save replaced the
     * first one's prices with no word on either screen - and the owner could
     * then publish an edit he had never made, because the review card renders
     * whatever the row now says.
     *
     * Both directions, because a guard that refuses everything is not a guard:
     * the editor that is up to date saves normally.
     */
    public function testASaveThatIsUpToDateGoesThroughAndAStaleOneIsRefused(): void
    {
        Repo::publishVersion($this->args(Seed::site(), 'seed', 'seed'));

        // The first admin opens the editor.
        $opened = Repo::getDraft();

        $mine                    = Seed::data();
        $mine['company']['name'] = 'Mine';

        // ... and saves what he was shown. Nothing moved, so it is written.
        $saved = Repo::saveDraft($this->site($mine), 'first@x', $opened->revision);
        $this->assertSame('Mine', Repo::getDraft()->data->company->name);
        $this->assertNotSame($opened->revision, $saved->revision, 'the fingerprint did not move with the write');
        $this->assertSame($saved->revision, Repo::getDraft()->revision, 'the returned fingerprint is not the stored one');

        // The second admin had the SAME screen open and saves on top.
        $theirs                    = Seed::data();
        $theirs['company']['name'] = 'Theirs';

        $e = $this->assertThrows(
            DraftConflict::class,
            fn () => Repo::saveDraft($this->site($theirs), 'second@x', $opened->revision)
        );

        // Refused, and with the sentence the screen needs to explain itself.
        $this->assertStringContains('edited by first@x', $e->getMessage());
        $this->assertSame(
            'first@x',
            $e instanceof DraftConflict ? $e->current->updatedBy : '',
            'the conflict does not say who is holding the draft',
        );

        // Above all: nothing was overwritten.
        $stored = Repo::getDraft();
        $this->assertSame('Mine', $stored->data->company->name, 'the stale save overwrote the current draft');
        $this->assertSame('first@x', $stored->updatedBy);

        // And the admin who reloads can then save, because he now holds the
        // fingerprint the row actually has.
        Repo::saveDraft($this->site($theirs), 'second@x', $stored->revision);
        $this->assertSame('Theirs', Repo::getDraft()->data->company->name);
    }

    /**
     * A save with no fingerprint is still last-writer-wins, and deliberately
     * so: a seed, a restore and a script have no screen that could have gone
     * stale, and making them carry one would only invent a token to ignore.
     */
    public function testASaveThatCarriesNoFingerprintKeepsTheOldBehaviour(): void
    {
        Repo::publishVersion($this->args(Seed::site(), 'seed', 'seed'));

        $first                    = Seed::data();
        $first['company']['name'] = 'First';
        Repo::saveDraft($this->site($first), 'first@x');

        $second                    = Seed::data();
        $second['company']['name'] = 'Second';
        Repo::saveDraft($this->site($second), 'second@x');

        $this->assertSame('Second', Repo::getDraft()->data->company->name);
        $this->assertSame(1, Repo::getDraft()->baseVersion, 'saving is still not re-basing');
    }

    /* -------------------------------------------- the publish-time schema */

    /**
     * THE CHECK THAT COULD NOT FIRE.
     *
     * `publishVersion()` ran `Schema::firstIssue()` on `$args->data->toArray()`
     * and called it the last line of defence. `SiteData::fromArray()` has
     * already coerced by then: a `services` key that arrived as a STRING is an
     * empty list, so the check looked at a document that could not be invalid
     * and the broken submission published anyway - as a document nobody sent.
     *
     * The second half of this test is the proof that the first half is not
     * decoration: the SAME submission, with the raw document withheld, still
     * publishes clean.
     */
    public function testPublishingChecksTheDocumentThatWasSubmittedNotTheOneNormalisationMade(): void
    {
        Repo::publishVersion($this->args(Seed::site(), 'seed', 'seed'));

        $submitted             = Seed::data();
        $submitted['services'] = 'yes';

        $e = $this->assertThrows(
            \InvalidArgumentException::class,
            fn () => Repo::publishVersion(new PublishArgs(
                data: $this->site($submitted),
                by: 'owner@x',
                source: 'admin',
                summary: '1 change',
                changeCount: 1,
                expectedBase: 1,
                liftHold: false,
                raw: $submitted,
            ))
        );

        $this->assertStringContains('services', $e->getMessage());
        $this->assertStringContains('refusing to publish an invalid document', $e->getMessage());
        $this->assertSame(1, Repo::getLatestVersion()?->version, 'the broken document was published');

        // Without the raw document there is nothing left to complain about:
        // normalisation turned "yes" into an empty list on the way in.
        $this->assertNull(Schema::firstIssue($this->site($submitted)->toArray()));

        $through = Repo::publishVersion($this->args($this->site($submitted), 'owner@x', 'admin', 1, 1));
        $this->assertSame(2, $through->version);

        // And what went live is a document nobody sent: `Migrate` answers a
        // services key it cannot read with the SEED's services, so the version
        // customers would have been priced from carries a service list the
        // submitter never typed. That is the accident the raw check refuses.
        $stored = $through->data->toArray()['services'];
        $this->assertTrue(is_array($stored) && array_is_list($stored) && $stored !== []);
        $this->assertSame(
            count(Seed::data()['services']),
            count($stored),
            'the published services are not the seed\'s, so this test no longer shows what it claims',
        );
    }

    public function testKeepsNamingADestinationOrServiceAfterItLeavesTheRateCard(): void
    {
        Repo::publishVersion($this->args(Seed::site(), 'seed', 'seed'));

        $live = Repo::getLatestVersion();
        $this->assertSame('United Kingdom', Repo::namesFor('destinations', $live?->data, ['gb', 'ca'])['gb'] ?? null);

        $renamed = Seed::data();
        foreach ($renamed['destinations'] as $i => $dest) {
            if ($dest['id'] === 'gb') {
                $renamed['destinations'][$i]['name'] = 'Britain';
            }
        }
        Repo::publishVersion($this->args($this->site($renamed), 't', 'admin'));

        $removed                 = $renamed;
        $removed['destinations'] = array_values(array_filter(
            $removed['destinations'],
            static fn (array $d): bool => $d['id'] !== 'gb'
        ));
        $removed['services'] = array_values(array_filter(
            $removed['services'],
            static fn (array $s): bool => $s['id'] !== 'normal'
        ));
        $v3 = Repo::publishVersion($this->args($this->site($removed), 't', 'admin'));

        $this->assertSame(3, $v3->version);

        $dests = Repo::namesFor('destinations', $v3->data, ['gb', 'ca', 'zz', '']);
        $this->assertSame('Britain', $dests['gb'] ?? null, 'the last name it had is what history answers');
        $this->assertSame('Canada', $dests['ca'] ?? null);
        $this->assertFalse(array_key_exists('zz', $dests), 'an id no version ever knew is left to the caller');
        $this->assertFalse(array_key_exists('', $dests));

        $services = Repo::namesFor('services', $v3->data, ['normal', 'express']);
        $this->assertSame('Normal', $services['normal'] ?? null);
        $this->assertSame('Express', $services['express'] ?? null);

        // The live document wins for an id it carries, and an empty one still answers from history.
        $back = $renamed;
        foreach ($back['destinations'] as $i => $dest) {
            if ($dest['id'] === 'gb') {
                $back['destinations'][$i]['name'] = 'Great Britain';
            }
        }
        $this->assertSame('Great Britain', Repo::namesFor('destinations', $this->site($back), ['gb'])['gb'] ?? null);
        $this->assertSame('Britain', Repo::namesFor('destinations', null, ['gb'])['gb'] ?? null);
    }

    public function testTheHistoryListsNewestFirstWithItsSummaries(): void
    {
        Repo::publishVersion($this->args(Seed::site(), 'seed', 'seed'));
        Repo::publishVersion(new PublishArgs(Seed::site(), 'owner@x', 'import:rates.xlsx', '3 changes: 2 destinations', 3));

        $history = Repo::listVersions(10);

        $this->assertSame(2, count($history));
        $this->assertSame(2, $history[0]->version);
        $this->assertSame('import:rates.xlsx', $history[0]->source);
        $this->assertSame('3 changes: 2 destinations', $history[0]->summary);
        $this->assertSame(3, $history[0]->changeCount);
        $this->assertSame(1, $history[1]->version);
        $this->assertSame('seed', $history[1]->source);
    }

    public function testPublishingCanLiftThePriceHold(): void
    {
        Hold::set(true, 'owner@x', 'Updating rates');
        $this->assertTrue(Hold::get()->on);

        Repo::publishVersion($this->args(Seed::site(), 'seed', 'seed'));
        $this->assertTrue(Hold::get()->on, 'a publish leaves the hold alone unless the publisher says otherwise');

        Repo::publishVersion(new PublishArgs(Seed::site(), 'owner@x', 'admin', 'x', 0, null, true));

        $hold = Hold::get();
        $this->assertFalse($hold->on);
        $this->assertSame('Updating rates', $hold->message, 'lifting the hold keeps the sentence for next time');
    }

    /**
     * The one that needed real processes: two publishers, two connections, one
     * instant. They must come out as n+1 and n+2, never both as n+1 and never
     * with one silently overwriting the other.
     */
    public function testConcurrentPublishesAreNumberedConsecutively(): void
    {
        if (!function_exists('proc_open')) {
            $this->skip('proc_open is disabled in this PHP, so two publishers cannot be raced');
        }

        $child = Paths::root() . '/tests/SiteDoc/publish-child.php';
        if (!is_file($child)) {
            $this->skip('tests/SiteDoc/publish-child.php is missing');
        }

        Repo::publishVersion($this->args(Seed::site(), 'seed', 'seed'));

        $database = Config::str('db.name');
        $cache    = $this->useScratchCache();
        $start    = microtime(true) + 1.5;

        $processes = [];
        foreach (['p1', 'p2', 'p3'] as $label) {
            $pipes   = [];
            $process = proc_open(
                [PHP_BINARY, $child, $database, (string) $start, $label, $cache],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );

            if (!is_resource($process)) {
                $this->fail('could not start a publisher process');
            }

            $processes[] = ['process' => $process, 'pipes' => $pipes, 'label' => $label];
        }

        $versions = [];
        $errors   = [];

        foreach ($processes as $entry) {
            $out = (string) stream_get_contents($entry['pipes'][1]);
            $err = (string) stream_get_contents($entry['pipes'][2]);
            fclose($entry['pipes'][1]);
            fclose($entry['pipes'][2]);
            proc_close($entry['process']);

            $result = json_decode(trim($out), true);

            if (!is_array($result) || ($result['ok'] ?? false) !== true) {
                $errors[] = $entry['label'] . ': ' . (is_array($result) ? (string) ($result['error'] ?? '?') : $out . $err);
                continue;
            }

            $versions[] = (int) $result['version'];
        }

        $this->assertSame([], $errors, 'every publisher should have got its turn');

        sort($versions);
        $this->assertSame([2, 3, 4], $versions, 'three publishers, three consecutive numbers, no duplicate');

        $stored = [];
        foreach (Repo::listVersions(10) as $meta) {
            $stored[] = $meta->version;
        }
        $this->assertSame([4, 3, 2, 1], $stored);

        $this->assertSame(
            4,
            (int) Db::value('SELECT COUNT(*) FROM versions'),
            'no publisher lost its row to another'
        );
    }

    /* ------------------------------------------------------------ helpers */

    private function args(
        SiteData $data,
        string $by,
        string $source,
        int $changeCount = 0,
        ?int $expectedBase = null,
    ): PublishArgs {
        return new PublishArgs(
            data: $data,
            by: $by,
            source: $source,
            summary: $changeCount . ' change' . ($changeCount === 1 ? '' : 's'),
            changeCount: $changeCount,
            expectedBase: $expectedBase,
        );
    }
}
