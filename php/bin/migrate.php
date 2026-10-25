<?php

declare(strict_types=1);

/**
 * Apply the SQL migrations in db/migrations, in filename order, once each.
 *
 *   php bin/migrate.php                     apply everything pending
 *   php bin/migrate.php --dry-run           list what would run, change nothing
 *   php bin/migrate.php --database=other    point at another schema (the tests
 *                                           use this for speedat_php_test)
 *
 * A migration is recorded in schema_migrations with the SHA-256 of the file it
 * ran from, so editing a migration that has already been applied is reported
 * rather than silently ignored - "works on my machine" is usually this.
 *
 * On a Hostinger plan without SSH, import db/migrations/0001_init.sql through
 * phpMyAdmin instead; every statement in it is IF NOT EXISTS, and this runner
 * will simply find the tables already there.
 */

use Speedat\Core\Autoloader;
use Speedat\Core\Bootstrap;
use Speedat\Core\Config;
use Speedat\Core\Db;
use Speedat\Core\Paths;

$root = dirname(__DIR__);
require $root . '/app/Core/Autoloader.php';
Autoloader::register($root . '/app');
Bootstrap::cli($root);

$options  = parseOptions($argv);
$dryRun   = isset($options['dry-run']);
$database = is_string($options['database'] ?? null) ? $options['database'] : '';

if ($database !== '') {
    if (preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1) {
        fwrite(STDERR, "Refusing a database name that is not [A-Za-z0-9_].\n");
        exit(2);
    }
    Config::set('db.name', $database);
}

echo 'Database : ' . Config::str('db.name') . ' on ' . Config::str('db.host') . ':' . Config::int('db.port', 3306) . PHP_EOL;
echo 'Migrations: ' . Paths::migrations() . PHP_EOL . PHP_EOL;

$files = glob(Paths::migrations() . '/*.sql') ?: [];
sort($files, SORT_STRING);

if ($files === []) {
    echo "Nothing to do - no .sql files found.\n";
    exit(0);
}

try {
    ensureLedger();
    $applied = appliedMigrations();
} catch (Throwable $e) {
    fwrite(STDERR, 'Cannot reach the database: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$ran = 0;

foreach ($files as $file) {
    $name = basename($file);
    $sql  = file_get_contents($file);

    if ($sql === false) {
        fwrite(STDERR, "  ! {$name} could not be read\n");
        exit(1);
    }

    $checksum = hash('sha256', $sql);

    if (isset($applied[$name])) {
        if ($applied[$name] !== $checksum) {
            // Not fatal: the tables are already as this file left them the
            // first time. Fatal would stop a deployment over a comment.
            fwrite(STDERR, "  ~ {$name} already applied, but the file has CHANGED since.\n");
            fwrite(STDERR, "    Write the change as a new migration instead of editing this one.\n");
        } else {
            echo "  = {$name}\n";
        }
        continue;
    }

    $statements = splitStatements($sql);

    if ($dryRun) {
        echo '  ? ' . $name . ' (' . count($statements) . " statements, not run)\n";
        continue;
    }

    echo '  + ' . $name . ' (' . count($statements) . ' statements) ... ';

    try {
        // DDL cannot be rolled back in MySQL - an ALTER commits the
        // transaction around it - so a failure stops everything here and says
        // exactly which statement it was. Finishing the rest would leave a
        // schema nobody can reason about.
        foreach ($statements as $index => $statement) {
            try {
                Db::pdo()->exec($statement);
            } catch (Throwable $e) {
                throw new RuntimeException(
                    'statement ' . ($index + 1) . ' failed: ' . $e->getMessage()
                        . PHP_EOL . '    ' . substr(preg_replace('/\s+/', ' ', $statement) ?? '', 0, 200),
                    0,
                    $e
                );
            }
        }

        Db::insert('schema_migrations', [
            'filename'   => $name,
            'applied_at' => gmdate('Y-m-d H:i:s'),
            'checksum'   => $checksum,
        ]);

        echo "ok\n";
        $ran++;
    } catch (Throwable $e) {
        echo "FAILED\n";
        fwrite(STDERR, '    ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

echo PHP_EOL . ($dryRun ? 'Dry run - nothing was changed.' : ($ran === 0 ? 'Already up to date.' : $ran . ' migration(s) applied.')) . PHP_EOL;
exit(0);

/**
 * Split a migration into statements.
 *
 * A naive explode(';') breaks on the first semicolon inside a string, a
 * comment or a JSON default - all three of which appear in this project's
 * migrations - so this walks the file and only treats a semicolon as a
 * separator when it is not inside quotes or a comment.
 *
 * @return list<string>
 */
function splitStatements(string $sql): array
{
    $statements = [];
    $current    = '';
    $length     = strlen($sql);

    $inSingle = false;
    $inDouble = false;
    $inTick   = false;
    $inLine   = false;
    $inBlock  = false;

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        if ($inLine) {
            $current .= $char;
            if ($char === "\n") {
                $inLine = false;
            }
            continue;
        }

        if ($inBlock) {
            $current .= $char;
            if ($char === '*' && $next === '/') {
                $current .= $next;
                $i++;
                $inBlock = false;
            }
            continue;
        }

        if ($inSingle || $inDouble || $inTick) {
            $current .= $char;

            // MySQL accepts both '' and \' inside a string.
            if ($char === '\\' && $next !== '') {
                $current .= $next;
                $i++;
                continue;
            }
            if ($inSingle && $char === "'") {
                $inSingle = false;
            } elseif ($inDouble && $char === '"') {
                $inDouble = false;
            } elseif ($inTick && $char === '`') {
                $inTick = false;
            }
            continue;
        }

        // Comment openers: "-- " (or "--" at end of line), "#", "/*".
        if ($char === '-' && $next === '-') {
            $inLine   = true;
            $current .= $char;
            continue;
        }
        if ($char === '#') {
            $inLine   = true;
            $current .= $char;
            continue;
        }
        if ($char === '/' && $next === '*') {
            $inBlock  = true;
            $current .= $char;
            continue;
        }

        if ($char === "'") {
            $inSingle = true;
        } elseif ($char === '"') {
            $inDouble = true;
        } elseif ($char === '`') {
            $inTick = true;
        } elseif ($char === ';') {
            $statement = trim($current);
            if (stripComments($statement) !== '') {
                $statements[] = $statement;
            }
            $current = '';
            continue;
        }

        $current .= $char;
    }

    $tail = trim($current);
    if (stripComments($tail) !== '') {
        $statements[] = $tail;
    }

    return $statements;
}

/** Is there anything but comments and whitespace here? */
function stripComments(string $sql): string
{
    $withoutBlocks = preg_replace('#/\*.*?\*/#s', '', $sql) ?? $sql;
    $withoutLines  = preg_replace('/^\s*(--|#).*$/m', '', $withoutBlocks) ?? $withoutBlocks;

    return trim($withoutLines);
}

function ensureLedger(): void
{
    Db::pdo()->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            filename   VARCHAR(190) NOT NULL,
            applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            checksum   CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            PRIMARY KEY (filename)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/** @return array<string,string> filename => checksum */
function appliedMigrations(): array
{
    $out = [];
    foreach (Db::all('SELECT filename, checksum FROM schema_migrations') as $row) {
        $out[(string) $row['filename']] = (string) $row['checksum'];
    }

    return $out;
}

/**
 * @param  list<string>            $argv
 * @return array<string,string|bool>
 */
function parseOptions(array $argv): array
{
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (!str_starts_with($argument, '--')) {
            continue;
        }
        $pair               = explode('=', substr($argument, 2), 2);
        $options[$pair[0]]  = $pair[1] ?? true;
    }

    return $options;
}
