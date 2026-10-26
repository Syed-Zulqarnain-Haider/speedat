<?php

declare(strict_types=1);

namespace Speedat\Tests\Core;

use Speedat\Core\Clock;
use Speedat\Core\Config;
use Speedat\Core\Db;
use Speedat\Tests\TestCase;

/**
 * Db::transaction() re-runs the WHOLE callback when the server threw the work
 * away - a deadlock (1213) or MariaDB 11.6+'s "record has changed since last
 * read" (1020) - and nothing else.
 *
 * WHY THESE TESTS FORCE innodb_snapshot_isolation ON (2026-10-26). The live
 * host is MariaDB 11.8, where it defaults ON; local dev is 11.4, where it
 * defaults OFF, and that difference is exactly how 1020 went unseen. The
 * variable exists (session scope) on both, so it is switched on for this
 * connection and the real error is produced on either server - the suite then
 * proves the 11.8 behaviour wherever it runs, instead of passing vacuously on
 * the one the developers use. A server without the variable (MySQL) skips
 * those tests rather than failing them.
 *
 * A real deadlock needs two transactions BLOCKED on each other, which a
 * single-threaded test cannot arrange without a child process; the 1213 cases
 * throw the PDOException the driver would throw (same class, same errorInfo).
 * The 1020 cases are the real thing, from the server, via a second connection.
 */
final class DbRetryTest extends TestCase
{
    private string $marker = '';

    private ?\PDO $other = null;

    public function setUp(): void
    {
        if (!Db::healthy()) {
            $this->skip('the test database is not reachable - start MariaDB (bin/local/start-db.ps1) and run bin/migrate.php --database=speedat_php_test');
        }

        $this->marker = 'dbretry-' . bin2hex(random_bytes(4));
        Db::exec(
            'INSERT INTO app_settings (k, v, updated_at, updated_by) VALUES (?, ?, ?, ?)',
            [$this->key(), Db::json(['n' => 0]), Clock::sql(), $this->marker]
        );
    }

    public function tearDown(): void
    {
        if ($this->marker !== '' && Db::healthy()) {
            Db::exec('DELETE FROM app_settings WHERE k = ?', [$this->key()]);
            Db::exec('DELETE FROM audit_log WHERE actor = ?', [$this->marker]);
            // Back to the server's own default for the rest of the suite.
            Db::exec('SET SESSION innodb_snapshot_isolation = DEFAULT');
        }

        $this->other = null;
    }

    public function testAChangedRecordIsRetriedAndTheSecondAttemptSeesTheNewValue(): void
    {
        $this->snapshotIsolation();

        $attempts = 0;
        $n        = Db::transaction(function () use (&$attempts): int {
            $attempts++;
            $this->audit('attempt-' . $attempts);

            // A plain read: this is what opens the snapshot.
            $seen = (int) (Db::unjson(Db::value('SELECT v FROM app_settings WHERE k = ?', [$this->key()]))['n'] ?? -1);

            if ($attempts === 1) {
                // Somebody else commits a change to the same row in between.
                $this->otherConnection()->prepare('UPDATE app_settings SET v = ? WHERE k = ?')
                    ->execute([Db::json(['n' => 5]), $this->key()]);
            }

            Db::exec('UPDATE app_settings SET v = ? WHERE k = ?', [Db::json(['n' => $seen + 1]), $this->key()]);

            return $seen + 1;
        });

        $this->assertSame(2, $attempts, 'the first attempt was refused with 1020 and the callback ran again');
        $this->assertSame(6, $n, 'the retry read the committed 5, not the stale 0');
        $this->assertSame(6, $this->stored(), 'and that is what was written - not a lost update back to 1');
        $this->assertSame(
            ['attempt-2'],
            $this->audited(),
            'the first attempt\'s own write went with the server\'s rollback; only the second one committed'
        );
        $this->assertFalse(Db::pdo()->inTransaction(), 'the connection is not left inside a transaction');
    }

    public function testADeadlockVictimIsRetried(): void
    {
        $attempts = 0;
        $result   = Db::transaction(function () use (&$attempts): string {
            $attempts++;
            $this->audit('attempt-' . $attempts);

            if ($attempts === 1) {
                throw self::serverError(1213, 'Deadlock found when trying to get lock; try restarting transaction');
            }

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame(2, $attempts);
        $this->assertSame(['attempt-2'], $this->audited(), 'the losing attempt left nothing behind');
    }

    public function testAWrappedDeadlockIsStillRecognised(): void
    {
        $attempts = 0;
        Db::transaction(static function () use (&$attempts): void {
            $attempts++;
            if ($attempts === 1) {
                throw new \RuntimeException('could not save', 0, self::serverError(1213, 'Deadlock found'));
            }
        });

        $this->assertSame(2, $attempts, 'a domain exception wrapping the deadlock is the same lost transaction');
    }

    public function testItGivesUpAfterThreeAttemptsAndSaysWhy(): void
    {
        $attempts = 0;
        $e        = $this->assertThrows(\PDOException::class, function () use (&$attempts): void {
            Db::transaction(function () use (&$attempts): void {
                $attempts++;
                $this->audit('attempt-' . $attempts);

                throw self::serverError(1213, 'Deadlock found');
            });
        });

        $this->assertSame(Db::ATTEMPTS, $attempts, 'bounded: three tries in all, not a loop');
        $this->assertSame(1213, (int) ($e instanceof \PDOException ? $e->errorInfo[1] : 0), 'the caller gets the real error');
        $this->assertSame([], $this->audited(), 'and nothing from any attempt was committed');
    }

    public function testWhatTheCallbackDecidesIsNotRetried(): void
    {
        // DraftConflict, PublishConflict, a validation error: the callback
        // looked at the data and said no. Asking again gets the same answer.
        $attempts = 0;
        $this->assertThrows(\DomainException::class, static function () use (&$attempts): void {
            Db::transaction(static function () use (&$attempts): void {
                $attempts++;

                throw new \DomainException('conflict');
            });
        });
        $this->assertSame(1, $attempts);

        // Nor any other database error: a duplicate key is not transient.
        $attempts = 0;
        $this->assertThrows(\PDOException::class, function () use (&$attempts): void {
            Db::transaction(function () use (&$attempts): void {
                $attempts++;
                Db::exec(
                    'INSERT INTO app_settings (k, v, updated_at, updated_by) VALUES (?, ?, ?, ?)',
                    [$this->key(), '{}', Clock::sql(), $this->marker]
                );
            });
        });
        $this->assertSame(1, $attempts, '1062 is the caller\'s problem, not a retry');
    }

    public function testALockWaitTimeoutIsNotRetried(): void
    {
        // 1205 already waited innodb_lock_wait_timeout; see Db::RETRYABLE.
        $attempts = 0;
        $this->assertThrows(\PDOException::class, static function () use (&$attempts): void {
            Db::transaction(static function () use (&$attempts): void {
                $attempts++;

                throw self::serverError(1205, 'Lock wait timeout exceeded; try restarting transaction');
            });
        });

        $this->assertSame(1, $attempts);
    }

    public function testASwallowedLossIsRetriedRatherThanFinishedInAutocommit(): void
    {
        // Audit::log() catches every throwable by design. If what it caught
        // was the 1020 that killed the transaction, the rest of the callback
        // would run in AUTOCOMMIT and commit half a unit of work.
        $this->snapshotIsolation();

        $attempts = 0;
        Db::transaction(function () use (&$attempts): void {
            $attempts++;
            Db::value('SELECT v FROM app_settings WHERE k = ?', [$this->key()]);

            if ($attempts === 1) {
                $this->otherConnection()->prepare('UPDATE app_settings SET v = ? WHERE k = ?')
                    ->execute([Db::json(['n' => 5]), $this->key()]);

                try {
                    Db::exec('UPDATE app_settings SET v = ? WHERE k = ?', [Db::json(['n' => 1]), $this->key()]);
                } catch (\PDOException) {
                    // swallowed, the way Audit::log() would
                }

                try {
                    $this->audit('after-the-loss');
                } catch (\RuntimeException) {
                    // refused - and swallowed again
                }

                return;
            }

            $this->audit('attempt-' . $attempts);
        });

        $this->assertSame(2, $attempts, 'the swallowed loss still counted as a lost attempt');
        $this->assertSame(['attempt-2'], $this->audited(), 'nothing after the loss was autocommitted');
        $this->assertSame(5, $this->stored(), 'the other connection\'s write stands; the stale one never landed');
    }

    public function testANestedCallIsRetriedOnlyByTheOutermost(): void
    {
        $outer = 0;
        $inner = 0;

        Db::transaction(static function () use (&$outer, &$inner): void {
            $outer++;
            Db::transaction(static function () use (&$outer, &$inner): void {
                $inner++;
                if ($outer === 1) {
                    throw self::serverError(1213, 'Deadlock found');
                }
            });
        });

        $this->assertSame(2, $outer, 'the server rolled back the OUTER work too, so the outer callback ran again');
        $this->assertSame(2, $inner, 'and the inner one ran once per outer attempt, never retrying on its own');
    }

    public function testALockingFirstReadTurnsTheConflictIntoAWait(): void
    {
        // The other half of the fix: a transaction whose FIRST read is
        // FOR UPDATE opens no snapshot, so a row somebody committed before it
        // is simply read as it now is. This is the shape Repo::publishVersion()
        // now has (SELECT MAX(version) ... FOR UPDATE).
        $this->snapshotIsolation();

        $this->otherConnection()->prepare('UPDATE app_settings SET v = ? WHERE k = ?')
            ->execute([Db::json(['n' => 5]), $this->key()]);

        $attempts = 0;
        Db::transaction(function () use (&$attempts): void {
            $attempts++;
            $seen = (int) (Db::unjson(Db::value('SELECT v FROM app_settings WHERE k = ? FOR UPDATE', [$this->key()]))['n'] ?? -1);
            Db::exec('UPDATE app_settings SET v = ? WHERE k = ?', [Db::json(['n' => $seen + 1]), $this->key()]);
        });

        $this->assertSame(1, $attempts, 'no 1020, so nothing to retry');
        $this->assertSame(6, $this->stored());
    }

    /* ------------------------------------------------------------ helpers */

    private function key(): string
    {
        return 'dbretry_' . substr($this->marker, -8);
    }

    private function snapshotIsolation(): void
    {
        try {
            Db::exec('SET SESSION innodb_snapshot_isolation = ON');
        } catch (\PDOException) {
            $this->skip('this server has no innodb_snapshot_isolation (MariaDB 10.6.18+/11.4+ only)');
        }
    }

    /** A second, independent connection: "somebody else". Autocommit. */
    private function otherConnection(): \PDO
    {
        if ($this->other instanceof \PDO) {
            return $this->other;
        }

        $host = Config::need('db.host');
        $dsn  = str_starts_with($host, '/')
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $host, Config::need('db.name'))
            : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, Config::int('db.port', 3306), Config::need('db.name'));

        return $this->other = new \PDO($dsn, Config::need('db.user'), Config::str('db.pass'), [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }

    private function audit(string $action): void
    {
        Db::insert('audit_log', ['at' => Clock::sql(), 'actor' => $this->marker, 'action' => $action, 'detail' => Db::json([])]);
    }

    /** @return list<string> */
    private function audited(): array
    {
        return array_map(
            static fn (array $r): string => (string) $r['action'],
            Db::all('SELECT action FROM audit_log WHERE actor = ? ORDER BY id', [$this->marker])
        );
    }

    private function stored(): int
    {
        return (int) (Db::unjson(Db::value('SELECT v FROM app_settings WHERE k = ?', [$this->key()]))['n'] ?? -1);
    }

    /** The exception pdo_mysql throws for server error `$code`. */
    private static function serverError(int $code, string $message): \PDOException
    {
        $e            = new \PDOException('SQLSTATE[40001]: ' . $message);
        $e->errorInfo = ['40001', $code, $message];

        return $e;
    }
}
