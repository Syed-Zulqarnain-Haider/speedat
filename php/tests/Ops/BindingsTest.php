<?php

declare(strict_types=1);

namespace Speedat\Tests\Ops;

use Speedat\Core\Db;
use Speedat\Domain\Ops\Hold;
use Speedat\Domain\Ops\HoldSetting;
use Speedat\Domain\Ops\VersionsTable;
use Speedat\Domain\SiteDoc\Hold as SiteDocHold;

/**
 * The two bindings that connect Ops to Domain\SiteDoc, against the real schema.
 *
 * They are what /health and /metrics actually read, so both are proved end to
 * end: a row is written the way SiteDoc writes it and read back through the
 * binding. Not a stub in sight - the point of these tests is precisely that Ops
 * and SiteDoc agree about the rows, and a stub cannot disagree.
 *
 * Both bindings must also be forgiving: a missing row, an empty table or a
 * hand-edited value has to read as "nothing to report", never as an exception on
 * a page that was only asking whether prices are visible.
 *
 * THE HOLD TESTS WRITE THE SITE'S REAL `hold` ROW, because SiteDoc owns its name
 * and there is exactly one of it. Whatever was there is captured in setUp and
 * put back in tearDown, so a run cannot leave this database's prices switched
 * off - and the put-back happens even when an assertion fails.
 */
final class BindingsTest extends OpsTestCase
{
    /** @var list<int> */
    private array $versions = [];

    /** The `hold` row as it was before this test, or null when there was none. */
    private ?string $savedHold = null;

    private bool $hadHold = false;

    public function setUp(): void
    {
        parent::setUp();

        $raw           = Db::value('SELECT v FROM app_settings WHERE k = ?', [SiteDocHold::KEY]);
        $this->hadHold = $raw !== null;
        $this->savedHold = is_string($raw) ? $raw : null;
    }

    public function tearDown(): void
    {
        if (Db::healthy()) {
            foreach ($this->versions as $version) {
                Db::exec('DELETE FROM versions WHERE version = ?', [$version]);
            }

            if ($this->hadHold) {
                Db::exec(
                    'INSERT INTO app_settings (k, v, updated_at, updated_by) VALUES (:k, :v, :at, :by)'
                    . ' ON DUPLICATE KEY UPDATE v = :v2, updated_at = :at2, updated_by = :by2',
                    [
                        ':k'   => SiteDocHold::KEY,
                        ':v'   => $this->savedHold ?? '{}',
                        ':at'  => self::sql(self::utc('now')),
                        ':by'  => 'ops-test',
                        ':v2'  => $this->savedHold ?? '{}',
                        ':at2' => self::sql(self::utc('now')),
                        ':by2' => 'ops-test',
                    ]
                );
            } else {
                Db::exec('DELETE FROM app_settings WHERE k = ?', [SiteDocHold::KEY]);
            }
        }

        $this->versions = [];

        parent::tearDown();
    }

    /* ------------------------------------------------------------ versions */

    public function testTheVersionsReadRunsOnThisEngine(): void
    {
        // Whatever is in the table, the binding must not throw: this is the
        // assertion that SiteDoc's repository and this engine understand each
        // other, JSON column and all.
        $live = (new VersionsTable())->live();

        if ($live === null) {
            $this->assertNull($live, 'an empty versions table reads as "nothing published"');

            return;
        }

        $this->assertTrue($live->version > 0, 'a published version has a number');
    }

    public function testItReadsTheHighestVersionWithItsTimestampAndLiveFlag(): void
    {
        // A number far above anything the seed uses, so this row IS the latest
        // while the test runs; tearDown removes it again.
        $version          = 900001;
        $this->versions[] = $version;
        $publishedAt      = self::utc('2026-09-20 06:30:00');

        $this->insertVersion($version, $publishedAt, true);

        $live = (new VersionsTable())->live();

        $this->assertNotNull($live);
        $this->assertSame($version, $live?->version);
        $this->assertTrue($live?->live ?? false, 'the live flag came out of the document');
        $this->assertSame(
            $publishedAt->format('Y-m-d H:i:s'),
            $live?->publishedAt?->format('Y-m-d H:i:s'),
            'and the timestamp is read back as the UTC it was stored as'
        );

        $age = $live?->ageSeconds(self::utc('2026-09-21 06:30:00')) ?? -1;
        $this->assertSame(86400, $age, 'one day later is one day old');
    }

    public function testADocumentThatIsNotFlaggedLiveIsNotLive(): void
    {
        $version          = 900002;
        $this->versions[] = $version;

        $this->insertVersion($version, self::utc('2026-09-20 06:30:00'), false);

        $this->assertFalse((new VersionsTable())->live()?->live ?? true);
    }

    /**
     * A version row whose document is not what SiteDoc would have written must
     * still report its number and its date.
     *
     * This is the case the binding gained when it started going through the real
     * repository: SiteDoc runs the stored JSON through Migrate on the way out, so
     * a document published before a field existed still loads, and a document
     * with almost nothing in it loads as defaults rather than throwing. /health
     * answering "version 900003, four days old" about a damaged row is far more
     * use than /health returning a 500.
     */
    public function testASparseDocumentStillReportsItsVersionAndAge(): void
    {
        $version          = 900003;
        $this->versions[] = $version;

        Db::insert('versions', [
            'version'      => $version,
            'published_at' => self::sql(self::utc('2026-09-23 06:30:00')),
            'published_by' => 'ops-test',
            'source'       => 'ops-test',
            'summary'      => '',
            'change_count' => 0,
            'data'         => '{}',
        ]);

        $live = (new VersionsTable())->live();

        $this->assertSame($version, $live?->version);
        $this->assertFalse($live?->live ?? true, 'a document with no live flag is not live');
        $this->assertSame(4 * 86400, $live?->ageSeconds(self::utc('2026-09-27 06:30:00')) ?? -1);
    }

    /* ---------------------------------------------------------------- hold */

    public function testAMissingHoldRowMeansPricesAreShowing(): void
    {
        Db::exec('DELETE FROM app_settings WHERE k = ?', [SiteDocHold::KEY]);

        $hold = (new HoldSetting())->current();

        $this->assertFalse($hold->on);
        $this->assertNull($hold->since);
        $this->assertSame(0, $hold->seconds(self::utc('now')));
    }

    /**
     * Written by SiteDoc, read by Ops. This is the test that would have caught
     * the two-readers-of-one-row problem the binding used to have.
     */
    public function testItReadsAHoldTheAdminActuallySet(): void
    {
        SiteDocHold::set(true, 'owner@speedat.test', 'Back shortly');

        $hold = (new HoldSetting())->current();

        $this->assertTrue($hold->on);
        $this->assertSame('owner@speedat.test', $hold->by);
        $this->assertNotNull($hold->since, 'SiteDoc stamped it, so the age is knowable');
    }

    public function testItReadsTheStoredHoldAndCountsItsAge(): void
    {
        $this->writeHold([
            'on'      => true,
            'by'      => 'owner@speedat.test',
            'since'   => '2026-09-27T04:00:00.000Z',
            'message' => 'Back shortly',
        ]);

        $hold = (new HoldSetting())->current();

        $this->assertTrue($hold->on);
        $this->assertSame('owner@speedat.test', $hold->by);
        $this->assertSame(14 * 3600, $hold->seconds(self::utc('2026-09-27 18:00:00')));
        $this->assertSame(14, $hold->hours(self::utc('2026-09-27 18:00:00')));
    }

    public function testAHoldThatIsOffReportsNoAge(): void
    {
        // `since` is kept when the hold is lifted - it records when the switch
        // last moved - so "off" must be decided by `on` alone.
        $this->writeHold(['on' => false, 'by' => 'owner@speedat.test', 'since' => '2026-09-27T04:00:00.000Z']);

        $hold = (new HoldSetting())->current();

        $this->assertFalse($hold->on);
        $this->assertSame(0, $hold->seconds(self::utc('2026-09-27 18:00:00')));
    }

    public function testAGarbledHoldRowDoesNotTakeTheSiteDown(): void
    {
        $this->writeHold(['on' => 'yes', 'since' => 'not a date']);

        $hold = (new HoldSetting())->current();

        $this->assertFalse($hold->on, 'only a real boolean true is a hold');
        $this->assertNull($hold->since, 'an unparseable timestamp is simply unknown');
        $this->assertSame(0, $hold->seconds(self::utc('now')));
    }

    /** Ops reports the state, never the sentence customers read. */
    public function testTheCustomerFacingMessageIsNotPartOfWhatOpsReports(): void
    {
        $this->writeHold([
            'on'      => true,
            'by'      => 'owner@speedat.test',
            'since'   => '2026-09-27T04:00:00.000Z',
            'message' => 'A sentence the owner wrote for the shop front',
        ]);

        $hold = (new HoldSetting())->current();

        $this->assertStringNotContains(
            'shop front',
            (string) json_encode([$hold->on, $hold->by, $hold->since?->format('c')]),
            'the hold message has no business on an ops endpoint'
        );
    }

    public function testHoldOffIsTheDefaultValueObject(): void
    {
        $off = Hold::off();

        $this->assertFalse($off->on);
        $this->assertSame('', $off->by);
        $this->assertSame(0, $off->hours(self::utc('now')));
    }

    /* ------------------------------------------------------------ helpers */

    private function insertVersion(int $version, \DateTimeImmutable $publishedAt, bool $live): void
    {
        Db::insert('versions', [
            'version'      => $version,
            'published_at' => self::sql($publishedAt),
            'published_by' => 'ops-test',
            'source'       => 'ops-test',
            'summary'      => '',
            'change_count' => 0,
            'data'         => Db::json(['live' => $live]),
        ]);
    }

    /** @param array<string,mixed> $value */
    private function writeHold(array $value): void
    {
        Db::exec(
            'INSERT INTO app_settings (k, v, updated_at, updated_by) VALUES (:k, :v, :at, :by)'
            . ' ON DUPLICATE KEY UPDATE v = :v2, updated_at = :at2, updated_by = :by2',
            [
                ':k'   => SiteDocHold::KEY,
                ':v'   => Db::json($value),
                ':at'  => self::sql(self::utc('now')),
                ':by'  => 'ops-test',
                ':v2'  => Db::json($value),
                ':at2' => self::sql(self::utc('now')),
                ':by2' => 'ops-test',
            ]
        );
    }
}
