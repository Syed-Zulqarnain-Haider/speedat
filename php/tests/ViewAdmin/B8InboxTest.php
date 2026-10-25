<?php

declare(strict_types=1);

namespace Speedat\Tests\ViewAdmin;

use Speedat\Domain\Leads\LeadRepository;
use Speedat\View\Admin\InboxPage;
use Speedat\View\Admin\LeadCard;
use Speedat\View\Admin\Routes;
use Speedat\View\Admin\Wa;

/**
 * The inbox fixes of 2026-10-25: a WhatsApp link that rings the number however
 * it was written, a search that finds a number however it is typed, a search
 * box that survives finding nothing, a list that reaches past its first 200,
 * and a save that comes back to where it was.
 *
 * Nothing here touches the database; the SQL side of the search is in
 * tests/Leads/B8LeadSearchTest.php.
 */
final class B8InboxTest extends AdminViewTestCase
{
    /* ---------------- Wa ---------------- */

    /** Each of these passed the contact form and used to dial nobody. */
    public function testEveryWayOfWritingAPakistaniMobileDialsTheSameNumber(): void
    {
        foreach ([
            '03001234567',
            '+92 300 1234567',
            '0092 300 1234567',
            '+92 (0) 300 1234567',
            '300 1234567',
            '92 300 1234567',
        ] as $written) {
            $this->assertSame('923001234567', Wa::digits($written), $written);
        }
    }

    public function testANumberOutsidePakistanIsLeftAsWritten(): void
    {
        $this->assertSame('447700900123', Wa::digits('+44 7700 900123'));
        $this->assertSame('447700900123', Wa::digits('0044 7700 900123'), 'the 00 prefix is dropped for every country');
        $this->assertSame('', Wa::digits('0012345'), 'too short to be a number, before or after the prefix');
        $this->assertSame('', Wa::href('call me'));
    }

    /* ---------------- the search key ---------------- */

    public function testALeadIsFoundByItsNumberTypedTheWayACallerSaysIt(): void
    {
        $card = LeadCard::fromArray(['id' => 1, 'name' => 'Ayesha', 'phone' => '+92 300 1234567']);

        $this->assertStringContains('03001234567', $card->searchKey(), 'the local 0-form');
        $this->assertStringContains('923001234567', $card->searchKey(), 'the wa.me form');
        $this->assertSame(['923001234567', '03001234567'], $card->phoneForms());
        $this->assertSame([], LeadCard::fromArray(['id' => 2, 'phone' => ''])->phoneForms());
    }

    public function testTheServerReadsAPhoneSearchInBothForms(): void
    {
        $this->assertSame(['03001234567', '923001234567'], LeadRepository::phoneForms('0300 1234567'));
        $this->assertSame(['923001234567', '03001234567'], LeadRepository::phoneForms('+92 (0) 300-1234567'));
        $this->assertSame([], LeadRepository::phoneForms('SP-2609'), 'letters make it not a number');
        $this->assertSame([], LeadRepository::phoneForms('300'), 'three digits is not a number');
        $this->assertSame(['ali', 'khan'], LeadRepository::searchWords('Ali-Khan'));
    }

    /* ---------------- the empty search ---------------- */

    public function testASearchThatFindsNothingKeepsTheBoxAndSaysWhatWasSearched(): void
    {
        $html = $this->view()->render('admin/inbox', [
            'page'  => $this->inboxPage(['leads' => [], 'filter' => 'Zubair']),
            'shell' => $this->shell('inbox'),
        ]);

        $this->assertStringContains('id="leadfind"', $html, 'the search box went with the results');
        $this->assertStringContains('value="Zubair"', $html, 'the search can be edited');
        $this->assertStringContains('No leads match &quot;Zubair&quot;.', $html);
        $this->assertStringContains('href="' . Routes::inbox('new') . '">Clear the search</a>', $html);
        $this->assertStringNotContains('Nothing here.', $html);
    }

    public function testAnEmptyTabWithNoSearchStillSaysNothingHereWithoutABox(): void
    {
        $html = $this->view()->render('admin/inbox', [
            'page'  => $this->inboxPage(['leads' => []]),
            'shell' => $this->shell('inbox'),
        ]);

        $this->assertStringNotContains('id="leadfind"', $html, 'an empty tab has nothing to look for');
        $this->assertStringContains('Nothing here.', $html);
        $this->assertStringNotContains('Clear the search', $html);
    }

    /**
     * admin.js cancels the submit of any form whose input carries
     * data-list-filter, so with scripting on that attribute turned the server
     * search back into a filter over the cards already on screen.
     */
    public function testTheSearchBoxSubmitsToTheServerWithScriptingOn(): void
    {
        $html = $this->view()->render('admin/inbox', [
            'page'  => $this->inboxPage(),
            'shell' => $this->shell('inbox'),
        ]);

        $this->assertStringContains('id="leadfind" method="get"', $html);
        $this->assertStringNotContains('data-list-filter', $html, 'admin.js would cancel the submit');
        $this->assertStringContains('>Find</button>', $html);
    }

    /* ---------------- past the first 200 ---------------- */

    public function testATabLargerThanOneScreenSaysSoAndLinksToTheOlderLeads(): void
    {
        $page = $this->paged(total: 340, offset: 0, filter: '');

        $this->assertSame('Showing 1-2 of 340, newest first', $page->rangeLine());
        $this->assertSame(Routes::inbox('contacted') . '&page=2', $page->olderHref());
        $this->assertSame('', $page->newerHref(), 'the first screen has nothing newer');

        $html = $this->view()->render('admin/inbox', ['page' => $page, 'shell' => $this->shell('inbox')]);
        $this->assertStringContains('Older leads &#8594;</a>', $html);
    }

    public function testTheSecondScreenLinksBothWaysAndKeepsTheSearch(): void
    {
        $page = $this->paged(total: 5, offset: 2, filter: 'ali khan');

        $this->assertSame('Showing 3-4 of 5', $page->rangeLine());
        $this->assertSame(Routes::inbox('contacted') . '&q=ali%20khan', $page->newerHref());
        $this->assertSame(Routes::inbox('contacted') . '&q=ali%20khan&page=3', $page->olderHref());
        $this->assertSame(2, $page->pageNo());
    }

    public function testATabThatFitsOnOneScreenPrintsNoRangeLine(): void
    {
        $this->assertSame('', $this->inboxPage()->rangeLine());
        $this->assertSame('', $this->inboxPage()->olderHref());
    }

    /* ---------------- saving comes back here ---------------- */

    public function testEachCardPostsTheTabTheSearchAndThePageItWasSavedFrom(): void
    {
        $html = $this->view()->render('admin/inbox', [
            'page'  => $this->paged(total: 5, offset: 2, filter: 'ali'),
            'shell' => $this->shell('inbox'),
        ]);

        $this->assertSame(2, substr_count($html, '<input type="hidden" name="tab" value="contacted">'));
        $this->assertSame(2, substr_count($html, '<input type="hidden" name="q" value="ali">'));
        $this->assertSame(2, substr_count($html, '<input type="hidden" name="page" value="2">'));
    }

    public function testTheSaveIsConfirmedAndARefusalIsShown(): void
    {
        $html = $this->view()->render('admin/inbox', [
            'page'  => new InboxPage(
                leads: [],
                toast: 'Saved - moved to Contacted',
                error: 'That enquiry is not in the inbox any more.',
            ),
            'shell' => $this->shell('inbox'),
        ]);

        $this->assertStringContains('data-toast="Saved - moved to Contacted"', $html);
        $this->assertStringContains('<div class="notice err" role="alert">That enquiry is not in the inbox any more.</div>', $html);
        $this->assertSame('Contacted', InboxPage::label('contacted'));
    }

    /** Two cards on the Contacted tab, as one screen of a longer list. */
    private function paged(int $total, int $offset, string $filter): InboxPage
    {
        $base = $this->inboxPage();

        return new InboxPage(
            leads: $base->leads,
            counts: $base->counts,
            status: 'contacted',
            filter: $filter,
            total: $total,
            offset: $offset,
            perPage: 2,
        );
    }
}
