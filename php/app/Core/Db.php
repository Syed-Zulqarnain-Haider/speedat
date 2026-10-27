<?php

declare(strict_types=1);

namespace Speedat\Core;

/**
 * The database. One lazy PDO connection per process, opened the first time
 * somebody actually needs it - a page that serves from cache must not pay for
 * a handshake.
 *
 * Settings that are not optional:
 *   ERRMODE_EXCEPTION   a query that fails must stop the request, not return false
 *   EMULATE_PREPARES=false  real server-side prepares: the values never reach
 *                       the SQL text, so injection is impossible by construction
 *   STRINGIFY_FETCHES=false integers come back as PHP ints (prices are integers)
 *   time_zone='+00:00'  every stored timestamp is UTC (see Clock)
 *   STRICT_TRANS_TABLES an over-long or wrongly typed value is an error, never
 *                       a silent truncation
 *
 * EVERY query in this application goes through here with bound parameters.
 * There is no string-built SQL anywhere, and no method on this class accepts
 * a caller-supplied identifier without validating it against [A-Za-z0-9_].
 */
final class Db
{
    private static ?\PDO $pdo = null;

    /** @var array<string,\PDOStatement> prepared-statement cache, keyed by SQL */
    private static array $statements = [];

    /**
     * @var array<string,list<string>> the parameter names each cached statement
     *                                 was first executed with, keyed by the same SQL
     */
    private static array $statementParams = [];

    /**
     * How many times a transaction (or a lone autocommitted statement) is
     * tried in all before a transient refusal is let through to the caller.
     * Three, deliberately small: these errors clear in milliseconds when they
     * are what they claim to be, and a request that has lost three times in a
     * row is looking at something that is not transient.
     */
    public const ATTEMPTS = 3;

    /**
     * Server errors that mean "the SERVER rolled your work back - run it
     * again", and nothing else:
     *
     *   1213 ER_LOCK_DEADLOCK  InnoDB picked this transaction as the victim of
     *                          a deadlock and rolled the whole of it back.
     *   1020 ER_CHECKREAD      "Record has changed since last read". MariaDB
     *                          11.6+ turns innodb_snapshot_isolation ON by
     *                          default, and the live host is on 11.8
     *                          (2026-10-26): a transaction that read with a
     *                          plain SELECT and then locks or writes a row
     *                          somebody else committed since is refused with
     *                          this, and - measured on 11.8.9, not assumed -
     *                          the whole transaction is rolled back, exactly
     *                          like a deadlock. 11.4 (local dev until now)
     *                          defaults it OFF, which is why nothing here had
     *                          ever seen it.
     *
     * NOT 1205 (lock wait timeout). It is not transient in the same sense:
     * the statement already waited innodb_lock_wait_timeout (50 s by default)
     * for a lock somebody is still holding, and trying twice more is up to two
     * more of those waits on a web request that has long since timed out. It
     * also rolls back only the statement, not the transaction, so "run it
     * again" is not even what the server is asking for.
     */
    private const RETRYABLE = [1213, 1020];

    /**
     * True while Db::transaction() has a transaction open. Tracked here rather
     * than asked of PDO because after a 1213/1020 the server has ALREADY
     * rolled back and PDO::inTransaction() (which reads the server's status
     * flag) says false - so it cannot tell "inside a transaction the server
     * killed" from "autocommit".
     */
    private static bool $open = false;

    /**
     * The 1213/1020 that killed the open transaction, once one has.
     *
     * Every statement after it would run in AUTOCOMMIT - the transaction is
     * gone - and commit for good, half a unit of work with the first half
     * thrown away. That is reachable: Audit::log() catches every throwable by
     * design and is called inside transactions (Repo::catchUpDraft), so a
     * deadlock on its insert would be swallowed and the callback would carry
     * on. So once this is set, run() refuses every further statement until the
     * transaction is over, and transaction() retries or rethrows instead of
     * committing.
     */
    private static ?\PDOException $lost = null;

    public static function pdo(): \PDO
    {
        if (self::$pdo instanceof \PDO) {
            return self::$pdo;
        }

        $host    = Config::need('db.host');
        $name    = Config::need('db.name');
        $user    = Config::need('db.user');
        $pass    = Config::str('db.pass');
        $port    = Config::int('db.port', 3306);
        $charset = Config::str('db.charset', 'utf8mb4');

        // Hostinger's MySQL is reached over a unix socket or localhost TCP; the
        // DSN below covers both because `host` may be a socket path.
        $dsn = str_starts_with($host, '/')
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $host, $name, $charset)
            : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);

        $init = "SET SESSION time_zone = '+00:00', "
            . "sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'";

        // PHP 8.4 moved the MySQL attributes onto Pdo\Mysql and 8.5 deprecates
        // the old PDO::MYSQL_ATTR_* names - and the error handler turns that
        // deprecation into an exception, so on 8.5 the old name alone stopped
        // every connection (found 2026-10-27 moving to 8.5.12). Same value
        // either way; only the branch that runs is ever resolved, so 8.2 and
        // 8.3 never see the new name and 8.5 never sees the old one.
        $initCommand = defined('Pdo\Mysql::ATTR_INIT_COMMAND')
            ? \Pdo\Mysql::ATTR_INIT_COMMAND
            : \PDO::MYSQL_ATTR_INIT_COMMAND;

        try {
            self::$pdo = new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE                  => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE       => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES         => false,
                \PDO::ATTR_STRINGIFY_FETCHES        => false,
                $initCommand                        => $init,
                // Shared hosting drops idle connections; a short timeout turns a
                // dead host into a fast error instead of a hung page.
                \PDO::ATTR_TIMEOUT                  => 5,
            ]);
        } catch (\PDOException $e) {
            // The DSN contains the host and database name but never the
            // password; PDO's own message can still include the user, so it is
            // logged and not shown.
            Log::exception('db_connect_failed', $e, ['host' => $host, 'db' => $name]);

            throw new \RuntimeException('The database is unavailable.', 0, $e);
        }

        return self::$pdo;
    }

    /** True when a query actually round-trips. Used by the health check and the test runner. */
    public static function healthy(): bool
    {
        try {
            return self::value('SELECT 1') === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Close the connection and drop the statement cache. Tests and long CLI jobs. */
    public static function reset(): void
    {
        self::$statements      = [];
        self::$statementParams = [];
        self::$pdo             = null;
        self::$open            = false;
        self::$lost            = null;
    }

    /**
     * Run a prepared statement.
     *
     * BINDINGS PERSIST ON A CACHED STATEMENT, which is the footgun this method
     * has to close (audit round 1, finding 23). The same PDOStatement is reused
     * for the same SQL, and a value bound on one call is still bound on the next -
     * so running one query with a SUBSET of its parameters silently reuses the
     * PREVIOUS call's values for the ones left out. PDO does not catch it: as far
     * as the statement is concerned every placeholder has a value. No caller does
     * this today (the SQL always encodes its own placeholder list, so a different
     * set of parameters means a different cache key), and it would be invisible
     * the day one did - a query returning somebody else's row, with no error
     * anywhere.
     *
     * So the parameter names of the first execution are remembered and every
     * later one must match. An InvalidArgumentException rather than a PDOException
     * because this is a mistake in the calling code, not a database condition.
     *
     * @param  array<string|int,mixed> $params
     * @throws \InvalidArgumentException when a cached statement is re-run with a
     *                                   different set of parameters
     */
    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::$statements[$sql] ??= self::pdo()->prepare($sql);

        $names = [];
        foreach (array_keys($params) as $key) {
            $names[] = is_int($key) ? (string) ($key + 1) : ltrim((string) $key, ':');
        }
        sort($names);

        $first = self::$statementParams[$sql] ?? null;
        if ($first === null) {
            self::$statementParams[$sql] = $names;
        } elseif ($first !== $names) {
            throw new \InvalidArgumentException(
                'This SQL was prepared with the parameters [' . implode(', ', $first)
                . '] and is being re-run with [' . implode(', ', $names)
                . ']. A cached statement keeps its old bindings, so the missing ones would '
                . 'silently reuse the previous call\'s values.'
            );
        }

        // Bind explicitly so an int stays an int: MySQL compares '1' and 1
        // differently against an indexed integer column.
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : $key;
            $stmt->bindValue($name, $value, match (true) {
                is_int($value)  => \PDO::PARAM_INT,
                is_bool($value) => \PDO::PARAM_BOOL,
                $value === null => \PDO::PARAM_NULL,
                default         => \PDO::PARAM_STR,
            });
        }

        if (self::$lost !== null) {
            throw new \RuntimeException(
                'The server rolled this transaction back (error ' . self::errorCode(self::$lost)
                . '); refusing to run more of it in autocommit.',
                0,
                self::$lost
            );
        }

        // Asked BEFORE the statement runs: afterwards a deadlock has already
        // rolled the transaction back and PDO would report autocommit.
        $inTransaction = self::$open || self::pdo()->inTransaction();

        for ($attempt = 1; ; $attempt++) {
            try {
                $stmt->execute();

                return $stmt;
            } catch (\PDOException $e) {
                if (!self::retryable($e)) {
                    throw $e;
                }

                if ($inTransaction) {
                    // Not this statement's to retry: everything before it in
                    // the transaction is gone too. Db::transaction() re-runs
                    // the whole callback.
                    if (self::$open) {
                        self::$lost = $e;
                    }

                    throw $e;
                }

                // A LONE AUTOCOMMITTED STATEMENT that lost a deadlock committed
                // nothing, so running it again is the same statement, not a
                // second one. The page-view counter and the rate limiter are
                // INSERT ... ON DUPLICATE KEY UPDATE on hot rows - the classic
                // shape for a deadlock under a burst - and a visitor's page must
                // not fail because two of them arrived together.
                if ($attempt >= self::ATTEMPTS) {
                    throw $e;
                }

                self::backoff($attempt);
            }
        }
    }

    /**
     * @param  array<string|int,mixed>    $params
     * @return list<array<string,mixed>>
     */
    public static function all(string $sql, array $params = []): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = self::run($sql, $params)->fetchAll();

        return $rows;
    }

    /**
     * @param  array<string|int,mixed>  $params
     * @return array<string,mixed>|null
     */
    public static function one(string $sql, array $params = []): ?array
    {
        $stmt = self::run($sql, $params);
        /** @var array<string,mixed>|false $row */
        $row = $stmt->fetch();
        $stmt->closeCursor();

        return $row === false ? null : $row;
    }

    /** @param array<string|int,mixed> $params */
    public static function value(string $sql, array $params = [], mixed $default = null): mixed
    {
        $stmt = self::run($sql, $params);
        $v    = $stmt->fetchColumn();
        $stmt->closeCursor();

        return $v === false ? $default : $v;
    }

    /**
     * Rows affected.
     *
     * @param array<string|int,mixed> $params
     */
    public static function exec(string $sql, array $params = []): int
    {
        return self::run($sql, $params)->rowCount();
    }

    /**
     * INSERT from a column => value map. Column names are validated against a
     * strict identifier pattern: they come from this codebase, never from a
     * request, and this makes that guarantee structural rather than a habit.
     *
     * @param  array<string,mixed> $data
     * @return int                 lastInsertId, or 0 for a table without AUTO_INCREMENT
     */
    public static function insert(string $table, array $data): int
    {
        $cols         = array_keys($data);
        $quotedTable  = self::identifier($table);
        $quotedCols   = implode(', ', array_map(self::identifier(...), $cols));
        $placeholders = implode(', ', array_map(static fn (string $c): string => ':' . $c, $cols));

        self::run("INSERT INTO {$quotedTable} ({$quotedCols}) VALUES ({$placeholders})", self::named($data));

        $id = self::pdo()->lastInsertId();

        return is_numeric($id) ? (int) $id : 0;
    }

    /**
     * Run a closure inside a transaction, rolling back on any throwable.
     *
     * RE-RUN, WHOLE, WHEN THE SERVER THREW THE WORK AWAY (2026-10-26). A
     * deadlock (1213) or MariaDB 11.8's "record has changed since last read"
     * (1020, see RETRYABLE) means the server has already rolled the entire
     * transaction back: nothing was committed and nothing is half-written. The
     * callback is then run again from its first statement - up to ATTEMPTS
     * times in all, with a short jittered pause - so it re-reads everything
     * and decides again on what is there NOW. That is also what keeps the
     * domain answers right: a publish that lost to a concurrent save comes
     * round again, reads the newer draft under its row lock, and refuses with
     * DraftConflict - the answer the editor knows how to show - instead of a
     * bare "SQLSTATE[HY000]: 1020".
     *
     * What makes that safe is a rule every callback has to keep: EVERYTHING
     * IT DOES IS IN THE DATABASE, so the rollback undoes all of it. Checked for
     * every caller on 2026-10-26 (Session::rotate, ShipmentRepository::create
     * and ::addEvent, DailyOnce::claim, Repo::saveDraft, ::catchUpDraft and
     * ::publishVersion): mail, cache busting, the price hold and the audit
     * lines about a publish all happen AFTER the commit. The one thing a
     * callback writes outside the database is a Log line (catchUpDraft's
     * "draft_repointed" / "draft_rebase_refused"), which a retry can write
     * twice; a duplicated diagnostic line is the accepted cost. Anything with
     * a real outside effect - mail, a file, an HTTP call - goes after this
     * method returns, never inside the closure.
     *
     * NOT retried: anything the closure throws itself (DraftConflict,
     * PublishConflict, a validation error), any other database error, and a
     * failure of COMMIT itself - whether a commit that errored took effect is
     * not something the client can know, and guessing "no" could write twice.
     *
     * Nested calls join the outer transaction and never retry on their own:
     * the server rolled back the OUTER work too, so only the outermost call
     * can run it again, and it does.
     *
     * @template T
     * @param  \Closure():T $work
     * @return T
     */
    public static function transaction(\Closure $work): mixed
    {
        $pdo = self::pdo();

        // Nested calls join the outer transaction rather than failing: MySQL
        // has no real nesting and a silent second BEGIN would commit early.
        // `$open` as well as PDO's view: after a deadlock inside the outer
        // call PDO already reports "not in a transaction", and a nested call
        // must not then start a fresh one of its own.
        if (self::$open || $pdo->inTransaction()) {
            return $work();
        }

        for ($attempt = 1; ; $attempt++) {
            $pdo->beginTransaction();
            self::$open = true;
            self::$lost = null;

            try {
                $result = $work();

                // The callback swallowed the error that killed the
                // transaction (Audit::log() does that by design). Its work is
                // gone server-side, so this is a lost attempt, not a success.
                if (self::$lost !== null) {
                    throw self::$lost;
                }
            } catch (\Throwable $e) {
                $killedBy = self::$lost;
                self::$open = false;
                self::$lost = null;

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if ($attempt < self::ATTEMPTS && ($killedBy !== null || self::retryable($e))) {
                    Log::warn('db_transaction_retried', [
                        'attempt' => $attempt,
                        'code'    => self::errorCode($killedBy ?? $e),
                    ]);
                    self::backoff($attempt);

                    continue;
                }

                throw $e;
            }

            try {
                $pdo->commit();
            } finally {
                self::$open = false;
                self::$lost = null;
            }

            return $result;
        }
    }

    /**
     * True when `$e`, or anything it wraps, is a 1213/1020 from the server.
     * The chain is walked because a caller may wrap a PDOException in its own
     * - the transaction is just as gone either way.
     */
    private static function retryable(\Throwable $e): bool
    {
        for ($at = $e; $at !== null; $at = $at->getPrevious()) {
            if ($at instanceof \PDOException && in_array(self::errorCode($at), self::RETRYABLE, true)) {
                return true;
            }
        }

        return false;
    }

    /** The server's own error number (1213, 1020, ...), or 0 when there is none. */
    private static function errorCode(\Throwable $e): int
    {
        if ($e instanceof \PDOException && isset($e->errorInfo[1]) && is_numeric($e->errorInfo[1])) {
            return (int) $e->errorInfo[1];
        }

        return $e->getPrevious() !== null ? self::errorCode($e->getPrevious()) : 0;
    }

    /**
     * 20-50 ms, then 40-100 ms: long enough for the transaction that won to
     * commit, short enough that a visitor never notices. Jittered so two
     * losers of the same deadlock do not walk straight back into each other.
     */
    private static function backoff(int $attempt): void
    {
        usleep(random_int(20_000, 50_000) * $attempt);
    }

    /**
     * Encode for a JSON column. Postgres had JSONB; MySQL 8 has native JSON and
     * MariaDB has JSON as a LONGTEXT alias with a json_valid() check. Both
     * accept a plain JSON string, so the application always writes one.
     */
    public static function json(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($encoded === false) {
            throw new \RuntimeException('Value cannot be stored as JSON: ' . json_last_error_msg());
        }

        return $encoded;
    }

    /**
     * Decode a JSON column. A corrupt value returns the fallback rather than
     * throwing: one bad settings row must not take the site down.
     *
     * @return array<array-key,mixed>
     */
    public static function unjson(mixed $raw, array $fallback = []): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return $fallback;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : $fallback;
    }

    /** `:name` keys for a bound statement built from a column map. @param array<string,mixed> $data @return array<string,mixed> */
    private static function named(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $out[':' . $k] = $v;
        }

        return $out;
    }

    /** Backtick-quote an identifier this codebase supplied, refusing anything unusual. */
    private static function identifier(string $name): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException('Illegal SQL identifier.');
        }

        return '`' . $name . '`';
    }
}
