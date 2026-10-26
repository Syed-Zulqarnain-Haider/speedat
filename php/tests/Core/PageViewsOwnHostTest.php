<?php

declare(strict_types=1);

namespace Speedat\Tests\Core;

use Speedat\Core\Config;
use Speedat\Core\Db;
use Speedat\Domain\Analytics\PageViews;
use Speedat\Tests\TestCase;

/**
 * Rows written before own-site referrals were refused (2026-10-25) are still
 * in the table - on speedat.co 296 of 1,071 views, the site itself the
 * second-biggest "referrer". Every read leaves the site's own host out.
 */
final class PageViewsOwnHostTest extends TestCase
{
    private const DAY = '2001-02-03';

    private string $url = '';

    public function setUp(): void
    {
        if (!Db::healthy()) {
            $this->skip('the test database is not reachable');
        }
        $this->url = Config::str('app.url');
        Config::set('app.url', 'https://speedat.co');
        Db::exec('DELETE FROM page_views WHERE day = ?', [self::DAY]);
        foreach ([['', 5], ['speedat.co', 7], ['google.com', 3]] as [$host, $n]) {
            Db::exec(
                'INSERT INTO page_views (day, path, device, referrer_host, views) VALUES (?, ?, ?, ?, ?)',
                [self::DAY, '/', 'phone', $host, $n]
            );
        }
    }

    public function tearDown(): void
    {
        Config::set('app.url', $this->url);
        if (Db::healthy()) {
            Db::exec('DELETE FROM page_views WHERE day = ?', [self::DAY]);
        }
    }

    public function testOwnSiteRowsAreLeftOutOfEveryCount(): void
    {
        $views = new PageViews();
        $day   = new \DateTimeImmutable(self::DAY);

        $this->assertSame(8, $views->total($day, $day), 'own-site referrals were counted as landings');
        $this->assertSame(['' => 5, 'google.com' => 3], $views->byReferrer($day, $day));
        $this->assertSame([self::DAY => 8], $views->byDay($day, $day));
    }
}
