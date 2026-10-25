<?php

declare(strict_types=1);

namespace Speedat\Tests\Ops;

use Speedat\Core\Audit;
use Speedat\Core\Db;
use Speedat\Domain\Ops\Counts;

/**
 * The counts helper, against the raw queries it is supposed to replace.
 *
 * This is the test that exists because the Next.js admin had three different
 * numbers for "sheets waiting": each caller counted from its own truncated
 * list. Every number here is checked twice - once through Counts and once with
 * the query written out by hand - and the two have to agree.
 */
final class CountsTest extends OpsTestCase
{
    public function testEveryCountAgreesWithTheRawQuery(): void
    {
        $now   = self::utc('2026-09-27 12:00:00');
        $since = self::sql($now->sub(new \DateInterval('PT24H')));

        // Two quotes inside the window, one booked; one outside it.
        $this->insertQuote($now->sub(new \DateInterval('PT2H')));
        $this->insertQuote($now->sub(new \DateInterval('PT3H')), true);
        $this->insertQuote($now->sub(new \DateInterval('PT30H')));

        $this->insertLead('new');
        $this->insertShipment('in_transit');
        $this->insertShipment('exception');

        $counts = new Counts($now);

        $this->assertSame(
            (int) Db::value('SELECT COUNT(*) FROM quotes WHERE created_at >= ?', [$since]),
            $counts->quotes24h(),
            'quotes in the last 24 hours'
        );
        $this->assertSame(
            (int) Db::value('SELECT COUNT(*) FROM quotes WHERE created_at >= ? AND booked = 1', [$since]),
            $counts->quotesBooked24h(),
            'booked quotes in the last 24 hours'
        );
        $this->assertSame(
            (int) Db::value("SELECT COUNT(*) FROM leads WHERE status = 'new'"),
            $counts->leadsNew(),
            'leads waiting in the inbox'
        );
        $this->assertSame(
            (int) Db::value("SELECT COUNT(*) FROM shipments WHERE status <> 'delivered'"),
            $counts->shipmentsOpen(),
            'shipments not yet delivered'
        );
        $this->assertSame(
            (int) Db::value("SELECT COUNT(*) FROM shipments WHERE status = 'exception'"),
            $counts->shipmentsException(),
            'shipments needing attention'
        );
    }

    public function testTheWindowExcludesWhatIsOlderThanADay(): void
    {
        $now = self::utc('2026-09-27 12:00:00');

        $before = new Counts($now);
        $baseQuotes = $before->quotes24h();
        $baseBooked = $before->quotesBooked24h();

        $this->insertQuote($now->sub(new \DateInterval('PT1H')));
        $this->insertQuote($now->sub(new \DateInterval('PT1H')), true);
        // 25 hours old: outside the window, and it must not be counted.
        $this->insertQuote($now->sub(new \DateInterval('PT25H')));
        $this->insertQuote($now->sub(new \DateInterval('PT25H')), true);

        $after = new Counts($now);

        $this->assertSame($baseQuotes + 2, $after->quotes24h(), 'only the two inside the window');
        $this->assertSame($baseBooked + 1, $after->quotesBooked24h(), 'only the booked one inside the window');
    }

    public function testAdminBadgeAndMetricsReadTheSameNumber(): void
    {
        $now = self::utc('2026-09-27 12:00:00');

        $before = (new Counts($now))->leadsNew();

        $this->insertLead('new');
        $this->insertLead('new');
        $this->insertLead('contacted');

        // Two separate instances, as the badge and the scrape would be.
        $badge   = (new Counts($now))->leadsNew();
        $scraped = (new Counts($now))->all()['leadsNew'];

        $this->assertSame($before + 2, $badge, 'a contacted lead is not waiting in the inbox');
        $this->assertSame($badge, $scraped, 'the badge and the metrics page cannot disagree');
    }

    public function testFailedNotificationsAreCountedFromTheAuditLog(): void
    {
        $now = self::utc('now');

        $before = (new Counts($now))->notifyFailed24h();

        // audit_log is append-only, so these rows stay; the assertion is a
        // delta for exactly that reason.
        Audit::log('system', 'notify_failed', ['test' => $this->tag]);
        Audit::log('system', 'mail_skipped', ['test' => $this->tag]);
        Audit::log('system', 'rates_published', ['test' => $this->tag]);

        $this->assertSame(
            $before + 2,
            (new Counts($now))->notifyFailed24h(),
            'a publish is not a failed notification'
        );
    }

    public function testTheSameInstanceAsksTheDatabaseOnlyOnce(): void
    {
        $now    = self::utc('2026-09-27 12:00:00');
        $counts = new Counts($now);

        $first = $counts->leadsNew();

        // A row added behind its back must NOT change the memoised answer: one
        // scrape is one consistent set of numbers, not six taken at six times.
        $this->insertLead('new');

        $this->assertSame($first, $counts->leadsNew(), 'the instance is a snapshot');
        $this->assertSame($first + 1, (new Counts($now))->leadsNew(), 'a fresh instance sees the new row');
    }
}
