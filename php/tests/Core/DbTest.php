<?php

declare(strict_types=1);

namespace Speedat\Tests\Core;

use Speedat\Core\Clock;
use Speedat\Core\Db;
use Speedat\Tests\TestCase;

/**
 * The database layer: the settings that make injection impossible rather than
 * unlikely, and the behaviour the rest of the application relies on.
 *
 * The claims in Db's own docblock are all testable, so they are tested:
 * server-side prepares (the value never reaches the SQL text), exceptions
 * rather than false, integers back as integers, UTC on the connection, strict
 * mode on, and an identifier that did not come from this codebase refused.
 */
final class DbTest extends TestCase
{
    /** Rows this test wrote, cleaned up whatever happens. */
    private string $marker = '';

    public function setUp(): void
    {
        if (!Db::healthy()) {
            $this->skip('the test database is not reachable - start MariaDB (bin/local/start-db.ps1) and run bin/migrate.php --database=speedat_php_test');
        }

        $this->marker = 'db-test-' . bin2hex(random_bytes(4));
    }

    public function tearDown(): void
    {
        if ($this->marker !== '' && Db::healthy()) {
            Db::exec('DELETE FROM audit_log WHERE actor = ?', [$this->marker]);
            Db::exec('DELETE FROM app_settings WHERE k LIKE ?', ['dbtest_%']);
        }
    }

    public function testTheConnectionIsConfiguredTheWayTheSecurityStoryAssumes(): void
    {
        $pdo = Db::pdo();

        $this->assertFalse(
            (bool) $pdo->getAttribute(\PDO::ATTR_EMULATE_PREPARES),
            'emulated prepares would put values into the SQL text'
        );
        $this->assertSame(
            \PDO::ERRMODE_EXCEPTION,
            $pdo->getAttribute(\PDO::ATTR_ERRMODE),
            'a failed query must stop the request, not return false'
        );
        $this->assertFalse((bool) $pdo->getAttribute(\PDO::ATTR_PERSISTENT), 'never persistent: 50 connections is the plan limit');

        // The session settings from MYSQL_ATTR_INIT_COMMAND.
        $this->assertSame('+00:00', (string) Db::value('SELECT @@session.time_zone'));

        $mode = (string) Db::value('SELECT @@session.sql_mode');
        $this->assertStringContains('STRICT_TRANS_TABLES', $mode, 'an over-long value must be an error, not a silent truncation');
        $this->assertStringContains('NO_ZERO_DATE', $mode);
    }

    public function testAValueThatLooksLikeSqlIsJustAValue(): void
    {
        $nasty = "x'; DROP TABLE app_settings; -- ";

        Db::insert('app_settings', [
            'k'          => 'dbtest_injection',
            'v'          => Db::json(['note' => $nasty]),
            'updated_at' => Clock::sql(),
            'updated_by' => $this->marker,
        ]);

        $row = Db::one('SELECT v FROM app_settings WHERE k = ?', ['dbtest_injection']);
        $this->assertNotNull($row);
        $this->assertSame($nasty, Db::unjson($row['v'])['note'] ?? null, 'stored verbatim');

        // The table it tried to drop is still there.
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM app_settings WHERE k = ?', ['dbtest_injection']));
    }

    public function testIntegersComeBackAsIntegers(): void
    {
        // Prices are integers. A "1" that arrives as a string compares wrongly
        // against an indexed integer column and formats wrongly on the page.
        $this->assertSame(1, Db::value('SELECT 1'));
        $this->assertSame(7, Db::value('SELECT ?', [7]));

        Db::insert('audit_log', [
            'at'     => Clock::sql(),
            'actor'  => $this->marker,
            'action' => 'db_test',
            'detail' => Db::json(['n' => 1]),
        ]);

        $row = Db::one('SELECT id FROM audit_log WHERE actor = ?', [$this->marker]);
        $this->assertNotNull($row);
        $this->assertTrue(is_int($row['id']), 'got ' . get_debug_type($row['id']));
    }

    public function testAFailingQueryThrows(): void
    {
        $this->assertThrows(
            \PDOException::class,
            static fn () => Db::run('SELECT * FROM a_table_that_does_not_exist'),
            'ERRMODE_EXCEPTION, not a silent false'
        );
    }

    public function testAnIdentifierThatDidNotComeFromThisCodebaseIsRefused(): void
    {
        foreach (['users; DROP TABLE x', 'a b', '`x`', '1table', '', 'x-y'] as $table) {
            $this->assertThrows(
                \InvalidArgumentException::class,
                static fn () => Db::insert($table, ['k' => 'v']),
                'table "' . $table . '" must be refused before any SQL is built'
            );
        }

        // Column names go through the same gate.
        $this->assertThrows(
            \InvalidArgumentException::class,
            static fn () => Db::insert('app_settings', ['k) VALUES (1) -- ' => 'v'])
        );
    }

    public function testOneAndValueAndAllAgreeAboutAnEmptyResult(): void
    {
        $this->assertNull(Db::one('SELECT id FROM audit_log WHERE actor = ?', ['nobody-' . $this->marker]));
        $this->assertSame([], Db::all('SELECT id FROM audit_log WHERE actor = ?', ['nobody-' . $this->marker]));
        $this->assertNull(Db::value('SELECT id FROM audit_log WHERE actor = ?', ['nobody-' . $this->marker]));
        $this->assertSame('x', Db::value('SELECT id FROM audit_log WHERE actor = ?', ['nobody-' . $this->marker], 'x'));
    }

    public function testATransactionRollsBackOnAnyThrowable(): void
    {
        $marker = $this->marker;

        $this->assertThrows(\RuntimeException::class, static function () use ($marker): void {
            Db::transaction(static function () use ($marker): void {
                Db::insert('audit_log', [
                    'at'     => Clock::sql(),
                    'actor'  => $marker,
                    'action' => 'rolled_back',
                    'detail' => Db::json([]),
                ]);

                throw new \RuntimeException('no');
            });
        });

        $this->assertSame(
            0,
            (int) Db::value('SELECT COUNT(*) FROM audit_log WHERE actor = ? AND action = ?', [$this->marker, 'rolled_back']),
            'the insert was undone'
        );
        $this->assertFalse(Db::pdo()->inTransaction(), 'and the connection is not left inside a transaction');
    }

    public function testANestedTransactionJoinsTheOuterOneRatherThanCommittingEarly(): void
    {
        $marker = $this->marker;

        $this->assertThrows(\RuntimeException::class, static function () use ($marker): void {
            Db::transaction(static function () use ($marker): void {
                Db::transaction(static function () use ($marker): void {
                    Db::insert('audit_log', [
                        'at'     => Clock::sql(),
                        'actor'  => $marker,
                        'action' => 'inner',
                        'detail' => Db::json([]),
                    ]);
                });

                // MySQL has no real nesting: if the inner call had committed,
                // this failure could not undo the row.
                throw new \RuntimeException('outer fails after the inner one returned');
            });
        });

        $this->assertSame(
            0,
            (int) Db::value('SELECT COUNT(*) FROM audit_log WHERE actor = ? AND action = ?', [$this->marker, 'inner']),
            'the inner insert went with the outer rollback'
        );
    }

    public function testTheStatementCacheDoesNotLeakValuesBetweenRuns(): void
    {
        // The same SQL is prepared once and re-bound. A cached statement that
        // kept its old parameters would answer the previous question.
        $sql = 'SELECT COUNT(*) FROM audit_log WHERE actor = ?';

        Db::insert('audit_log', [
            'at'     => Clock::sql(),
            'actor'  => $this->marker,
            'action' => 'cache_test',
            'detail' => Db::json([]),
        ]);

        $this->assertSame(1, (int) Db::value($sql, [$this->marker]));
        $this->assertSame(0, (int) Db::value($sql, ['someone-else-' . $this->marker]));
        $this->assertSame(1, (int) Db::value($sql, [$this->marker]));
    }

    public function testJsonRoundTripsAndACorruptValueDoesNotTakeTheSiteDown(): void
    {
        $this->assertSame('{"a":1}', Db::json(['a' => 1]));
        $this->assertSame('"سلام"', Db::json('سلام'), 'unicode is not escaped');
        $this->assertSame('"a/b"', Db::json('a/b'), 'slashes are not escaped');

        $this->assertSame(['a' => 1], Db::unjson('{"a":1}'));
        $this->assertSame(['a' => 1], Db::unjson(['a' => 1]), 'already decoded is fine');
        $this->assertSame([], Db::unjson('not json at all'));
        $this->assertSame(['fallback' => true], Db::unjson('', ['fallback' => true]));
        $this->assertSame(['fallback' => true], Db::unjson(null, ['fallback' => true]));

        // A value that cannot be JSON at all is a programming error and says so.
        $this->assertThrows(\RuntimeException::class, static fn () => Db::json(fopen('php://memory', 'rb')));
    }

    public function testResetClosesTheConnectionAndTheNextCallOpensAFreshOne(): void
    {
        $before = Db::pdo();
        Db::reset();
        $after = Db::pdo();

        $this->assertFalse($before === $after, 'a new PDO after reset');
        $this->assertTrue(Db::healthy());
    }
}
