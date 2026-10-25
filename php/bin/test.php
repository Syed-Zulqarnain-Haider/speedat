<?php

declare(strict_types=1);

/**
 * The test runner. Finds tests/**\/*Test.php, runs every public test* method
 * on every class that extends Speedat\Tests\TestCase, and exits non-zero if
 * anything failed.
 *
 *   php bin/test.php
 *   php bin/test.php --filter=RateLimit
 *   php bin/test.php --database=speedat_php_test
 *   php bin/test.php --skip-js          (accept an UNCHECKED browser engine)
 *
 * After the PHP suites it runs tests/js/run-vectors.mjs through node, which
 * puts the browser pricing engine through the same golden vectors. node is a
 * development dependency only - the Hostinger host never needs it - but a
 * missing node is reported as a failure rather than quietly passing.
 *
 * IT RUNS AGAINST THE TEST DATABASE, not the development one: the name from
 * config with "_test" appended, unless --database says otherwise. A test suite
 * that can truncate the database you were working in is a test suite people
 * stop running.
 *
 * Exit codes: 0 all green, 1 something failed, 2 a refused --database name,
 * 3 the database is reachable but has no schema (run bin/migrate.php).
 */

use Speedat\Core\Autoloader;
use Speedat\Core\Bootstrap;
use Speedat\Core\Config;
use Speedat\Core\Db;
use Speedat\Core\Paths;
use Speedat\Tests\AssertionFailed;
use Speedat\Tests\SkippedTest;
use Speedat\Tests\TestCase;

$root = dirname(__DIR__);
require $root . '/app/Core/Autoloader.php';
Autoloader::register($root . '/tests', 'Speedat\\Tests\\');
Autoloader::register($root . '/app');
Bootstrap::cli($root);

/*
 * SAY, IN THE ENVIRONMENT, THAT THE SUITE IS RUNNING - AND WHY IT HAS TO.
 *
 * bin/build-release.php now runs this file before it packs anything, and
 * tests/Core/BuildReleaseTest.php runs bin/build-release.php to check the
 * collector. Left alone those two call each other: the suite builds a release,
 * which runs the suite, which builds a release. The inner run failed rather
 * than spinning forever, so what it actually produced was six tests failing
 * with "the builder refused a clean tree" and a build gate that could never be
 * green - which is the same shape of unfixable red the harness's false alarms
 * were.
 *
 * putenv(), so the value reaches CHILD processes: the builder is launched with
 * passthru() and inherits this. One level of nesting is all there is - a
 * builder started from inside the suite sees this and skips its gate, and the
 * tree is being proven by the very suite that started it in any case.
 */
putenv('SPEEDAT_TEST_SUITE=1');

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--')) {
        $pair              = explode('=', substr($argument, 2), 2);
        $options[$pair[0]] = $pair[1] ?? '';
    }
}

$database = $options['database'] ?? '';
if ($database === '') {
    $configured = Config::str('db.name');
    $database   = str_ends_with($configured, '_test') ? $configured : $configured . '_test';
}

if (preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1) {
    fwrite(STDERR, "Refusing a database name that is not [A-Za-z0-9_].\n");
    exit(2);
}

Config::set('db.name', $database);

$filter = $options['filter'] ?? '';

echo 'PHP      : ' . PHP_VERSION . PHP_EOL;
echo 'Database : ' . $database . PHP_EOL;
echo 'Tests    : ' . Paths::root() . '/tests' . PHP_EOL;
if ($filter !== '') {
    echo 'Filter   : ' . $filter . PHP_EOL;
}
/*
 * IS THERE A SCHEMA? Asked once, here, before a single test runs.
 *
 * An empty-but-reachable database is the worst shape this runner can be in.
 * `SELECT 1` succeeds, so Db::healthy() is true and every test that checks it
 * decides to run - and then each one dies on its first query with
 * "Table 'speedat_php_test.leads' doesn't exist". A hundred and thirty-nine
 * identical PDOExceptions scroll past, none of them containing the word
 * migrate, and the reader's reasonable conclusion is that the suite is broken.
 * It is not: it has never been migrated.
 *
 * The tables are read out of db/migrations/*.sql rather than listed here, so a
 * new migration is covered without anybody remembering to come back.
 *
 * A database that cannot be reached at all is a different thing and is NOT a
 * failure: the DB-backed tests skip themselves with a reason, and the pure
 * ones - pricing, text, the whole browser engine - are worth running without a
 * database at all. That case gets one line of explanation instead.
 */
$schemaMissing = [];
$dbReachable   = Db::healthy();

if ($dbReachable) {
    $expected = [];
    foreach (glob(Paths::migrations() . '/*.sql') ?: [] as $migration) {
        // Comments first: "CREATE TABLE" inside the explanatory header of
        // 0001_init.sql is prose, not a table.
        $sql = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($migration));

        if (preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)?\s+`?([A-Za-z0-9_]+)`?/i', $sql, $matches) > 0) {
            foreach ($matches[1] as $table) {
                $expected[strtolower($table)] = true;
            }
        }
    }

    $present = [];
    foreach (Db::all('SELECT TABLE_NAME AS t FROM information_schema.tables WHERE table_schema = ?', [$database]) as $row) {
        $present[strtolower((string) ($row['t'] ?? ''))] = true;
    }

    $schemaMissing = array_keys(array_diff_key($expected, $present));
    sort($schemaMissing);
}

if ($schemaMissing !== []) {
    fwrite(STDERR, 'The database "' . $database . '" has not been migrated - '
        . count($schemaMissing) . ' table(s) missing (' . implode(', ', array_slice($schemaMissing, 0, 4))
        . (count($schemaMissing) > 4 ? ', …' : '') . '). Run: php bin/migrate.php --database=' . $database . PHP_EOL);

    exit(3);
}

if (!$dbReachable) {
    echo 'Database : NOT REACHABLE - the tests that need it will skip themselves.' . PHP_EOL;
    echo '           Start it with bin/local/start-db.ps1, then php bin/migrate.php --database=' . $database . PHP_EOL;
}

echo PHP_EOL;

$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/tests', FilesystemIterator::SKIP_DOTS)
);

/** @var SplFileInfo $entry */
foreach ($iterator as $entry) {
    if ($entry->isFile() && str_ends_with($entry->getFilename(), 'Test.php')) {
        $files[] = $entry->getPathname();
    }
}

sort($files, SORT_STRING);

$passed   = 0;
$failed   = 0;
$skipped  = 0;
$asserts  = 0;
$failures = [];

foreach ($files as $file) {
    $before = get_declared_classes();
    require_once $file;
    $classes = array_diff(get_declared_classes(), $before);

    foreach ($classes as $class) {
        if (!is_subclass_of($class, TestCase::class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract()) {
            continue;
        }

        echo $class . PHP_EOL;

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (!str_starts_with($method->getName(), 'test')) {
                continue;
            }
            if ($filter !== '' && !str_contains($class . '::' . $method->getName(), $filter)) {
                continue;
            }

            /** @var TestCase $instance */
            $instance = $reflection->newInstance();
            $instance->resetCounters();

            $name = $method->getName();

            try {
                $instance->setUp();
                $method->invoke($instance);

                $passed++;
                $asserts += $instance->assertionCount();
                echo '  [OK]   ' . $name . ' (' . $instance->assertionCount() . " assertions)\n";
            } catch (SkippedTest $e) {
                $skipped++;
                echo '  [SKIP] ' . $name . ' - ' . $e->getMessage() . PHP_EOL;
            } catch (AssertionFailed $e) {
                $failed++;
                $asserts   += $instance->assertionCount();
                $failures[] = $class . '::' . $name . ' - ' . $e->getMessage();
                echo '  [FAIL] ' . $name . ' - ' . $e->getMessage() . PHP_EOL;
            } catch (Throwable $e) {
                $failed++;
                $failures[] = $class . '::' . $name . ' - ' . $e::class . ': ' . $e->getMessage()
                    . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
                echo '  [ERR]  ' . $name . ' - ' . $e::class . ': ' . $e->getMessage() . PHP_EOL;
            } finally {
                try {
                    $instance->tearDown();
                } catch (Throwable $e) {
                    fwrite(STDERR, '  tearDown failed in ' . $class . '::' . $name . ': ' . $e->getMessage() . PHP_EOL);
                }
            }
        }

        echo PHP_EOL;
    }
}

echo str_repeat('-', 60) . PHP_EOL;
echo $passed . ' passed, ' . $failed . ' failed, ' . $skipped . ' skipped, ' . $asserts . ' assertions' . PHP_EOL;

if ($failures !== []) {
    echo PHP_EOL . "Failures:\n";
    foreach ($failures as $failure) {
        echo '  - ' . $failure . PHP_EOL;
    }
}

/*
 * The browser half of the engine.
 *
 * public/assets/js/pricing.js prices the parcel in the customer's browser and
 * app/Domain/Pricing prices it again on the server; if they disagree the
 * customer is shown one number and charged another. Both are run against the
 * same golden vectors, so this shells out to node for the half PHP cannot run.
 *
 * node is a DEVELOPMENT dependency only - nothing on the Hostinger host needs
 * it. But a missing node must not look like a pass: without --skip-js this is
 * a failure, and with it the summary says out loud that the browser engine was
 * not checked.
 */
$jsFailed  = false;
$jsSkipped = false;
$jsRunner  = $root . '/tests/js/run-vectors.mjs';

echo PHP_EOL . str_repeat('-', 60) . PHP_EOL;
echo 'Browser engine (public/assets/js/pricing.js)' . PHP_EOL;

if (isset($options['skip-js'])) {
    $jsSkipped = true;
    echo '  [SKIP] --skip-js was passed' . PHP_EOL;
} elseif (!function_exists('exec')) {
    $jsFailed = true;
    echo '  [FAIL] exec() is disabled in this PHP, so node cannot be run.' . PHP_EOL;
    echo '         Run "node tests/js/run-vectors.mjs" yourself, or pass --skip-js.' . PHP_EOL;
} elseif (!is_file($jsRunner)) {
    $jsFailed = true;
    echo '  [FAIL] tests/js/run-vectors.mjs is missing.' . PHP_EOL;
} else {
    $probe = [];
    $code  = 1;
    exec('node --version 2>&1', $probe, $code);

    if ($code !== 0) {
        $jsFailed = true;
        echo '  [FAIL] node was not found on PATH, so the browser engine was NOT checked.' . PHP_EOL;
        echo '         Install Node, or pass --skip-js to accept an unchecked browser engine.' . PHP_EOL;
    } else {
        $lines = [];
        $code  = 1;
        exec('node ' . escapeshellarg($jsRunner) . ' 2>&1', $lines, $code);

        foreach ($lines as $line) {
            echo '  ' . $line . PHP_EOL;
        }

        if ($code !== 0) {
            $jsFailed   = true;
            $failures[] = 'pricing.js disagreed with the golden vectors (see above)';
        }
    }
}

// A suite that found nothing is a broken suite, not a passing one.
if ($passed === 0 && $failed === 0 && $skipped === 0) {
    echo PHP_EOL . "RESULT: FAIL (no tests were found)\n";
    exit(1);
}

$ok = $failed === 0 && !$jsFailed;

echo PHP_EOL . 'RESULT: ' . ($ok ? 'PASS' : 'FAIL')
    . ($jsSkipped ? ' (PHP only - the browser engine was NOT checked)' : '') . PHP_EOL;

exit($ok ? 0 : 1);
