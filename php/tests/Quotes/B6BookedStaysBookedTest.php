<?php

declare(strict_types=1);

namespace Speedat\Tests\Quotes;

use Speedat\Core\Db;
use Speedat\Domain\Leads\LeadRepository;
use Speedat\Domain\Quotes\QuoteInput;
use Speedat\Domain\Quotes\QuoteRepository;
use Speedat\Domain\Quotes\QuoteService;
use Speedat\Tests\TestCase;

/**
 * A booking is never undone by the "shown" log that left before it.
 *
 * Since audit B6 (2026-10-25) the calculator logs a quote twice: once when the
 * price has stayed on screen for a moment (booked = false), and again when Book
 * is tapped (booked = true). Both are keepalive requests the page never waits
 * for, so nothing orders them on the wire - on a slow phone the shown-log can
 * land second. Before this, the second arrival rewrote `booked` and a real
 * booking read as a view in the analytics.
 */
final class B6BookedStaysBookedTest extends TestCase
{
    /** @var list<string> quote ids written by the running test, removed afterwards */
    private array $written = [];

    private QuoteService $service;

    private QuoteRepository $quotes;

    public function setUp(): void
    {
        if (!Db::healthy()) {
            $this->skip('the test database is not reachable - start MariaDB (bin/local/start-db.ps1) and run bin/migrate.php --database=speedat_php_test');
        }

        $this->quotes  = new QuoteRepository();
        $this->service = new QuoteService(Fixtures::card(), $this->quotes, new LeadRepository());
    }

    public function tearDown(): void
    {
        if (!Db::healthy()) {
            return;
        }

        foreach ($this->written as $id) {
            Db::exec('DELETE FROM leads WHERE quote_id = ?', [$id]);
            Db::exec('DELETE FROM quotes WHERE id = ?', [$id]);
        }

        $this->written = [];
    }

    /** Booked first, shown second: still booked, still one row. */
    public function testALateShownLogDoesNotUnbookAQuote(): void
    {
        $id = $this->id();

        $this->service->log(QuoteInput::fromArray(Fixtures::body($id, ['booked' => true])));
        $this->assertTrue($this->quotes->find($id)?->booked ?? false);

        $this->service->log(QuoteInput::fromArray(Fixtures::body($id, ['booked' => false])));

        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM quotes WHERE id = ?', [$id], 0), 'still one row');
        $this->assertTrue($this->quotes->find($id)?->booked ?? false, 'the late shown-log left the booking in place');
    }

    /** The ordinary order still works: shown, then booked, reads as booked. */
    public function testShownThenBookedReadsAsBooked(): void
    {
        $id = $this->id();

        $this->service->log(QuoteInput::fromArray(Fixtures::body($id)));
        $this->assertFalse($this->quotes->find($id)?->booked ?? true, 'a shown-log alone is not a booking');

        $this->service->log(QuoteInput::fromArray(Fixtures::body($id, ['booked' => true])));
        $this->assertTrue($this->quotes->find($id)?->booked ?? false);
    }

    private function id(): string
    {
        $id              = Fixtures::quoteId();
        $this->written[] = $id;

        return $id;
    }
}
