<?php

declare(strict_types=1);

namespace Speedat\Tests\ViewAdmin;

use Speedat\Core\Clock;
use Speedat\Domain\Analytics\PageViews;
use Speedat\View\Admin\AnalyticsPage;

/**
 * The analytics screen's fixes of 2026-10-25: a capped country list whose
 * shares are of every quote rather than of the twenty printed, and the
 * referrer, device and page the counter has always stored, finally shown.
 *
 * Nothing here touches the database: the page model is built from values, the
 * way Http\Admin\AnalyticsController builds it from the two readers.
 */
final class B14AnalyticsTest extends AdminViewTestCase
{
    public function tearDown(): void
    {
        Clock::freeze(null);
        parent::tearDown();
    }

    /* ---------------- shares of a capped list ---------------- */

    public function testCountrySharesAreOfEveryQuoteNotOfTheTopTwenty(): void
    {
        // The UK at 50 of 400 quotes, with only 350 of them in the rows shown.
        $page = $this->page([
            'quotesTotal' => 400,
            'countries'   => [['label' => 'United Kingdom', 'n' => 50], ['label' => 'UAE', 'n' => 300]],
        ]);

        $rows = $page->countriesRanked();

        $this->assertSame('12.5%', $rows[0]['share'], 'was 14.3% of the rows printed');
        $this->assertSame(17, $rows[0]['pct'], 'the bar is still of the biggest row');
    }

    public function testAStaleTotalSmallerThanTheRowsCannotPrintOverAHundred(): void
    {
        $page = $this->page(['quotesTotal' => 10, 'countries' => [['label' => 'UAE', 'n' => 40]]]);

        $this->assertSame('100%', $page->countriesRanked()[0]['share']);
    }

    public function testCompleteListsStillAddUpToTheirOwnRows(): void
    {
        $page = $this->page(['quotesTotal' => 400, 'services' => [['label' => 'DHL', 'n' => 3], ['label' => 'FedEx', 'n' => 1]]]);

        $this->assertSame('75%', $page->servicesRanked()[0]['share']);
    }

    /* ---------------- how they arrived ---------------- */

    public function testReferrersDevicesAndPagesAreLabelledForTheOwner(): void
    {
        $page = $this->page([
            'landingsTotal' => 200,
            'referrers'     => ['' => 90, 'google.com' => 60, 'facebook.com' => 10],
            'devices'       => [PageViews::PHONE => 150, PageViews::DESKTOP => 50],
            'pages'         => ['/' => 120, '/services' => 70, PageViews::OTHER => 10],
        ]);

        $refs = $page->referrersRanked();
        $this->assertSame('Direct, or an app that does not say', $refs[0]['label'], 'the empty host is direct');
        $this->assertSame('google.com', $refs[1]['label']);
        $this->assertSame('30%', $refs[1]['share'], 'a capped list is a share of every landing');

        $this->assertSame(['Phone', 'Computer or tablet'], array_column($page->devicesRanked(), 'label'));
        $this->assertSame('75%', $page->devicesRanked()[0]['share']);

        $this->assertSame(['Home page', '/services', 'Any other address'], array_column($page->pagesRanked(), 'label'));
    }

    public function testTheScreenShowsHowPeopleArrived(): void
    {
        $html = $this->render($this->page([
            'landingsTotal' => 5,
            'landingsByDay' => ['2026-10-20' => 5],
            'referrers'     => ['' => 2, 'l.facebook.com' => 3],
            'devices'       => [PageViews::PHONE => 5],
            'pages'         => ['/contact' => 5],
            'quotesTotal'   => 4,
            'countries'     => [['label' => 'United Kingdom', 'n' => 4]],
        ]));

        $this->assertStringContains('How people arrived', $html);
        $this->assertStringContains('l.facebook.com', $html);
        $this->assertStringContains('Phone', $html);
        $this->assertStringContains('/contact', $html);
        $this->assertStringContains('Top countries', $html, 'the quote lists still render through the same block');
        $this->assertStringNotContains("\u{2014}", $html, 'plain " - ", never an em dash (owner, 2026-10-11)');
    }

    public function testNothingCountedShowsNoArrivalsSection(): void
    {
        $html = $this->render($this->page([]));

        $this->assertStringNotContains('How people arrived', $html, 'the empty-state sentence already says it once');
    }

    public function testAHostileReferrerIsEscaped(): void
    {
        $html = $this->render($this->page([
            'landingsTotal' => 1,
            'landingsByDay' => ['2026-10-20' => 1],
            'referrers'     => [self::HOSTILE => 1],
        ]));

        $this->assertStringNotContains('<script>alert(1)', $html);
    }

    /* ---------------- fixture ---------------- */

    /** @param array<string,mixed> $overrides constructor arguments by name */
    private function page(array $overrides): AnalyticsPage
    {
        Clock::freeze('2026-10-25 12:00:00');

        $zone = Clock::zone();

        return new AnalyticsPage(...array_merge([
            'from'            => new \DateTimeImmutable('2026-10-19 00:00:00', $zone),
            'to'              => new \DateTimeImmutable('2026-10-25 00:00:00', $zone),
            'landingsByDay'   => [],
            'landingsTotal'   => 0,
            'quotesTotal'     => 0,
            'whatsAppPressed' => 0,
            'countries'       => [],
            'services'        => [],
            'bands'           => [],
            'countingFrom'    => '2026-09-30',
        ], $overrides));
    }

    private function render(AnalyticsPage $page): string
    {
        return $this->view()->render('admin/analytics', ['page' => $page, 'shell' => $this->shell('analytics')]);
    }
}
