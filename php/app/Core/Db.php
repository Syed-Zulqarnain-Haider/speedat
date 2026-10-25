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

        try {
            self::$pdo = new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE                  => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE       => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES         => false,
                \PDO::ATTR_STRINGIFY_FETCHES        => false,
                \PDO::MYSQL_ATTR_INIT_COMMAND       => $init,
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

        $stmt->execute();

        return $stmt;
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
     * @template T
     * @param  \Closure():T $work
     * @return T
     */
    public static function transaction(\Closure $work): mixed
    {
        $pdo = self::pdo();

        // Nested calls join the outer transaction rather than failing: MySQL
        // has no real nesting and a silent second BEGIN would commit early.
        if ($pdo->inTransaction()) {
            return $work();
        }

        $pdo->beginTransaction();
        try {
            $result = $work();
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
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
