<?php

declare(strict_types=1);

/**
 * Carry the PUBLISHED RATES out of the Next.js Postgres database and into the
 * PHP app's MySQL schema. Nothing else.
 *
 * ---------------------------------------------------------------------------
 * WHAT IT CARRIES, AND WHAT IT DELIBERATELY DOES NOT
 * ---------------------------------------------------------------------------
 * CARRIED
 *   versions      every published site document, with its number, its date and
 *                 who published it. This IS the rates: the destinations, the
 *                 1..25 kg boxes, the services, the surcharges, the company
 *                 details and the page copy all live inside that one JSON
 *                 document, so "the rates" and "the site document" are the same
 *                 thing. The history comes too, because Restore is a feature
 *                 the owner has and a version nobody kept is a version nobody
 *                 can go back to.
 *   draft         the admin's working copy, so he opens the editor on the same
 *                 half-finished edit he left in the old one.
 *   app_settings  only the keys in SETTINGS_KEYS below - today that is the
 *                 price hold, and it is not optional: leave it behind and the
 *                 new site comes up SHOWING prices the owner had deliberately
 *                 taken down.
 *
 * NOT CARRIED, BY THE OWNER'S DECISION (2026-09-27)
 *   leads, quotes, shipments, shipment_events, audit_log
 *                 They are our own test data. The old site was never handed to
 *                 customers, so there is no real enquiry, no real quote and no
 *                 real shipment in there - and importing test rows into a live
 *                 inbox means the office starts its first morning deleting
 *                 things. The live site starts empty and fills up with real work.
 *   admins        There is no data to import, so there is nobody to import. The
 *                 first owner is created by `php bin/seed.php --owner=<email>`,
 *                 which also means no TOTP secret has to survive a key change
 *                 and no password hash crosses a database boundary.
 *   imports       The table is gone with the Excel rate-sheet feature the owner
 *                 removed on 2026-09-27. The rates CSV EXPORT is unaffected;
 *                 it was the upload, the mapper and the emailed sheets that went.
 *   rate_limits   Ephemeral counters keyed by a hash salted with the OLD app's
 *                 secret, in windows that closed before the site moved. They
 *                 identify nobody here.
 *
 * If you want any of that after all, it is a deliberate change to the SPEC
 * below, not a flag - a flag is how test data ends up on a live site at 2am.
 *
 * ---------------------------------------------------------------------------
 * HOW TO RUN IT
 * ---------------------------------------------------------------------------
 *   php bin/export-from-nextjs.php --dump=../var/nextjs-dump.json
 *   php bin/export-from-nextjs.php --dump=... --out=../var/rates.sql --gzip
 *   php bin/export-from-nextjs.php --dump=... --import-into=speedat_php_test --truncate
 *
 * It does not touch the source beyond reading it, and it does not touch the
 * destination unless you ask it to with --import-into. By default it writes ONE
 * .sql file that you import through hPanel -> phpMyAdmin.
 *
 * TWO SOURCE MODES
 *   --dump=FILE                read the JSON dump (the supported path here)
 *   --pg=postgres://user:pass@host:port/db     read Postgres directly (PDO)
 *
 * THE DIRECT MODE DOES NOT WORK ON THIS MACHINE. D:\php (PHP 8.2.34) is built
 * without pdo_pgsql - PDO::getAvailableDrivers() returns ["mysql"] and there is
 * no pgsql extension either. So the supported path is:
 *
 *   node php/deploy/export/dump-postgres.mjs --url=postgres://... --out=FILE
 *   php  bin/export-from-nextjs.php --dump=FILE
 *
 * The Node script reads Postgres with the `postgres` package the Next.js app
 * already depends on, casts every column to text in SQL, and writes JSON.
 * Nothing is installed for it. It dumps more tables than this script reads;
 * the extra ones are reported as "not carried" and ignored. --pg is kept
 * working for the day this runs somewhere with the driver, and both modes feed
 * the SAME spec below, so neither can quietly disagree about what a column means.
 *
 * ---------------------------------------------------------------------------
 * WHAT MAKES IT IDEMPOTENT
 * ---------------------------------------------------------------------------
 * Every row is emitted with its source primary key and every statement is
 * INSERT ... ON DUPLICATE KEY UPDATE. Import the file twice and the second run
 * changes nothing and reports the same counts. That matters more than it
 * sounds: a go-live import that fails halfway must be safe to just run again.
 *
 * Nothing here is append-only any more, because audit_log is not carried, so
 * there is no INSERT IGNORE and no history this file could rewrite.
 *
 * Spelling differences, declared in the SPEC and not buried in code:
 * jsonb -> JSON, timestamptz -> DATETIME in UTC, key -> k, value -> v, and
 * versions.id dropped in favour of versions.version as the primary key.
 */

// ---------------------------------------------------------------------------
// Standalone by design
// ---------------------------------------------------------------------------
// This script does not load the application. It runs before the application
// exists on the target host, it must run against a config that is not written
// yet, and a migration tool that needs the thing it is migrating to is a
// migration tool you cannot use. Everything it needs is in this file.

const EXIT_OK      = 0;
const EXIT_FAILED  = 1;
const EXIT_BAD_USE = 2;

/**
 * The app_settings keys that travel, and why each one does.
 *
 * An allow-list rather than a deny-list on purpose. The Next.js database has
 * rows nobody remembers writing - `intake` held the dead rate-sheet mailbox
 * settings - and a deny-list carries whatever the next person adds. Every key
 * that is NOT here is named in the report, so nothing is dropped silently.
 */
const SETTINGS_KEYS = [
    // The one switch that takes every price off the website at once. Not
    // optional: see the header.
    'hold' => 'the price hold',
];

$root = dirname(__DIR__);

// ---------------------------------------------------------------------------
// The mapping. This array IS the specification.
// ---------------------------------------------------------------------------
//
// Per table:
//   target     MySQL table name
//   keys       the MySQL primary key columns - never in the UPDATE clause
//   columns    target column => rule
//
// Per column rule:
//   from       source key in the dump (defaults to the target name)
//   type       'int' | 'uint' | 'str' | 'text' | 'json' | 'datetime'
//   max        VARCHAR length; longer is an error, not a silent truncation
//   empty      value substituted when the source is NULL and the column is not
//              nullable (MySQL TEXT columns cannot carry a DEFAULT)
//   const      a literal emitted instead of reading the source
//
// The order of the tables is the order they are written and imported: the
// published document first, then the draft that points at it, then the hold
// that decides whether any of it is shown. A half-finished import leaves a site
// that can still serve prices.
$SPEC = [
    'versions' => [
        'target'  => 'versions',
        // The bigserial `id` is dropped: in MySQL the version number IS the
        // primary key, which is what the Postgres unique index always meant.
        'keys'    => ['version'],
        'columns' => [
            'version'      => ['type' => 'uint'],
            'published_at' => ['type' => 'datetime'],
            'published_by' => ['type' => 'str', 'max' => 190],
            'source'       => ['type' => 'str', 'max' => 255],
            'summary'      => ['type' => 'text', 'empty' => ''],
            'change_count' => ['type' => 'int'],
            'data'         => ['type' => 'json'],
        ],
    ],

    'draft' => [
        'target'  => 'draft',
        'keys'    => ['id'],
        'columns' => [
            // MySQL has CHECK (id = 1) - there is exactly one draft. The source
            // agrees, but forcing it here means a stray row cannot smuggle in a
            // second draft that the CHECK would then reject mid-import.
            'id'           => ['const' => '1', 'type' => 'uint'],
            'base_version' => ['type' => 'uint'],
            'data'         => ['type' => 'json'],
            'updated_at'   => ['type' => 'datetime'],
            'updated_by'   => ['type' => 'str', 'max' => 190],
        ],
    ],

    'app_settings' => [
        'target' => 'app_settings',
        'keys'   => ['k'],
        // Only the keys in SETTINGS_KEYS. Filtered on the SOURCE key, before
        // anything is rendered.
        'filter' => 'settings',
        'columns' => [
            // key -> k, value -> v: both are reserved words in MySQL. The Node
            // dump aliases them at the SELECT, so the dump already carries k
            // and v; --pg maps them in pgSourceColumn().
            'k'          => ['type' => 'str', 'max' => 64],
            'v'          => ['type' => 'json'],
            'updated_at' => ['type' => 'datetime'],
            'updated_by' => ['type' => 'str', 'max' => 190, 'empty' => ''],
        ],
    ],
];

// ---------------------------------------------------------------------------
// Arguments
// ---------------------------------------------------------------------------

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (!str_starts_with($argument, '--')) {
        fwrite(STDERR, "Unexpected argument \"$argument\". Everything is a --flag.\n");
        exit(EXIT_BAD_USE);
    }
    $pair              = explode('=', substr($argument, 2), 2);
    $options[$pair[0]] = $pair[1] ?? '';
}

if (isset($options['help']) || $argv === [$argv[0]]) {
    fwrite(STDOUT, usage());
    exit(isset($options['help']) ? EXIT_OK : EXIT_BAD_USE);
}

$dumpFile   = strval($options['dump'] ?? '');
$pgDsn      = strval($options['pg'] ?? '');
$outFile    = strval($options['out'] ?? '');
$importInto = strval($options['import-into'] ?? '');
$only       = array_values(array_filter(array_map('trim', explode(',', strval($options['only'] ?? '')))));
$maxBytes   = max(4096, (int) ($options['max-statement-bytes'] ?? 262144));
$gzip       = isset($options['gzip']);
$truncate   = isset($options['truncate']);

if ($dumpFile === '' && $pgDsn === '') {
    fwrite(STDERR, "Nothing to read. Pass --dump=FILE or --pg=DSN.\n\n" . usage());
    exit(EXIT_BAD_USE);
}
if ($dumpFile !== '' && $pgDsn !== '') {
    fwrite(STDERR, "Pass --dump OR --pg, not both.\n");
    exit(EXIT_BAD_USE);
}
if ($importInto !== '' && preg_match('/^[A-Za-z0-9_]+$/', $importInto) !== 1) {
    fwrite(STDERR, "Refusing a database name that is not [A-Za-z0-9_].\n");
    exit(EXIT_BAD_USE);
}

// A typo in --only used to mean "emit nothing" and report success. This script
// carries three tables; naming one that is not among them is a mistake.
$unknown = array_diff($only, array_keys($SPEC));
if ($unknown !== []) {
    fwrite(STDERR, '--only names ' . implode(', ', $unknown) . ', which this script does not carry.'
        . ' It carries: ' . implode(', ', array_keys($SPEC)) . ".\n");
    exit(EXIT_BAD_USE);
}

if ($outFile === '') {
    $outFile = $root . '/var/speedat-rates-' . gmdate('Ymd-His') . '.sql';
}
$outFile = str_replace('\\', '/', $outFile);

// ---------------------------------------------------------------------------
// Read the source
// ---------------------------------------------------------------------------

echo "Speedat - Next.js (Postgres) -> PHP (MySQL): the published rates\n";
echo str_repeat('=', 72) . "\n\n";

try {
    [$tables, $sourceLabel, $missingTables] = $dumpFile !== ''
        ? readDump($dumpFile)
        : readPostgres($pgDsn, $SPEC);
} catch (Throwable $e) {
    fwrite(STDERR, 'Cannot read the source: ' . $e->getMessage() . "\n");
    exit(EXIT_FAILED);
}

echo 'source   : ' . $sourceLabel . "\n";
echo 'out      : ' . $outFile . ($gzip ? ' (+ .gz)' : '') . "\n";
echo "carries  : the published site document (the rates), the draft, and the price hold\n";
echo "leaves   : leads, quotes, shipments, audit rows, admins - see the header\n";
echo "\n";

// ---------------------------------------------------------------------------
// Build the statements
// ---------------------------------------------------------------------------

/** @var list<string> $statements every element is sent to the server on its own */
$statements = [];
/** @var list<array{table:string,source:int,emitted:int,note:string}> $report */
$report = [];
/** @var list<string> $problems */
$problems = [];
/** @var array<string,int> $warnings */
$warnings = [];
/** @var list<string> $skippedSettings app_settings keys left behind, named out loud */
$skippedSettings = [];

$dataStatements = 0;

foreach (header_sql($sourceLabel) as $line) {
    $statements[] = $line;
}

foreach ($SPEC as $name => $spec) {
    if ($only !== [] && !in_array($name, $only, true)) {
        continue;
    }
    if (!array_key_exists($name, $tables)) {
        $report[] = ['table' => $name, 'source' => 0, 'emitted' => 0,
            'note' => in_array($name, $missingTables, true) ? 'absent from source' : 'not in dump'];
        continue;
    }

    $rows     = $tables[$name];
    $sourceN  = count($rows);
    $filtered = '';

    if (($spec['filter'] ?? '') === 'settings') {
        [$rows, $skippedSettings] = filterSettings($rows);
        $filtered = $skippedSettings === []
            ? ''
            : count($skippedSettings) . ' other key(s) left behind';
    }

    // Catch schema drift before it silently drops a column: every column the
    // spec wants must exist in the source rows. A dump written against an older
    // Postgres schema fails here with the column's name rather than importing
    // a NULL nobody notices until the site is live.
    if ($rows !== []) {
        foreach ($spec['columns'] as $target => $rule) {
            if (array_key_exists('const', $rule)) {
                continue;
            }
            $from = $rule['from'] ?? $target;
            if (!array_key_exists($from, $rows[0])) {
                $problems[] = "$name: the source has no column \"$from\" (needed for $target). "
                    . 'The dump and this script disagree about the schema.';
            }
        }
    }

    [$stmts, $emitted, $tableProblems, $tableWarnings] = buildTable($name, $spec, $rows, $maxBytes);

    foreach ($stmts as $s) {
        $statements[] = $s;
        if (!str_starts_with(ltrim($s), '--')) {
            $dataStatements++;
        }
    }
    foreach ($tableProblems as $p) {
        $problems[] = $p;
    }
    foreach ($tableWarnings as $key => $n) {
        $warnings[$key] = ($warnings[$key] ?? 0) + $n;
    }

    $report[] = ['table' => $name, 'source' => $sourceN, 'emitted' => $emitted, 'note' => $filtered];
}

// Every table in the dump that this script does not carry, named so that
// "where did the leads go" has an answer on the screen rather than in a file.
$notCarried = array_values(array_diff(array_keys($tables), array_keys($SPEC)));
sort($notCarried);

$statements[] = footer_sql();

if ($problems !== []) {
    fwrite(STDERR, "\nREFUSING TO WRITE. " . count($problems) . " problem(s):\n\n");
    foreach (array_slice($problems, 0, 40) as $p) {
        fwrite(STDERR, '  * ' . $p . "\n");
    }
    if (count($problems) > 40) {
        fwrite(STDERR, '  ... and ' . (count($problems) - 40) . " more.\n");
    }
    fwrite(STDERR, "\nNothing was written. Fix the source data (or the spec) and run again.\n");
    exit(EXIT_FAILED);
}

// ---------------------------------------------------------------------------
// Write
// ---------------------------------------------------------------------------

$sqlText = implode("\n", $statements) . "\n";

if (!is_dir(dirname($outFile)) && !@mkdir(dirname($outFile), 0775, true) && !is_dir(dirname($outFile))) {
    fwrite(STDERR, 'Cannot create ' . dirname($outFile) . "\n");
    exit(EXIT_FAILED);
}
if (file_put_contents($outFile, $sqlText) === false) {
    fwrite(STDERR, 'Cannot write ' . $outFile . "\n");
    exit(EXIT_FAILED);
}

$gzFile = null;
if ($gzip) {
    $gzFile  = $outFile . '.gz';
    $gzBytes = gzencode($sqlText, 9);
    if ($gzBytes === false || file_put_contents($gzFile, $gzBytes) === false) {
        fwrite(STDERR, "Could not gzip the output.\n");
        exit(EXIT_FAILED);
    }
}

// ---------------------------------------------------------------------------
// Optionally run it, and count what landed
// ---------------------------------------------------------------------------

/** @var array<string,int> $destinationCounts */
$destinationCounts = [];
$imported          = false;

if ($importInto !== '') {
    try {
        $pdo = mysqlConnect($options, $importInto);

        if ($truncate) {
            echo "TRUNCATE (--truncate) on $importInto ...\n";
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach (array_keys($SPEC) as $name) {
                if ($only !== [] && !in_array($name, $only, true)) {
                    continue;
                }
                $pdo->exec('TRUNCATE TABLE ' . quoteIdent($SPEC[$name]['target']));
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        // Executed statement by statement from the list we built, not by
        // re-parsing the file: a multi-statement exec() can swallow the error
        // of everything after the first one, and a migration that reports
        // success while half of it failed is the worst possible outcome.
        $ran = 0;
        foreach ($statements as $statement) {
            $trimmed = trim(stripSqlComments($statement));
            if ($trimmed === '') {
                continue;
            }
            $pdo->exec($trimmed);
            $ran++;
        }

        foreach ($SPEC as $name => $spec) {
            $destinationCounts[$name] = (int) $pdo
                ->query('SELECT COUNT(*) FROM ' . quoteIdent($spec['target']))
                ->fetchColumn();
        }

        $imported = true;
        echo "imported : $ran statement(s) into $importInto\n\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "\nThe import failed: " . $e->getMessage() . "\n");
        fwrite(STDERR, 'The .sql file was still written to ' . $outFile . "\n");
        exit(EXIT_FAILED);
    }
}

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------

echo str_pad('table', 18) . str_pad('source', 9, ' ', STR_PAD_LEFT)
    . str_pad('emitted', 10, ' ', STR_PAD_LEFT)
    . ($imported ? str_pad('in mysql', 11, ' ', STR_PAD_LEFT) : '')
    . '  note' . "\n";
echo str_repeat('-', 46 + ($imported ? 11 : 0)) . "\n";

$totalSource = $totalEmitted = 0;
foreach ($report as $line) {
    $totalSource  += $line['source'];
    $totalEmitted += $line['emitted'];
    echo str_pad($line['table'], 18)
        . str_pad((string) $line['source'], 9, ' ', STR_PAD_LEFT)
        . str_pad((string) $line['emitted'], 10, ' ', STR_PAD_LEFT)
        . ($imported ? str_pad((string) ($destinationCounts[$line['table']] ?? 0), 11, ' ', STR_PAD_LEFT) : '')
        . '  ' . $line['note'] . "\n";
}
echo str_repeat('-', 46 + ($imported ? 11 : 0)) . "\n";
echo str_pad('TOTAL', 18) . str_pad((string) $totalSource, 9, ' ', STR_PAD_LEFT)
    . str_pad((string) $totalEmitted, 10, ' ', STR_PAD_LEFT) . "\n\n";

if ($skippedSettings !== []) {
    echo 'settings left behind : ' . implode(', ', $skippedSettings) . "\n";
}
if ($notCarried !== []) {
    echo 'tables not carried   : ' . implode(', ', $notCarried) . "\n";
}
if ($skippedSettings !== [] || $notCarried !== []) {
    echo "                       (by the owner's decision - see the header of this script)\n\n";
}

foreach ($warnings as $what => $count) {
    echo "warning  : $what ($count row(s))\n";
}
if ($warnings !== []) {
    echo "\n";
}

echo 'wrote    : ' . $outFile . '  (' . number_format((float) strlen($sqlText) / 1024, 1) . ' KiB, '
    . $dataStatements . " INSERT statement(s))\n";
if ($gzFile !== null) {
    echo 'wrote    : ' . $gzFile . '  (' . number_format((float) filesize($gzFile) / 1024, 1) . " KiB)\n";
}
echo 'sha256   : ' . hash('sha256', $sqlText) . "\n";

if ($imported) {
    $mismatch = false;
    foreach ($report as $line) {
        if ($line['emitted'] > 0 && ($destinationCounts[$line['table']] ?? -1) < $line['emitted']) {
            fwrite(STDERR, "MISMATCH: {$line['table']} emitted {$line['emitted']} but MySQL holds "
                . ($destinationCounts[$line['table']] ?? 0) . "\n");
            $mismatch = true;
        }
    }
    if ($mismatch) {
        exit(EXIT_FAILED);
    }
    echo "\nEvery emitted row is present in $importInto. Run this again to prove\n";
    echo "idempotency: the counts must not move.\n";
}

echo "\nNext: php bin/seed.php --owner=<email> against the same database. There is\n";
echo "no admin row in this file, so that is what creates the first one.\n";

exit(EXIT_OK);

// ===========================================================================
// Reading
// ===========================================================================

/**
 * @return array{0: array<string, list<array<string,?string>>>, 1: string, 2: list<string>}
 */
function readDump(string $file): array
{
    if (!is_file($file)) {
        throw new RuntimeException("no such dump file: $file");
    }

    $raw = file_get_contents($file);
    if ($raw === false) {
        throw new RuntimeException("cannot read $file");
    }

    /** @var mixed $json */
    $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($json) || ($json['format'] ?? '') !== 'speedat-nextjs-dump/1') {
        throw new RuntimeException(
            "$file is not a speedat-nextjs-dump/1 file. Produce one with:\n"
            . '  node php/deploy/export/dump-postgres.mjs --url=postgres://... --out=' . $file
        );
    }

    $tables = [];
    foreach (($json['tables'] ?? []) as $name => $rows) {
        if (!is_string($name) || !is_array($rows)) {
            continue;
        }
        $tables[$name] = array_values($rows);
    }

    $missing = array_values(array_filter(
        (array) ($json['missingTables'] ?? []),
        static fn ($t): bool => is_string($t),
    ));

    $label = sprintf(
        'JSON dump %s (%s, generated %s)',
        basename($file),
        (string) ($json['source'] ?? 'unknown'),
        (string) ($json['generatedAt'] ?? 'unknown'),
    );

    return [$tables, $label, $missing];
}

/**
 * The direct-from-Postgres path. Unavailable on the machine this was written on
 * (no pdo_pgsql in D:\php) and therefore UNTESTED - it is here so the tool is
 * not permanently tied to Node, and it fails loudly rather than pretending.
 *
 * @param array<string,array<string,mixed>> $spec
 * @return array{0: array<string, list<array<string,?string>>>, 1: string, 2: list<string>}
 */
function readPostgres(string $dsn, array $spec): array
{
    if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException(
            'this PHP has no pdo_pgsql (drivers: ' . implode(', ', PDO::getAvailableDrivers()) . ").\n"
            . "  Use the Node dump instead - it needs nothing installed:\n"
            . '      node php/deploy/export/dump-postgres.mjs --url=' . redact($dsn) . " --out=../var/nextjs-dump.json\n"
            . '      php  bin/export-from-nextjs.php --dump=../var/nextjs-dump.json'
        );
    }

    [$pdoDsn, $user, $pass] = pgDsnToPdo($dsn);
    $pdo = new PDO($pdoDsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $tables  = [];
    $missing = [];

    foreach ($spec as $name => $table) {
        $exists = $pdo->prepare('SELECT to_regclass(?) IS NOT NULL');
        $exists->execute(['public.' . $name]);
        if ((bool) $exists->fetchColumn() === false) {
            $missing[] = $name;
            continue;
        }

        // Same casts as deploy/export/dump-postgres.mjs, generated from the
        // same spec so the two cannot drift: everything comes back as text.
        $select = [];
        foreach ($table['columns'] as $target => $rule) {
            if (array_key_exists('const', $rule)) {
                continue;
            }
            $from     = (string) ($rule['from'] ?? $target);
            $source   = pgSourceColumn($name, $from);
            $select[] = match ($rule['type']) {
                'datetime' => sprintf('to_char("%s" AT TIME ZONE \'UTC\', \'YYYY-MM-DD HH24:MI:SS\') AS "%s"', $source, $from),
                default    => sprintf('"%s"::text AS "%s"', $source, $from),
            };
        }

        $sql  = 'SELECT ' . implode(', ', $select) . ' FROM "' . $name . '"';
        $rows = $pdo->query($sql)->fetchAll();

        $tables[$name] = array_map(
            static fn (array $row): array => array_map(
                static fn ($v): ?string => $v === null ? null : (string) $v,
                $row,
            ),
            $rows,
        );
    }

    return [$tables, 'Postgres ' . redact($dsn), $missing];
}

/** The columns whose NAME differs between the schemas. */
function pgSourceColumn(string $table, string $column): string
{
    $renames = [
        'app_settings' => ['k' => 'key', 'v' => 'value'],
    ];

    return $renames[$table][$column] ?? $column;
}

/** @return array{0:string,1:string,2:string} */
function pgDsnToPdo(string $url): array
{
    $p = parse_url($url);
    if (!is_array($p) || !isset($p['host'])) {
        throw new RuntimeException('cannot parse the Postgres URL');
    }

    return [
        sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $p['host'],
            $p['port'] ?? 5432,
            ltrim($p['path'] ?? '', '/'),
        ),
        rawurldecode($p['user'] ?? ''),
        rawurldecode($p['pass'] ?? ''),
    ];
}

function redact(string $url): string
{
    return (string) preg_replace('#//([^:/@]+):[^@]*@#', '//$1:***@', $url);
}

// ===========================================================================
// Building SQL
// ===========================================================================

/**
 * Keep only the app_settings rows in SETTINGS_KEYS, and report the rest.
 *
 * It reads `k` OR `key` because the two source modes spell it differently: the
 * Node dump already aliases `"key" AS "k"` (it renames both reserved words at
 * the SELECT so the exporter never has to remember which engine it is talking
 * to), while --pg reads the real Postgres column, `key`. Accepting either means
 * a dump produced by an older Node script still filters correctly instead of
 * silently keeping nothing.
 *
 * @param  list<array<string,?string>> $rows
 * @return array{0: list<array<string,?string>>, 1: list<string>}
 */
function filterSettings(array $rows): array
{
    $kept    = [];
    $skipped = [];

    foreach ($rows as $row) {
        $key = (string) ($row['key'] ?? $row['k'] ?? '');

        if (array_key_exists($key, SETTINGS_KEYS)) {
            $kept[] = $row;
            continue;
        }

        $skipped[] = $key === '' ? '(unnamed)' : $key;
    }

    sort($skipped);

    return [$kept, array_values(array_unique($skipped))];
}

/**
 * @param array<string,mixed>         $spec
 * @param list<array<string,?string>> $rows
 * @return array{0: list<string>, 1: int, 2: list<string>, 3: array<string,int>}
 */
function buildTable(string $name, array $spec, array $rows, int $maxBytes): array
{
    /** @var list<string> $problems */
    $problems = [];
    /** @var array<string,int> $warnings */
    $warnings = [];

    if ($rows === []) {
        return [[], 0, [], []];
    }

    $target  = (string) $spec['target'];
    $columns = array_keys($spec['columns']);
    $keys    = $spec['keys'];

    $insert = 'INSERT INTO ' . quoteIdent($target) . ' ('
        . implode(', ', array_map('quoteIdent', $columns)) . ') VALUES';

    $updateCols = array_values(array_filter($columns, static fn (string $c): bool => !in_array($c, $keys, true)));
    $suffix     = $updateCols === []
        ? ';'
        : "\n  ON DUPLICATE KEY UPDATE "
            . implode(', ', array_map(
                // VALUES(col) rather than the MySQL 8.0.20 row-alias form:
                // MariaDB, which is what Hostinger actually runs, does not
                // accept `AS new`. VALUES() works on both.
                static fn (string $c): string => quoteIdent($c) . ' = VALUES(' . quoteIdent($c) . ')',
                $updateCols,
            ))
            . ';';

    /** @var list<string> $statements */
    $statements = [];
    /** @var list<string> $tuples */
    $tuples  = [];
    $bytes   = 0;
    $emitted = 0;

    foreach ($rows as $index => $row) {
        $values = [];

        foreach ($spec['columns'] as $column => $rule) {
            $raw = array_key_exists('const', $rule)
                ? $rule['const']
                : ($row[$rule['from'] ?? $column] ?? null);

            try {
                $values[] = renderValue($raw, $rule, "$name.$column", $index, $warnings);
            } catch (RuntimeException $e) {
                $problems[] = $e->getMessage();
                $values[]   = 'NULL';
            }
        }

        $tuple = '  (' . implode(', ', $values) . ')';

        // Keep each statement small. phpMyAdmin parses the file with PHP, and
        // MySQL has max_allowed_packet; a single 40 MB INSERT is the classic
        // way a migration that worked locally dies on shared hosting. The site
        // document is a big JSON blob, so this bites here sooner than it looks.
        if ($tuples !== [] && $bytes + strlen($tuple) > $maxBytes) {
            $statements[] = $insert . "\n" . implode(",\n", $tuples) . $suffix;
            $tuples       = [];
            $bytes        = 0;
        }

        $tuples[] = $tuple;
        $bytes   += strlen($tuple) + 2;
        $emitted++;
    }

    if ($tuples !== []) {
        $statements[] = $insert . "\n" . implode(",\n", $tuples) . $suffix;
    }

    array_unshift(
        $statements,
        '-- ' . str_repeat('-', 70) . "\n"
        . "-- $target : $emitted row(s), INSERT .. ON DUPLICATE KEY UPDATE\n"
        . '-- ' . str_repeat('-', 70),
    );

    return [$statements, $emitted, $problems, $warnings];
}

/**
 * Turn one source value into one SQL literal, checking it against the MySQL
 * column as it goes. Anything that would be silently mangled on import is
 * raised here instead, where the message can name the table, column and row.
 *
 * Nothing carried by this script is nullable, which is why there is no 'null'
 * rule any more: versions, draft and the hold are all NOT NULL, and a NULL
 * arriving in one of them is a source problem to be reported, not absorbed.
 *
 * @param array<string,mixed> $rule
 * @param array<string,int>   $warnings
 */
function renderValue(?string $raw, array $rule, string $where, int $index, array &$warnings): string
{
    $type = (string) $rule['type'];

    if ($raw === null) {
        if (array_key_exists('empty', $rule)) {
            $raw = (string) $rule['empty'];
        } else {
            throw new RuntimeException("$where row $index: source is NULL but the MySQL column is NOT NULL");
        }
    }

    switch ($type) {
        case 'int':
        case 'uint':
            if (preg_match('/^-?\d+$/', $raw) !== 1) {
                throw new RuntimeException("$where row $index: \"$raw\" is not an integer");
            }
            if ($type === 'uint' && $raw[0] === '-') {
                throw new RuntimeException("$where row $index: $raw is negative but the column is UNSIGNED");
            }
            // Emitted unquoted, as a numeric literal. It came out of Postgres
            // as text precisely so it could not go through a float on the way.
            return $raw;

        case 'datetime':
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $raw) !== 1) {
                throw new RuntimeException("$where row $index: \"$raw\" is not a UTC 'Y-m-d H:i:s' datetime");
            }
            return quoteString($raw, $where);

        case 'json':
            // MySQL's JSON type refuses invalid JSON, and MariaDB's json_valid()
            // CHECK does the same. Catching it here names the row; catching it
            // during the import names only a line number in a 700 KB file.
            json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            return quoteString($raw, $where);

        case 'str':
            $max = (int) ($rule['max'] ?? 0);
            // mb_strlen, not strlen: the column is VARCHAR(n) CHARACTERS, and a
            // name in Urdu is two to three bytes a letter. Counting bytes here
            // would reject rows that fit perfectly well.
            if ($max > 0 && mb_strlen($raw, 'UTF-8') > $max) {
                throw new RuntimeException(
                    "$where row $index: " . mb_strlen($raw, 'UTF-8') . " characters, but the column is VARCHAR($max)"
                );
            }
            return quoteString($raw, $where);

        case 'text':
            return quoteString($raw, $where);

        default:
            throw new RuntimeException("$where: unknown spec type \"$type\"");
    }
}

/**
 * Quote a string for MySQL.
 *
 * NO PDO CONNECTION IS REQUIRED and that is the point: the file has to be
 * produceable on a laptop that cannot reach the destination database, because
 * on Hostinger the destination is reached through phpMyAdmin in a browser.
 *
 * Why this is safe rather than the usual "never hand-roll escaping":
 *
 *   * The output charset is utf8mb4 and is SET by the file's own header. The
 *     classic hand-rolled-escaping hole needs a charset where a multi-byte
 *     character can end in 0x5C (GBK, SJIS); UTF-8 never produces 0x5C, 0x27 or
 *     0x00 as a continuation byte, so escaping those bytes cannot be fooled by
 *     a character boundary.
 *   * The file's header sets sql_mode explicitly and WITHOUT
 *     NO_BACKSLASH_ESCAPES, so backslash escaping is what the server will use.
 *     That is the whole reason the header sets sql_mode at all.
 *   * Invalid UTF-8 is refused rather than escaped. A byte sequence that is not
 *     a character has no business in a utf8mb4 column and is exactly the input
 *     an escaping routine can be tricked with.
 *
 * The escaped set is the one mysql_real_escape_string uses: NUL, \n, \r, \,
 * ', " and Ctrl-Z (which ends input on a Windows console reading a .sql file).
 */
function quoteString(string $value, string $where): string
{
    if (preg_match('//u', $value) !== 1) {
        throw new RuntimeException("$where: the value is not valid UTF-8 and cannot be written to a utf8mb4 column");
    }

    return "'" . strtr($value, [
        '\\'   => '\\\\',
        "'"    => "\\'",
        '"'    => '\\"',
        "\0"   => '\\0',
        "\n"   => '\\n',
        "\r"   => '\\r',
        "\x1a" => '\\Z',
    ]) . "'";
}

/** Every identifier is backticked, so `by`, `key` and `rows` need no special case. */
function quoteIdent(string $identifier): string
{
    if (preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
        throw new RuntimeException("refusing an identifier that is not [A-Za-z0-9_]: $identifier");
    }

    return '`' . $identifier . '`';
}

/**
 * The file's preamble, as SEPARATE statements: one comment block and then one
 * SET per element. They are separate because --import-into executes the list
 * element by element - a multi-statement exec() can hide the failure of
 * everything after the first one, and "SET NAMES silently did not apply" is a
 * bug that shows up months later as mojibake in the page copy.
 *
 * @return list<string>
 */
function header_sql(string $source): array
{
    $comment = [
        '-- Speedat - the published rates, migrated from the Next.js Postgres database.',
        '--',
        '-- Generated : ' . gmdate('Y-m-d H:i:s') . ' UTC by bin/export-from-nextjs.php',
        '-- Source    : ' . $source,
        '--',
        '-- WHAT IS IN HERE',
        '--   versions      every published site document - the destinations, the 1..25 kg',
        '--                 boxes, the services, the surcharges, the company details and',
        '--                 the page copy. This is "the rates".',
        '--   draft         the admin editor\'s working copy.',
        '--   app_settings  the price hold row, and nothing else.',
        '--',
        '-- WHAT IS NOT, BY THE OWNER\'S DECISION (2026-09-27)',
        '--   No leads, no quotes, no shipments, no audit rows: they were our own test',
        '--   data and the live site starts empty. No admins either - create the first',
        '--   one with:  php bin/seed.php --owner=<email>',
        '--',
        '-- SAFE TO RUN TWICE. Every statement is INSERT ... ON DUPLICATE KEY UPDATE.',
        '--',
        '-- It creates NOTHING. Import db/migrations/0001_init.sql first; this file',
        '-- fills the tables that file made.',
        '--',
        '-- NO CUSTOMER DATA. There is not a name, a phone number or an email address in',
        '-- here beyond whatever the company put on its own contact page. It is still',
        '-- the shop\'s prices, so delete it from the server once the import is verified.',
    ];

    return [
        implode("\n", $comment),

        'SET NAMES utf8mb4;',

        // UTC, because every DATETIME in this file is UTC. Without this the
        // server's own zone is assumed and every timestamp moves by five hours.
        "SET @@session.time_zone = '+00:00';",

        // STRICT, so a value that does not fit is an error rather than a silent
        // truncation. Note what is NOT in this list: NO_BACKSLASH_ESCAPES. The
        // string literals below are backslash-escaped, so that mode must stay
        // off; setting sql_mode explicitly is how this file stops depending on
        // whatever the host's default happens to be.
        "SET @@session.sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,"
            . "ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';",
    ];
}

function footer_sql(): string
{
    return "\n-- End of the rates import. Verify with:\n"
        . "--   SELECT 'versions', COUNT(*) FROM versions\n"
        . "--   UNION ALL SELECT 'draft', COUNT(*) FROM draft\n"
        . "--   UNION ALL SELECT 'settings', COUNT(*) FROM app_settings;\n"
        . "--\n"
        . "-- Then:  php bin/seed.php --owner=<email>";
}

/** Comment-only "statements" must not be sent to the server. */
function stripSqlComments(string $statement): string
{
    $kept = [];
    foreach (explode("\n", $statement) as $line) {
        if (!str_starts_with(ltrim($line), '--')) {
            $kept[] = $line;
        }
    }

    return implode("\n", $kept);
}

/** @param array<string,string> $options */
function mysqlConnect(array $options, string $database): PDO
{
    $host = $options['mysql-host'] ?? '127.0.0.1';
    $port = (int) ($options['mysql-port'] ?? 3307);
    $user = $options['mysql-user'] ?? 'speedat';
    $pass = $options['mysql-pass'] ?? 'speedat';

    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database),
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ],
    );

    echo "mysql    : $user@$host:$port/$database\n";

    return $pdo;
}

function usage(): string
{
    return <<<TXT
    Usage: php bin/export-from-nextjs.php --dump=FILE [options]

    Carries the PUBLISHED RATES and nothing else: the versions table (the site
    document), the admin draft, and the price-hold settings row. No leads, no
    quotes, no shipments, no audit rows, no admins - the live site starts fresh
    (owner's decision, 2026-09-27). Create the first admin afterwards with
    php bin/seed.php --owner=<email>.

    Source (exactly one)
      --dump=FILE               JSON from deploy/export/dump-postgres.mjs
      --pg=postgres://...       read Postgres directly (needs pdo_pgsql, which
                                D:\\php does NOT have)

    Output
      --out=FILE                default var/speedat-rates-<utc>.sql
      --gzip                    also write FILE.gz (phpMyAdmin accepts .sql.gz)
      --max-statement-bytes=N   split INSERTs at N bytes (default 262144)
      --only=t1,t2              restrict to versions, draft and/or app_settings

    Test it end to end
      --import-into=DB          execute the statements against MySQL
      --truncate                empty the target tables first (TEST DB ONLY)
      --mysql-host= --mysql-port= --mysql-user= --mysql-pass=
                                default 127.0.0.1:3307 speedat/speedat

    TXT;
}
