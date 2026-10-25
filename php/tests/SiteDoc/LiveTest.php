<?php

declare(strict_types=1);

namespace Speedat\Tests\SiteDoc;

use Speedat\Core\Config;
use Speedat\Core\Db;
use Speedat\Domain\SiteDoc\Hold;
use Speedat\Domain\SiteDoc\HoldState;
use Speedat\Domain\SiteDoc\Live;
use Speedat\Domain\SiteDoc\PublishArgs;
use Speedat\Domain\SiteDoc\Repo;
use Speedat\Domain\SiteDoc\Seed;

/**
 * The cached read the public site serves from. Port of src/lib/site/live.ts.
 *
 * The two behaviours that matter to a customer:
 *   - a publish or a hold switch is visible on the next request, not in five
 *     minutes, because a quoted price that the owner has already replaced is a
 *     price somebody has to honour or argue about;
 *   - an unreachable database serves the sample document with its notice
 *     instead of an error page.
 */
final class LiveTest extends SiteDocTestCase
{
    /** Restored in tearDown: a test that points the connection at a missing database must not take the next one with it. */
    private string $database = '';

    private string $cache = '';

    public function setUp(): void
    {
        $this->cache    = $this->useScratchCache();
        $this->database = Config::str('db.name');

        $this->needDatabase();
        $this->emptyStore();
        Live::bust();
    }

    public function tearDown(): void
    {
        if ($this->database !== '' && Config::str('db.name') !== $this->database) {
            Config::set('db.name', $this->database);
            Db::reset();
        }

        if (Db::healthy()) {
            $this->emptyStore();
        }

        $this->dropScratchCache();
    }

    /** Take the database away from the process, the way a dropped connection does. */
    private function breakTheDatabase(): void
    {
        Live::forget();
        Db::reset();
        Config::set('db.name', 'speedat_php_does_not_exist');
    }

    public function testAnEmptyDatabaseServesTheSampleDocumentWithItsNotice(): void
    {
        $site = Live::site();

        $this->assertSame(0, $site->version);
        $this->assertTrue($site->isSample());
        $this->assertFalse($site->data->live, 'the sample notice stays on until real rates are published');
        $this->assertSame(10, count($site->data->card->destinations));
    }

    public function testThePublishedDocumentIsServedAndThenCached(): void
    {
        Repo::publishVersion(new PublishArgs(Seed::site(), 'seed', 'seed', 'seed'));
        Live::forget();

        $first = Live::site();
        $this->assertSame(1, $first->version);
        $this->assertTrue(is_file($this->cacheFile('site.json')), 'the read was cached for the next request');

        // A new request (fresh memo) with the database taken away must still
        // answer, and answer the same thing, from the cache.
        $this->breakTheDatabase();

        $cached = Live::site();
        $this->assertSame(1, $cached->version);
        $this->assertSame($first->data->toArray(), $cached->data->toArray());
    }

    public function testAPublishIsVisibleOnTheNextRequestNotInFiveMinutes(): void
    {
        Repo::publishVersion(new PublishArgs(Seed::site(), 'seed', 'seed', 'seed'));
        Live::forget();
        $this->assertSame(1, Live::site()->version);

        $doc                                                = Seed::data();
        $doc['destinations'][0]['rates']['express']['first'] = 6100;
        Repo::publishVersion(new PublishArgs($this->site($doc), 'owner@x', 'admin', '1 change', 1));

        // No forget(): publishVersion busts the cache itself, which is the point.
        Live::forget();
        $after = Live::site();

        $this->assertSame(2, $after->version);
        $this->assertSame(6100.0, $after->data->card->destination('gb')?->rate('express')?->first);
    }

    public function testTheHoldIsCachedAndASwitchIsVisibleImmediately(): void
    {
        $this->assertFalse(Live::hold()->on);
        $this->assertTrue(is_file($this->cacheFile('hold.json')));

        Hold::set(true, 'owner@x', 'Rates being updated');
        Live::forget();

        $hold = Live::hold();
        $this->assertTrue($hold->on);
        $this->assertSame('Rates being updated', $hold->message);

        Hold::set(false, 'owner@x');
        Live::forget();
        $this->assertFalse(Live::hold()->on);
    }

    public function testAnUnreachableDatabaseShowsPricesRatherThanHoldingThem(): void
    {
        $this->breakTheDatabase();

        $this->assertSame(HoldState::none()->toArray(), Live::hold()->toArray());
        $this->assertTrue(Live::site()->isSample());
    }

    public function testABustedStampInvalidatesTheFilesWithoutDeletingThem(): void
    {
        Repo::publishVersion(new PublishArgs(Seed::site(), 'seed', 'seed', 'seed'));
        Live::forget();
        Live::site();

        $before = (string) file_get_contents($this->cacheFile('site.json'));
        $this->assertStringContains('"stamp"', $before);

        Live::bust();
        $stamped = (string) file_get_contents($this->cacheFile('site.json'));
        $this->assertSame($before, $stamped, 'busting does not have to find the files, only outdate them');

        // The stale file is ignored and the database is read again.
        Live::forget();
        $this->assertSame(1, Live::site()->version);
    }

    public function testTheFallbackIsNeverWrittenToTheCache(): void
    {
        $this->breakTheDatabase();

        $this->assertTrue(Live::site()->isSample());
        $this->assertFalse(
            is_file($this->cacheFile('site.json')),
            'a blink of the database must not pin sample prices to the site for five minutes'
        );
    }

    private function cacheFile(string $name): string
    {
        return $this->cache . '/site/' . $name;
    }
}
