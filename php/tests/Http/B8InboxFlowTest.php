<?php

declare(strict_types=1);

namespace Speedat\Tests\Http;

use Speedat\Core\Db;
use Speedat\View\Admin\Routes;

/**
 * The inbox through the real routes (fixed 2026-10-25).
 *
 * Two complaints, both about the office losing sight of a lead it had:
 *
 *   1. THE SEARCH ONLY LOOKED AT THE NEWEST 200. A customer from three months
 *      ago was "Nothing here." on a tab whose badge said 340, and a number typed
 *      as the caller reads it out (03xx) did not find the same number stored as
 *      +92 3xx.
 *   2. A SAVE WENT BACK TO THE NEW TAB WITH NO WORD. Notes saved on Contacted,
 *      or under a search, vanished with the card and read as notes lost.
 */
final class B8InboxFlowTest extends AdminTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->admin();
        $this->signIn();
    }

    /* ------------------------------------------------- 1. the whole tab */

    public function testASearchReachesALeadOlderThanTheFirstScreenByItsLocalNumber(): void
    {
        $old = $this->lead('Old Customer B8', '+92 300 7654321', 'new', '2026-01-02 10:00:00');
        for ($i = 0; $i < 205; $i++) {
            $this->lead('Recent ' . $i, '0321' . str_pad((string) $i, 7, '0', STR_PAD_LEFT), 'new', '2026-10-01 10:00:00');
        }

        $first = $this->adminGet(Routes::INBOX, ['status' => 'new'])->body();
        $this->assertStringNotContains('Old Customer B8', $first, 'the oldest lead is not on the first screen');
        $this->assertStringContains('Showing 1-200 of 206, newest first', $first);
        $this->assertStringContains('Older leads &#8594;</a>', $first);

        $found = $this->adminGet(Routes::INBOX, ['status' => 'new', 'q' => '03007654321'])->body();
        $this->assertStringContains('Old Customer B8', $found, 'the 0-form found the +92 number past the first 200');
        $this->assertStringContains('id="lead-' . $old . '"', $found);

        $paged = $this->adminGet(Routes::INBOX, ['status' => 'new', 'page' => '2'])->body();
        $this->assertStringContains('Old Customer B8', $paged, 'the second screen reaches it without a search');
    }

    public function testASearchFindsALeadByTheNameOfItsCountry(): void
    {
        $this->lead('Country Search B8', '03001112223', 'new', '2026-10-01 10:00:00', 'ca');

        $body = $this->adminGet(Routes::INBOX, ['status' => 'new', 'q' => 'canada'])->body();

        $this->assertStringContains('Country Search B8', $body);
    }

    public function testASearchThatFindsNothingKeepsTheBox(): void
    {
        $this->lead('Somebody B8', '03001112223', 'new', '2026-10-01 10:00:00');

        $body = $this->adminGet(Routes::INBOX, ['status' => 'new', 'q' => 'zzzz-nobody'])->body();

        $this->assertStringContains('id="leadfind"', $body);
        $this->assertStringContains('No leads match &quot;zzzz-nobody&quot;.', $body);
    }

    /* ------------------------------------------------- 2. back to where it was */

    public function testSavingNotesComesBackToTheSameTabSearchAndCard(): void
    {
        $id = $this->lead('Ali B8', '03001112223', 'contacted', '2026-10-01 10:00:00');

        $answer = $this->adminPost(Routes::INBOX_UPDATE, [
            'id' => (string) $id, 'status' => 'contacted', 'notes' => 'Pickup Friday',
            'tab' => 'contacted', 'q' => 'Ali', 'page' => '1',
        ]);

        $this->assertRedirects(Routes::INBOX . '?updated=1&status=contacted&q=Ali#lead-' . $id, $answer);

        $back = $this->adminGet(Routes::INBOX, ['updated' => '1', 'status' => 'contacted', 'q' => 'Ali'])->body();
        $this->assertStringContains('data-toast="Saved"', $back);
        $this->assertStringContains('value="Pickup Friday"', $back);
    }

    public function testACardThatLeavesTheTabSaysWhereItWent(): void
    {
        $id = $this->lead('Moving B8', '03001112223', 'new', '2026-10-01 10:00:00');

        $answer = $this->adminPost(Routes::INBOX_UPDATE, [
            'id' => (string) $id, 'status' => 'contacted', 'notes' => '', 'tab' => 'new', 'q' => '', 'page' => '1',
        ]);

        $location = $this->location($answer);
        $this->assertStringContains('status=new', $location);
        $this->assertStringContains('moved=contacted', $location);
        $this->assertStringNotContains('#lead-', $location, 'the card is not on the tab it goes back to');

        $back = $this->adminGet(Routes::INBOX, ['updated' => '1', 'status' => 'new', 'moved' => 'contacted'])->body();
        $this->assertStringContains('data-toast="Saved - moved to Contacted"', $back);

        $made = $this->adminGet(Routes::INBOX, ['updated' => '1', 'moved' => '<b>x</b>'])->body();
        $this->assertStringContains('data-toast="Saved"', $made, 'an unknown status adds nothing to the sentence');
    }

    public function testAMissingLeadIsSaidOutLoud(): void
    {
        $answer = $this->adminPost(Routes::INBOX_UPDATE, ['id' => '99999999', 'status' => 'new', 'tab' => 'booked']);

        $this->assertRedirects(Routes::INBOX . '?err=nolead&status=booked', $answer);

        $body = $this->adminGet(Routes::INBOX, ['err' => 'nolead', 'status' => 'booked'])->body();
        $this->assertStringContains('<div class="notice err" role="alert">That enquiry is not in the inbox any more.</div>', $body);
    }

    /* ------------------------------------------------------------ fixtures */

    private function lead(string $name, string $phone, string $status, string $at, ?string $destId = null): int
    {
        return Db::insert('leads', [
            'created_at' => $at,
            'updated_at' => $at,
            'kind'       => 'message',
            'quote_id'   => null,
            'name'       => $name,
            'phone'      => $phone,
            'email'      => '',
            'message'    => 'B8 inbox test',
            'dest_id'    => $destId,
            'weight_g'   => null,
            'status'     => $status,
            'notes'      => '',
            'updated_by' => '',
            'ip_hash'    => null,
        ]);
    }
}
