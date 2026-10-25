<?php

declare(strict_types=1);

namespace Speedat\Tests\Pricing;

use Speedat\Core\Db;
use Speedat\Core\Paths;
use Speedat\Domain\Pricing\RateCard;
use Speedat\Domain\Quotes\QuoteInput;
use Speedat\Domain\Quotes\QuoteRejected;
use Speedat\Domain\Quotes\QuoteRepository;
use Speedat\Domain\Quotes\QuoteService;
use Speedat\Tests\TestCase;

/**
 * Finding 13 of docs/audit-round1.md: engine output that the column cannot hold.
 *
 * `quotes.billable_g` is INT UNSIGNED and app/Core/Db.php connects with
 * STRICT_TRANS_TABLES, so a wider number is not truncated - MariaDB answers
 * "SQLSTATE[22003]: 1264 Out of range value for column 'billable_g' at row 1".
 * A quote nobody could store therefore surfaced as a 503 with a driver message
 * in the log, which is the right answer to an unexpected error and the wrong
 * answer to a shipment that should have been refused in a sentence.
 *
 * Two layers, and both are tested here: Domain\Quotes\QuoteService refuses at the
 * business cap of one tonne, and QuoteRepository refuses at the column's own
 * ceiling for any caller that did not come through QuoteService.
 *
 * MOST OF THIS NEEDS NO DATABASE, on purpose: every refusal happens before the
 * INSERT, which is the whole point. The tests assert the error CODE rather than
 * just the exception class, because an unreachable database produces
 * QuoteRejected too ("unavailable") and would otherwise let a missing guard pass
 * for a working one.
 */
final class QuoteLimitsTest extends TestCase
{
    /** MySQL's own ranges for the integer types the schema declares. */
    private const TYPE_MAX = [
        'INT UNSIGNED' => 4_294_967_295,
        'INT'          => 2_147_483_647,
    ];

    private const ID = 'SP-260920-K7QX';

    /** @var list<string> quote ids this test actually wrote, removed afterwards */
    private array $written = [];

    public function tearDown(): void
    {
        if (!Db::healthy()) {
            return;
        }

        foreach ($this->written as $id) {
            Db::exec('DELETE FROM quotes WHERE id = ?', [$id]);
        }

        $this->written = [];
    }

    /* ===================================================================== */
    /*  THE COLUMN                                                           */
    /* ===================================================================== */

    /**
     * The constants are the schema's numbers, read out of the migration.
     *
     * This is the Caps.php trick, for the columns Caps does not cover: it says
     * itself that "Only VARCHAR columns are listed", so these three integer
     * widths had no mirror anywhere and nothing could refuse a value politely.
     * Widen a column and this test tells you to widen the constant; widen the
     * constant and it tells you the column disagrees.
     */
    public function testTheConstantsStillMatchTheColumns(): void
    {
        $sql = file_get_contents(Paths::root() . '/db/migrations/0001_init.sql');
        $this->assertNotSame(false, $sql, 'db/migrations/0001_init.sql must be readable');

        $table = self::tableBody((string) $sql, 'quotes');

        foreach (
            [
                'billable_g' => QuoteRepository::MAX_BILLABLE_G,
                'total'      => QuoteRepository::MAX_TOTAL,
                'version'    => QuoteRepository::MAX_VERSION,
            ] as $column => $constant
        ) {
            $declared = self::declaredType($table, $column);

            $this->assertNotNull($declared, 'quotes.' . $column . ' is not declared in 0001_init.sql any more');
            $this->assertSame(
                self::TYPE_MAX[(string) $declared] ?? -1,
                $constant,
                'quotes.' . $column . ' is ' . $declared . ', which does not match the constant guarding it',
            );
        }
    }

    /** The business cap must sit inside the column, not outside it. */
    public function testTheBusinessCapFitsTheColumn(): void
    {
        $this->assertTrue(
            QuoteInput::MAX_GRAMS <= QuoteRepository::MAX_BILLABLE_G,
            'a weight the input accepts must be a weight the column can hold',
        );
        $this->assertTrue(
            QuoteInput::MAX_TOTAL <= QuoteRepository::MAX_TOTAL,
            'a total the input accepts must be a total the column can hold',
        );
        $this->assertTrue(
            QuoteInput::MAX_VERSION <= QuoteRepository::MAX_VERSION,
            'a version the input accepts must be a version the column can hold',
        );
    }

    /* ===================================================================== */
    /*  THE REFUSALS                                                         */
    /* ===================================================================== */

    /**
     * A billed weight past the column is answered with the cargo sentence, which
     * is the only true thing to say about 4,294 tonnes, and never reaches the
     * INSERT that would have made it a 503.
     */
    public function testAWeightTheColumnCannotHoldIsRefusedAsCargo(): void
    {
        foreach ([5_000_000_000, QuoteRepository::MAX_BILLABLE_G + 1, -1] as $grams) {
            $rejected = $this->assertThrows(
                QuoteRejected::class,
                fn () => (new QuoteRepository())->upsert(self::ID, 1, 'ca', 'express', $grams, 100, [], false),
                'billable_g = ' . $grams,
            );

            $this->assertTrue($rejected instanceof QuoteRejected);
            /** @var QuoteRejected $rejected */
            $this->assertSame(QuoteRejected::OVER_MAX, $rejected->errorCode, 'billable_g = ' . $grams);
            $this->assertSame(409, $rejected->httpStatus());
            $this->assertSame('That weight needs a cargo rate', $rejected->getMessage(), 'no SQL, no column names');
        }
    }

    /**
     * A total or a version past its column is not the customer's fault and is not
     * their problem to fix, so it is "could not save the quote" (503) rather than
     * a 400 that blames them - but it is still a domain refusal, not a driver
     * error.
     */
    public function testATotalOrVersionTheColumnCannotHoldIsRefusedWithoutBlamingTheCustomer(): void
    {
        $cases = [
            'total'          => [1_000, QuoteRepository::MAX_TOTAL + 1, 1],
            'negative total' => [1_000, QuoteRepository::MIN_TOTAL - 1, 1],
            'version'        => [1_000, 100, QuoteRepository::MAX_VERSION + 1],
        ];

        foreach ($cases as $what => [$grams, $total, $version]) {
            $rejected = $this->assertThrows(
                QuoteRejected::class,
                fn () => (new QuoteRepository())->upsert(self::ID, $version, 'ca', 'express', $grams, $total, [], false),
                $what,
            );

            /** @var QuoteRejected $rejected */
            $this->assertSame(QuoteRejected::UNAVAILABLE, $rejected->errorCode, $what);
            $this->assertSame(503, $rejected->httpStatus(), $what);
            $this->assertSame('Could not save the quote', $rejected->getMessage(), $what);
        }
    }

    /**
     * The layer in front: a legitimate but huge shipment - two tonnes in one
     * piece, which the input caps allow - is refused at the business cap with the
     * cargo sentence, long before anything asks the column.
     *
     * Two tonnes is 2,000,000 g against QuoteInput::MAX_GRAMS of 1,000,000.
     */
    public function testATonneIsCargoBeforeTheColumnIsEverAsked(): void
    {
        $service = new QuoteService(self::card());

        $rejected = $this->assertThrows(
            QuoteRejected::class,
            static fn () => $service->log(QuoteInput::fromArray([
                'id'        => self::ID,
                'destId'    => 'ca',
                'serviceId' => 'express',
                'type'      => 'pkg',
                'pieces'    => [['kg' => 2000, 'qty' => 1]],
            ])),
        );

        /** @var QuoteRejected $rejected */
        $this->assertSame(QuoteRejected::OVER_MAX, $rejected->errorCode);
        $this->assertSame(409, $rejected->httpStatus());
    }

    /**
     * And the row that fits is still written. Without this, a guard that refused
     * everything would pass every test above.
     */
    public function testAWeightTheColumnCanHoldIsStillStored(): void
    {
        if (!Db::healthy()) {
            $this->skip('the test database is not reachable - start MariaDB (bin/local/start-db.ps1) and run bin/migrate.php --database=speedat_php_test');
        }

        $quotes = new QuoteRepository();

        // Far above the one-tonne business cap and far below the column's
        // ceiling: this is the band the repository guard alone is responsible
        // for, and it must be a write, not a refusal.
        $quotes->upsert(self::ID, 1, 'ca', 'express', 4_000_000_000, 2_000_000_000, ['note' => 'limits test'], false);
        $this->written[] = self::ID;

        $stored = $quotes->find(self::ID);

        $this->assertNotNull($stored);
        $this->assertSame(4_000_000_000, $stored?->billableG);
        $this->assertSame(2_000_000_000, $stored?->total);
    }

    /* ===================================================================== */
    /*  HELPERS                                                              */
    /* ===================================================================== */

    private static function card(): RateCard
    {
        return RateCard::fromArray([
            'services'     => [['id' => 'express', 'name' => 'Express']],
            'destinations' => [[
                'id'     => 'ca',
                'name'   => 'Canada',
                'active' => true,
                'rates'  => ['express' => ['first' => 1000, 'addl' => 500, 'days' => '3-5']],
            ]],
            'settings'     => ['pricingMode' => 'slab', 'maxKg' => 0],
        ]);
    }

    /** The lines of one CREATE TABLE, so a column name cannot match another table's. */
    private static function tableBody(string $sql, string $table): string
    {
        $start = strpos($sql, 'CREATE TABLE IF NOT EXISTS ' . $table);
        if ($start === false) {
            return '';
        }

        $end = strpos($sql, 'ENGINE=InnoDB', $start);

        return substr($sql, $start, $end === false ? null : $end - $start);
    }

    /** "INT UNSIGNED" or "INT" as the migration declares it, or null. */
    private static function declaredType(string $table, string $column): ?string
    {
        if (preg_match('/^\s*' . preg_quote($column, '/') . '\s+(INT(?:\s+UNSIGNED)?)\s/mi', $table, $m) !== 1) {
            return null;
        }

        return strtoupper(preg_replace('/\s+/', ' ', $m[1]) ?? '');
    }
}
