<?php

declare(strict_types=1);

namespace Speedat\Tests\Leads;

use Speedat\Core\Db;
use Speedat\Domain\Leads\LeadKind;
use Speedat\Domain\Leads\LeadRepository;
use Speedat\Domain\Leads\LeadStatus;
use Speedat\Tests\TestCase;

/**
 * The inbox: both ways a lead is created, how it is worked, and the counts the
 * admin badges show.
 *
 * The test that earns its place is the idempotency one. The browser posts a
 * booked quote with `keepalive` and never waits for the answer, so a customer
 * who taps Book twice - or whose phone retries on a flaky connection - posts it
 * twice. Two leads for one booking means two people ring the same customer.
 */
final class LeadRepositoryTest extends TestCase
{
    private LeadRepository $leads;

    /** Unique per run so a leftover row from an interrupted run cannot fail the next one. */
    private string $mark = '';

    /** @var list<string> */
    private array $quoteIds = [];

    /** @var list<int> */
    private array $leadIds = [];

    public function setUp(): void
    {
        if (!Db::healthy()) {
            $this->skip('the test database is not reachable - start MariaDB (bin/local/start-db.ps1) and run bin/migrate.php --database=speedat_php_test');
        }

        $this->leads = new LeadRepository();
        $this->mark  = 'T' . bin2hex(random_bytes(4));
    }

    public function tearDown(): void
    {
        if (!Db::healthy()) {
            return;
        }

        foreach ($this->quoteIds as $quoteId) {
            Db::exec('DELETE FROM leads WHERE quote_id = ?', [$quoteId]);
        }
        foreach ($this->leadIds as $id) {
            Db::exec('DELETE FROM leads WHERE id = ?', [$id]);
        }

        $this->quoteIds = [];
        $this->leadIds  = [];
    }

    /* ===================================================================== */
    /*  upsertQuoteLead                                                      */
    /* ===================================================================== */

    public function testABookedQuoteBecomesOneLeadHoweverManyTimesItIsPosted(): void
    {
        $quoteId = $this->quoteId();

        $first = $this->leads->upsertQuoteLead($quoteId, 'ca', 5000, 'Express to Canada - 5 kg - PKR 19,620');
        $again = $this->leads->upsertQuoteLead($quoteId, 'ca', 5000, 'Express to Canada - 5 kg - PKR 19,620');
        $third = $this->leads->upsertQuoteLead($quoteId, 'ca', 5000, 'Express to Canada - 5 kg - PKR 19,620');

        $this->assertTrue($first, 'the first post created the lead');
        $this->assertFalse($again, 'the second did not');
        $this->assertFalse($third);

        $this->assertSame(1, $this->countFor($quoteId));
    }

    /**
     * A repeat does not rewrite the summary.
     *
     * The office may already have read the first line and replied to it;
     * quietly changing it under them is worse than leaving one that is a
     * few rupees out of date. The quote row itself does carry the newer
     * numbers - see QuoteRepository.
     */
    public function testARepeatLeavesTheSummaryAloneRatherThanRewritingIt(): void
    {
        $quoteId = $this->quoteId();

        $this->leads->upsertQuoteLead($quoteId, 'ca', 5000, 'Express to Canada - 5 kg - PKR 19,620');
        $this->leads->upsertQuoteLead($quoteId, 'gb', 9000, 'Normal to United Kingdom - 9 kg - PKR 1');

        $lead = $this->leads->findByQuote($quoteId);

        $this->assertSame('Express to Canada - 5 kg - PKR 19,620', $lead?->summary());
        $this->assertSame('ca', $lead?->destId);
        $this->assertSame(5000, $lead?->weightG);
    }

    public function testTheContentsLineFollowsTheSummaryAndIsOmittedWhenBlank(): void
    {
        $withContents = $this->quoteId();
        $this->leads->upsertQuoteLead($withContents, 'ca', 1000, 'Express to Canada - 1 kg - PKR 5,700', '  Wedding clothes  ');

        $this->assertSame(
            "Express to Canada - 1 kg - PKR 5,700\nContents: Wedding clothes",
            $this->leads->findByQuote($withContents)?->message,
        );

        $without = $this->quoteId();
        $this->leads->upsertQuoteLead($without, 'ca', 1000, 'Express to Canada - 1 kg - PKR 5,700', '   ');

        $this->assertSame(
            'Express to Canada - 1 kg - PKR 5,700',
            $this->leads->findByQuote($without)?->message,
            'whitespace-only contents leaves no trailing blank line',
        );
    }

    public function testAQuoteLeadStartsNewAndUntouched(): void
    {
        $quoteId = $this->quoteId();
        $this->leads->upsertQuoteLead($quoteId, 'ca', 1000, 'Express to Canada');

        $lead = $this->leads->findByQuote($quoteId);

        $this->assertSame(LeadKind::QUOTE, $lead?->kind);
        $this->assertSame(LeadStatus::NEW, $lead?->status);
        $this->assertSame('', $lead?->lastTouchedBy());
        $this->assertTrue($lead?->isUntouched() ?? false);
    }

    /* ===================================================================== */
    /*  createMessageLead                                                    */
    /* ===================================================================== */

    public function testAContactMessageBecomesItsOwnLeadEveryTime(): void
    {
        $one = $this->message('Ayesha Khan', "I want to send books to my son.\nAbout 4 kg.");
        $two = $this->message('Ayesha Khan', "I want to send books to my son.\nAbout 4 kg.");

        $this->assertNotSame($one->id, $two->id, 'two messages are two things to answer');
        $this->assertSame(LeadKind::MESSAGE, $one->kind);
        $this->assertNull($one->quoteId);
        $this->assertSame('I want to send books to my son.', $one->summary(), 'the first line is the summary');
        $this->assertSame(4000, $one->weightG);
        $this->assertSame('ca', $one->destId);
    }

    public function testFieldsAreSquishedAndCappedToTheirColumns(): void
    {
        // Straight at the repository, not through message(): that helper appends
        // the run's mark to the name, which is exactly the thing being asserted
        // on here. The row is still registered for tear-down.
        $lead            = $this->leads->createMessageLead(
            "  Ayesha    Khan\t",
            str_repeat('9', 80),          // phone, column is 40
            '',
            'Hello ' . $this->mark,
            'ca',
            4000,
            null,
        );
        $this->leadIds[] = $lead->id;

        $this->assertSame('Ayesha Khan', $lead->name);
        $this->assertSame(40, mb_strlen($lead->phone), 'the phone was cut to its column width');
    }

    public function testAnImpossibleWeightBecomesNoWeightRatherThanAColumnError(): void
    {
        $this->assertNull($this->messageWithWeight(0)->weightG);
        $this->assertNull($this->messageWithWeight(-5)->weightG);
        $this->assertNull($this->messageWithWeight(999_999_999)->weightG);
        $this->assertSame(1, $this->messageWithWeight(1)->weightG);
    }

    public function testAMalformedIpHashIsDroppedNotStored(): void
    {
        $bad = $this->leads->createMessageLead('A', '1', '', 'hi', null, null, 'not-a-hash');
        $this->leadIds[] = $bad->id;
        $this->assertNull($bad->ipHash);

        $good            = $this->leads->createMessageLead('A', '1', '', 'hi', null, null, str_repeat('ab', 16));
        $this->leadIds[] = $good->id;
        $this->assertSame(str_repeat('ab', 16), $good->ipHash);
    }

    /* ===================================================================== */
    /*  working a lead                                                       */
    /* ===================================================================== */

    public function testUpdatingRecordsTheStatusTheNotesAndWhoTouchedIt(): void
    {
        $lead = $this->message('Bilal', 'Two boxes to Manchester');

        $worked = $this->leads->update($lead->id, LeadStatus::CONTACTED, 'Called, will confirm Monday', 'owner@speedat.test');

        $this->assertSame(LeadStatus::CONTACTED, $worked?->status);
        $this->assertSame('Called, will confirm Monday', $worked?->notes);
        $this->assertSame('owner@speedat.test', $worked?->lastTouchedBy());
        $this->assertFalse($worked?->isUntouched() ?? true);

        // A partial update leaves the other field alone.
        $again = $this->leads->update($lead->id, LeadStatus::BOOKED, null, 'owner@speedat.test');
        $this->assertSame(LeadStatus::BOOKED, $again?->status);
        $this->assertSame('Called, will confirm Monday', $again?->notes);
    }

    public function testAnUnknownStatusIsRefused(): void
    {
        $lead = $this->message('Bilal', 'hello');

        $this->assertThrows(
            \InvalidArgumentException::class,
            fn () => $this->leads->update($lead->id, 'archived', null, 'owner@speedat.test'),
        );
    }

    public function testUpdatingALeadThatIsNotThereIsNullNotAnError(): void
    {
        $this->assertNull($this->leads->update(0, LeadStatus::LOST, null, 'owner@speedat.test'));
        $this->assertNull($this->leads->update(2_000_000_000, LeadStatus::LOST, null, 'owner@speedat.test'));
        $this->assertNull($this->leads->find(0));
    }

    public function testTheBadgeCountsMoveWithTheStatuses(): void
    {
        $before = $this->leads->counts();

        $a = $this->message('One', 'hello');
        $b = $this->message('Two', 'hello');
        $this->leads->update($b->id, LeadStatus::LOST, null, 'owner@speedat.test');

        $after = $this->leads->counts();

        $this->assertSame($before->new + 1, $after->new);
        $this->assertSame($before->lost + 1, $after->lost);
        $this->assertSame($before->total() + 2, $after->total());
        $this->assertSame($before->open() + 1, $after->open(), 'a lost lead is not open');

        // A status the database has never seen still reads as zero.
        $this->assertTrue($after->toArray()[LeadStatus::CONTACTED] >= 0);
        unset($a);
    }

    public function testTheListIsNewestFirstAndCanBeNarrowedByStatus(): void
    {
        $a = $this->message('First', 'hello');
        $b = $this->message('Second', 'hello');
        $this->leads->update($b->id, LeadStatus::LOST, null, 'owner@speedat.test');

        $ids = array_map(static fn ($l): int => $l->id, $this->leads->list(LeadStatus::ANY, 500));
        $this->assertTrue(array_search($b->id, $ids, true) <= array_search($a->id, $ids, true), 'newest first');

        $lostIds = array_map(static fn ($l): int => $l->id, $this->leads->list(LeadStatus::LOST, 500));
        $this->assertTrue(in_array($b->id, $lostIds, true));
        $this->assertFalse(in_array($a->id, $lostIds, true));
    }

    /* ------------------------------------------------------------ helpers */

    private function quoteId(): string
    {
        $id               = 'SP-990101-' . strtoupper(bin2hex(random_bytes(2)));
        $this->quoteIds[] = $id;

        return $id;
    }

    private function message(string $name, string $body, string $phone = '03001234567'): \Speedat\Domain\Leads\Lead
    {
        $lead            = $this->leads->createMessageLead($name . ' ' . $this->mark, $phone, '', $body, 'ca', 4000, null);
        $this->leadIds[] = $lead->id;

        return $lead;
    }

    private function messageWithWeight(int $weightG): \Speedat\Domain\Leads\Lead
    {
        $lead            = $this->leads->createMessageLead('W ' . $this->mark, '1', '', 'hi', 'ca', $weightG, null);
        $this->leadIds[] = $lead->id;

        return $lead;
    }

    private function countFor(string $quoteId): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM leads WHERE quote_id = ?', [$quoteId], 0);
    }
}
