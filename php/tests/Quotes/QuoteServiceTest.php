<?php

declare(strict_types=1);

namespace Speedat\Tests\Quotes;

use Speedat\Core\Db;
use Speedat\Domain\Leads\LeadRepository;
use Speedat\Domain\Quotes\QuoteInput;
use Speedat\Domain\Quotes\QuoteRejected;
use Speedat\Domain\Quotes\QuoteRepository;
use Speedat\Domain\Quotes\QuoteService;
use Speedat\Tests\TestCase;

/**
 * The quote log, and above all the thing it refuses to be told.
 *
 * The first test in this file is the one that matters: a request that sends a
 * 20 kg parcel and asserts it is billed as half a kilo must be stored at the
 * price of a 20 kg parcel. The Next.js route stored the assertion, because it
 * took the BILLED weight from the browser and only clamped the chargeable one.
 *
 * The bodies below still carry `type: "doc"`. The document rate left the product
 * on 2026-09-27 (owner), and keeping the retired key in the test is the point:
 * a tab opened before that date still posts it, and it must not change a number,
 * a status or a lead.
 */
final class QuoteServiceTest extends TestCase
{
    /** @var list<string> quote ids written by the running test, removed afterwards */
    private array $written = [];

    private QuoteService $service;

    private QuoteRepository $quotes;

    private LeadRepository $leads;

    public function setUp(): void
    {
        if (!Db::healthy()) {
            $this->skip('the test database is not reachable - start MariaDB (bin/local/start-db.ps1) and run bin/migrate.php --database=speedat_php_test');
        }

        $this->quotes  = new QuoteRepository();
        $this->leads   = new LeadRepository();
        $this->service = new QuoteService(Fixtures::card(), $this->quotes, $this->leads);
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

    /* ===================================================================== */
    /*  THE ATTACK                                                           */
    /* ===================================================================== */

    /**
     * A forged body claiming a 20 kg parcel weighs 0.4 kg is stored at the
     * correct price.
     *
     * The body says: the chargeable weight is 0.4 kg, the billed weight is
     * 0.5 kg, the total is PKR 2,200 - while sending the real pieces, one box of
     * 20 kg, which is what the customer will actually hand over. Every one of
     * those assertions except the pieces is discarded. It also says
     * `type: "doc"`, the retired key, which buys nothing at all.
     *
     * Expected, worked out by hand against tests/Quotes/Fixtures:
     *   chargeable  20 kg        (from the pieces; no dimensions, so no
     *                             volumetric weight)
     *   billed      20 kg        (already a whole multiple of the 0.5 kg step)
     *   steps       (20 - 0.5) / 0.5 = 39
     *   base        4,500 + 39 x 1,200 = 51,300
     *   tax         none, rounding to 10 leaves it alone
     *   TOTAL       PKR 51,300, not PKR 2,200
     */
    public function testAForgedWeightIsRepricedAsTheParcelItReallyIs(): void
    {
        $id = $this->id();

        $record = $this->service->log(QuoteInput::fromArray(Fixtures::body($id, [
            'type'      => 'doc',
            'chargeG'   => 400,
            'billableG' => 500,
            'total'     => 2200,
            'pieces'    => [['kg' => 20, 'qty' => 1]],
        ])));

        $this->assertSame(51300, $record->total, 'the engine priced it, not the browser');
        $this->assertSame(20000, $record->billableG, 'the billed weight came from the pieces');
        $this->assertSame(20000, $record->detail['chargeG'] ?? null);
        $this->assertFalse(array_key_exists('docRate', $record->detail), 'a docRate was stored');

        // And the row remembers what it was asked for, so the attempt is not
        // invisible to whoever reads the log afterwards.
        $this->assertTrue($record->wasTampered());
        // Cast: an integral float written to a JSON column comes back as an int,
        // because json_encode(2200.0) is "2200". The value is what matters here,
        // not which of PHP's two number types it landed in.
        $this->assertSame(500, $record->detail['clientBillableG'] ?? null);
        $this->assertSame(2200.0, (float) ($record->detail['clientTotal'] ?? null));

        // Read it back out of the database rather than trusting the object the
        // call returned: the point is what was STORED.
        $stored = $this->quotes->find($id);
        $this->assertNotNull($stored);
        $this->assertSame(51300, $stored?->total);
        $this->assertSame(20000, $stored?->billableG);
    }

    /**
     * The same attack without pieces: the billed weight is derived from the
     * chargeable one and the browser's claim is ignored.
     *
     * This is the shape the live browser actually posts today, so it is the one
     * the endpoint must be safe against even before any pieces are sent.
     */
    public function testTheBilledWeightIsDerivedFromTheChargeableOneNeverTaken(): void
    {
        $id = $this->id();

        $record = $this->service->log(QuoteInput::fromArray(Fixtures::body($id, [
            'type'      => 'doc',
            'chargeG'   => 20_000,
            'billableG' => 500,    // "but bill it as half a kilo"
            'total'     => 2200,
        ])));

        $this->assertSame(20000, $record->billableG);
        $this->assertSame(51300, $record->total);
    }

    /**
     * An add-on's amount comes from the published settings, never from the
     * request: naming a label is the only thing the browser may do.
     *
     * 1 kg express: steps (1 - 0.5) / 0.5 = 1, base 4,500 + 1,200 = 5,700,
     * plus the PKR 500 pickup charge = PKR 6,200. The body claims 999,999 and
     * an add-on the document does not have.
     */
    public function testAddonAmountsComeFromTheSettingsAndUnknownLabelsAreDropped(): void
    {
        $id = $this->id();

        $record = $this->service->log(QuoteInput::fromArray(Fixtures::body($id, [
            'total'  => 999_999,
            'addons' => ['Pickup and service charges', 'Free upgrade to Express'],
        ])));

        $this->assertSame(6200, $record->total);

        $addons = $record->detail['addons'] ?? [];
        $this->assertSame(1, is_array($addons) ? count($addons) : -1, 'the invented add-on was dropped');
        $this->assertSame('Pickup and service charges', $addons[0]['label'] ?? null);
        // Cast, as above: json_encode(500.0) is "500", so an integral amount
        // comes back out of the JSON column as an int. The amount is what
        // matters, not which of PHP's number types survived the round trip.
        $this->assertSame(500.0, (float) ($addons[0]['amount'] ?? null));
        $this->assertSame(999999.0, (float) ($record->detail['clientTotal'] ?? null));
    }

    /* ===================================================================== */
    /*  THE HONEST PATH                                                      */
    /* ===================================================================== */

    /**
     * A letter is priced like any other parcel, and calling it a document
     * changes nothing.
     *
     * This test used to assert the opposite: 0.3 kg with `type: "doc"` got the
     * flat PKR 2,200. The owner removed the document rate on 2026-09-27, so the
     * same body is now the first slab - 0.3 kg chargeable, billed as the 0.5 kg
     * minimum, base PKR 4,500 with no steps - and the browser's PKR 2,200 claim
     * is recorded as a disagreement instead of honoured as a price.
     */
    public function testALetterIsPricedLikeAnyOtherParcel(): void
    {
        $id = $this->id();

        $record = $this->service->log(QuoteInput::fromArray(Fixtures::body($id, [
            'type'      => 'doc',
            'chargeG'   => 300,
            'billableG' => 500,
            'total'     => 2200,
        ])));

        $this->assertSame(500, $record->billableG);
        $this->assertSame(4500, $record->total, 'a letter took a flat rate that no longer exists');
        $this->assertFalse(array_key_exists('docRate', $record->detail));
        $this->assertTrue($record->wasTampered(), 'the browser asked for the retired flat rate');
        $this->assertSame(2200.0, (float) ($record->detail['clientTotal'] ?? null));
    }

    /** Volumetric weight is recomputed from the dimensions, not accepted. */
    public function testVolumetricWeightIsRecomputedFromTheDimensions(): void
    {
        $id = $this->id();

        // 60 x 40 x 30 cm / 5000 = 14.4 kg volumetric against 2 kg actual, so
        // 14.4 kg is chargeable and 14.5 kg is billed (next 0.5 kg step).
        // steps (14.5 - 0.5) / 0.5 = 28, base 4,500 + 28 x 1,200 = 38,100.
        $record = $this->service->log(QuoteInput::fromArray(Fixtures::body($id, [
            'chargeG'   => 2000,
            'billableG' => 2000,
            'pieces'    => [['kg' => 2, 'qty' => 1, 'L' => 60, 'W' => 40, 'H' => 30]],
        ])));

        $this->assertSame(14400, $record->detail['chargeG'] ?? null);
        $this->assertSame(14500, $record->billableG);
        $this->assertSame(38100, $record->total);
        $this->assertSame(true, $record->detail['volumetricWins'] ?? null);
    }

    /* ===================================================================== */
    /*  UPSERT AND THE LEAD                                                  */
    /* ===================================================================== */

    /**
     * The same quote logged twice is one row, and the second call is the one
     * that counts - that is how "shown" becomes "booked".
     */
    public function testLoggingTheSameQuoteTwiceUpdatesTheOneRow(): void
    {
        $id = $this->id();

        $this->service->log(QuoteInput::fromArray(Fixtures::body($id)));
        $this->assertSame(1, $this->rowsFor($id));
        $this->assertFalse($this->quotes->find($id)?->booked ?? true);

        $this->service->log(QuoteInput::fromArray(Fixtures::body($id, ['booked' => true])));

        $this->assertSame(1, $this->rowsFor($id), 'still one row');
        $this->assertTrue($this->quotes->find($id)?->booked ?? false);
    }

    /** Tapping Book makes exactly one lead, however many times it is tapped. */
    public function testABookedQuoteMakesOneLeadAndTappingAgainMakesNoMore(): void
    {
        $id = $this->id();

        $this->service->log(QuoteInput::fromArray(Fixtures::body($id, [
            'booked'   => true,
            'contents' => 'Wedding clothes',
            'addons'   => ['Pickup and service charges'],
        ])));

        $lead = $this->leads->findByQuote($id);
        $this->assertNotNull($lead);
        $this->assertSame('quote', $lead?->kind);
        $this->assertSame('ca', $lead?->destId);
        $this->assertSame(1000, $lead?->weightG);

        // The summary is the line the office reads, and its price is the Total
        // the customer's own WhatsApp message carries.
        $this->assertStringContains('Express to Canada', $lead?->summary() ?? '');
        $this->assertStringContains('PKR 6,200', $lead?->summary() ?? '');
        $this->assertStringContains('includes PKR 500 pickup and service charges', $lead?->summary() ?? '');
        $this->assertStringContains('Contents: Wedding clothes', $lead?->message ?? '');

        $this->service->log(QuoteInput::fromArray(Fixtures::body($id, ['booked' => true])));

        $this->assertSame(
            1,
            (int) Db::value('SELECT COUNT(*) FROM leads WHERE quote_id = ?', [$id], 0),
            'a second tap is the same lead',
        );
    }

    /* ===================================================================== */
    /*  REFUSALS                                                             */
    /* ===================================================================== */

    public function testAHiddenDestinationIsRefusedExactlyLikeAnUnknownOne(): void
    {
        $hidden  = $this->assertThrows(
            QuoteRejected::class,
            fn () => $this->service->log(QuoteInput::fromArray(Fixtures::body($this->id(), ['destId' => 'zz']))),
        );
        $unknown = $this->assertThrows(
            QuoteRejected::class,
            fn () => $this->service->log(QuoteInput::fromArray(Fixtures::body($this->id(), ['destId' => 'xx']))),
        );

        $this->assertSame(QuoteRejected::BAD_REQUEST, $hidden instanceof QuoteRejected ? $hidden->errorCode : '');
        $this->assertSame($unknown->getMessage(), $hidden->getMessage(), 'the two are indistinguishable');
    }

    /**
     * EVERY BAD FIELD GETS THE SAME ANSWER, so probing teaches nothing.
     *
     * It used to teach quite a lot. A malformed reference answered 400 "That
     * is not a quote reference.", an unknown or hidden destination answered
     * 404 "Unknown destination or service", and everything else answered 400
     * "Invalid quote" - three answers, so a caller could sort its guesses into
     * which field it had got wrong. The endpoint's own docblock said this was
     * impossible.
     *
     * Six shapes of wrong, one code, one sentence, one status.
     */
    public function testEveryBadFieldIsRefusedWithTheSameCodeSentenceAndStatus(): void
    {
        $bodies = [
            'a malformed reference'     => ['id' => 'not-a-reference'],
            'a half-shaped reference'   => ['id' => 'SP-9901-AB'],
            'an unknown destination'    => ['destId' => 'atlantis'],
            'a hidden destination'      => ['destId' => 'zz'],
            'a destination left blank'  => ['destId' => ''],
            'an unknown service'        => ['serviceId' => 'teleport'],
            'a service left blank'      => ['serviceId' => ''],
            'a weight that is not one'  => ['chargeG' => 'heavy'],
        ];

        foreach ($bodies as $what => $patch) {
            $e = $this->assertThrows(
                QuoteRejected::class,
                fn () => $this->service->log(QuoteInput::fromArray(Fixtures::body($this->id(), $patch))),
                $what . ' was accepted',
            );

            $this->assertSame(QuoteRejected::BAD_REQUEST, $e instanceof QuoteRejected ? $e->errorCode : '', $what);
            $this->assertSame(QuoteRejected::INVALID, $e->getMessage(), $what . ' answered with its own sentence');
            $this->assertSame(400, $e instanceof QuoteRejected ? $e->httpStatus() : 0, $what);
        }
    }

    /**
     * The one the audit caught: an unknown destination and a malformed
     * reference are now byte-for-byte the same refusal.
     *
     * Asserted against each other rather than against a literal, because the
     * property being kept is "these two are indistinguishable", not "these two
     * both say some particular sentence".
     */
    public function testAMalformedReferenceIsRefusedExactlyLikeAnUnknownDestination(): void
    {
        $badId = $this->assertThrows(
            QuoteRejected::class,
            fn () => $this->service->log(QuoteInput::fromArray(Fixtures::body($this->id(), ['id' => 'SP-1-ABCD']))),
        );
        $badDest = $this->assertThrows(
            QuoteRejected::class,
            fn () => $this->service->log(QuoteInput::fromArray(Fixtures::body($this->id(), ['destId' => 'atlantis']))),
        );

        $this->assertSame($badDest->getMessage(), $badId->getMessage(), 'the reference names itself in the error');
        $this->assertSame(
            $badDest instanceof QuoteRejected ? $badDest->errorCode : 'a',
            $badId instanceof QuoteRejected ? $badId->errorCode : 'b',
        );
        $this->assertSame(
            $badDest instanceof QuoteRejected ? $badDest->httpStatus() : 0,
            $badId instanceof QuoteRejected ? $badId->httpStatus() : 1,
        );
    }

    /**
     * And the class itself no longer has a way to say anything else about a
     * bad field: `badRequest()` takes no message. An argument is how the
     * distinctions crept in the first time, so the guard is the signature
     * rather than a convention.
     */
    public function testTheRefusalForABadFieldCannotBeGivenItsOwnWords(): void
    {
        $method = new \ReflectionMethod(QuoteRejected::class, 'badRequest');

        $this->assertSame(0, $method->getNumberOfParameters(), 'badRequest() can be handed a custom sentence again');
        $this->assertSame(QuoteRejected::INVALID, QuoteRejected::badRequest()->getMessage());
        $this->assertFalse(
            defined(QuoteRejected::class . '::NOT_FOUND'),
            'the not_found code is back, and with it the 404 that told a prober which field failed',
        );
    }

    public function testAWeightAboveTheCargoThresholdIsRefused(): void
    {
        // 80 kg, against a 70 kg threshold. The site offers no price at all up
        // there, so a quote arriving for one is stale or forged.
        $e = $this->assertThrows(
            QuoteRejected::class,
            fn () => $this->service->log(QuoteInput::fromArray(Fixtures::body($this->id(), ['chargeG' => 80_000]))),
        );

        $this->assertSame(QuoteRejected::OVER_MAX, $e instanceof QuoteRejected ? $e->errorCode : '');
    }

    public function testABodyWithNoWeightAtAllIsRefusedRatherThanGuessed(): void
    {
        $body = Fixtures::body($this->id());
        unset($body['chargeG']);

        // Only the browser's billed weight is left, and that is not something
        // the server is allowed to price from.
        $this->assertThrows(QuoteRejected::class, static fn () => QuoteInput::fromArray($body));
    }

    public function testAMalformedReferenceIsRefused(): void
    {
        foreach (['', 'SP-1-ABCD', 'sp-990101-abcd', 'SP-990101-ABCDE', str_repeat('A', 400)] as $bad) {
            $this->assertThrows(
                QuoteRejected::class,
                static fn () => QuoteInput::fromArray(Fixtures::body('SP-990101-AAAA', ['id' => $bad])),
                'accepted "' . substr($bad, 0, 20) . '"',
            );
        }
    }

    public function testAnOutOfRangeWeightIsRefusedNotClamped(): void
    {
        foreach ([0, -1, 2_000_000, 'twenty'] as $bad) {
            $this->assertThrows(
                QuoteRejected::class,
                static fn () => QuoteInput::fromArray(Fixtures::body('SP-990101-AAAA', ['chargeG' => $bad])),
                'accepted a chargeable weight of ' . var_export($bad, true),
            );
        }
    }

    /* ------------------------------------------------------------ helpers */

    private function id(): string
    {
        $id              = Fixtures::quoteId();
        $this->written[] = $id;

        return $id;
    }

    private function rowsFor(string $id): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM quotes WHERE id = ?', [$id], 0);
    }
}
