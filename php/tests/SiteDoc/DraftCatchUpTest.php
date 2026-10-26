<?php

declare(strict_types=1);

namespace Speedat\Tests\SiteDoc;

use Speedat\Core\Db;
use Speedat\Domain\SiteDoc\PublishArgs;
use Speedat\Domain\SiteDoc\Repo;
use Speedat\Domain\SiteDoc\SiteData;

/**
 * `Repo::catchUpDraft()` against the database, in the state speedat.co was
 * really in: a version written straight into `versions` (as 18 was on
 * 2026-10-11), a draft still saying it came from the one before, and the
 * owner's edits in that draft.
 */
final class DraftCatchUpTest extends SiteDocTestCase
{
    public function setUp(): void
    {
        $this->useScratchCache();
        $this->needDatabase();
        $this->emptyStore();
        Db::exec("DELETE FROM audit_log WHERE action = 'draft_rebased'");

        // Version 1 through the front door, so the draft is branched from it.
        $doc = $this->seedArray();
        $doc['settings']['addons']  = '';
        $doc['settings']['showEta'] = true;
        Repo::publishVersion(new PublishArgs(data: SiteData::fromArray($doc), by: 'owner@test', source: 'admin', summary: 'v1', changeCount: 0));
    }

    public function tearDown(): void
    {
        if (Db::healthy()) {
            $this->emptyStore();
            Db::exec("DELETE FROM audit_log WHERE action = 'draft_rebased'");
        }

        $this->dropScratchCache();
    }

    public function testADraftOnTheLiveVersionIsLeftAlone(): void
    {
        $before = Repo::getDraft();

        $this->assertNull(Repo::catchUpDraft());
        $this->assertSame($before->revision, Repo::getDraft()->revision, 'a draft with nothing to catch up was rewritten');
        $this->assertNull(Repo::rebaseNote());
    }

    public function testABehindDraftKeepsTheOwnersEditsAndEverythingTheNewerVersionChanged(): void
    {
        $this->ownerRepricesUs(77777.0);
        $this->versionWrittenBehindTheAppsBack();

        $note = Repo::catchUpDraft();

        $this->assertNotNull($note);
        $this->assertSame(1, $note->from);
        $this->assertSame(2, $note->to);
        $this->assertSame([], $note->conflicts);

        $draft = Repo::getDraft();
        $doc   = $draft->data->toArray();
        $this->assertSame(2, $draft->baseVersion, 'the draft was not moved onto the live version');
        $this->assertSame('Delivery | 1000 | on', $doc['settings']['addons'], 'the newer version\'s add-on was undone');
        $this->assertFalse($doc['settings']['showEta'], 'the newer version\'s dates setting was undone');
        $this->assertSame(77777.0, (float) $this->usFirst($doc), 'the owner\'s price was lost');
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM audit_log WHERE action = 'draft_rebased'"), 'the catch-up was not written to the diary');
    }

    public function testTheNoteSurvivesUntilThePublishThatUsesItAndThePublishKeepsBothSides(): void
    {
        $this->ownerRepricesUs(77777.0);
        $this->versionWrittenBehindTheAppsBack();
        Repo::catchUpDraft();

        $this->assertNotNull(Repo::rebaseNote(), 'the note was not kept for the next visit');

        $published = Repo::publishVersion(new PublishArgs(
            data: Repo::getDraft()->data,
            by: 'owner@test',
            source: 'admin',
            summary: 'after catch-up',
            changeCount: 1,
            expectedBase: 2,
        ));

        $doc = $published->data->toArray();
        $this->assertSame(3, $published->version);
        $this->assertSame('Delivery | 1000 | on', $doc['settings']['addons']);
        $this->assertSame(77777.0, (float) $this->usFirst($doc));
        $this->assertNull(Repo::rebaseNote(), 'the note outlived the publish');
    }

    public function testDiscardingTheDraftClearsTheNote(): void
    {
        $this->ownerRepricesUs(77777.0);
        $this->versionWrittenBehindTheAppsBack();
        Repo::catchUpDraft();

        Repo::discardDraft();

        $this->assertNull(Repo::rebaseNote());
    }

    public function testAFieldChangedOnBothSidesKeepsTheLiveValueAndIsReported(): void
    {
        $draft = Repo::getDraft()->data->toArray();
        $draft['settings']['addons'] = 'Pickup | 500 | on';
        Repo::saveDraft(SiteData::fromArray($draft), 'owner@test');
        $this->versionWrittenBehindTheAppsBack();

        $note = Repo::catchUpDraft();

        $this->assertCount(1, $note->conflicts);
        $this->assertStringContains('addons', $note->conflicts[0]['path']);
        $this->assertSame('Delivery | 1000 | on', Repo::getDraft()->data->toArray()['settings']['addons'], 'a clash put the unreviewed draft value live-bound');
        $this->assertStringContains('Pickup | 500 | on', implode("\n", $note->conflictLines()), 'the owner\'s own value is not shown back');
    }

    public function testRunningItTwiceDoesNothingTheSecondTime(): void
    {
        $this->ownerRepricesUs(77777.0);
        $this->versionWrittenBehindTheAppsBack();
        Repo::catchUpDraft();
        $revision = Repo::getDraft()->revision;

        $this->assertNull(Repo::catchUpDraft());
        $this->assertSame($revision, Repo::getDraft()->revision);
    }

    public function testWhenTheDraftsOriginalVersionIsGoneNothingIsGuessed(): void
    {
        $this->ownerRepricesUs(77777.0);
        $this->versionWrittenBehindTheAppsBack();
        Db::exec('DELETE FROM versions WHERE version = 1');

        $this->assertNull(Repo::catchUpDraft());
        $this->assertSame(1, Repo::getDraft()->baseVersion, 'a draft with no known origin was merged anyway');
    }

    private function ownerRepricesUs(float $price): void
    {
        $doc = Repo::getDraft()->data->toArray();
        foreach ($doc['destinations'] as $i => $d) {
            if ($d['id'] === 'us') {
                foreach (array_keys($doc['destinations'][$i]['rates']) as $svc) {
                    if (isset($doc['destinations'][$i]['rates'][$svc]['first'])) {
                        $doc['destinations'][$i]['rates'][$svc]['first'] = $price;
                        break;
                    }
                }
            }
        }
        Repo::saveDraft(SiteData::fromArray($doc), 'owner@test');
    }

    /** What happened on 2026-10-11: a version inserted with SQL, the draft row untouched. */
    private function versionWrittenBehindTheAppsBack(): void
    {
        $doc = Repo::getVersion(1)->data->toArray();
        $doc['settings']['addons']  = 'Delivery | 1000 | on';
        $doc['settings']['showEta'] = false;

        Db::exec(
            'INSERT INTO versions (version, published_at, published_by, source, summary, change_count, data) VALUES (2, UTC_TIMESTAMP(), ?, ?, ?, 2, ?)',
            ['hand', 'sql', 'Delivery charge on; ETA dates off', Db::json(SiteData::fromArray($doc)->toArray())]
        );
    }

    /** @param array<string,mixed> $doc */
    private function usFirst(array $doc): mixed
    {
        foreach ($doc['destinations'] as $d) {
            if ($d['id'] === 'us') {
                foreach ($d['rates'] as $rate) {
                    if (isset($rate['first'])) {
                        return $rate['first'];
                    }
                }
            }
        }

        return null;
    }
}
