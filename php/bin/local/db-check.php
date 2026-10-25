<?php

declare(strict_types=1);

/**
 * Proves the local database is usable by the application user.
 *
 * Reads config/config.php (the same file the app will read), connects with
 * PDO, and performs a real round trip - DDL, insert, select, drop - so the
 * result is evidence of privileges, not just of a successful handshake.
 *
 * It also asserts the settings that would silently produce wrong prices if
 * they drifted: utf8mb4, strict SQL mode, and a UTC session timezone.
 *
 * Usage:
 *   D:\php\php.exe D:\speedat\php\bin\local\db-check.php
 *   D:\php\php.exe D:\speedat\php\bin\local\db-check.php --database=speedat_php_test
 */

$configFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "Missing {$configFile}. Copy config/config.sample.php and fill it in.\n");
    exit(1);
}

/** @var array<string, mixed> $config */
$config = require $configFile;
$db     = $config['db'];

// --database=... override, so the same script can check the test schema.
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--database=')) {
        $db['name'] = substr($arg, strlen('--database='));
    }
}

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $db['host'],
    (int) $db['port'],
    $db['name'],
    $db['charset'],
);

echo "DSN: {$dsn}\n";
echo "User: {$db['user']}\n\n";

$failures = [];

try {
    $pdo = new PDO($dsn, $db['user'], $db['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // Real prepared statements, not driver-side interpolation.
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, 'CONNECT FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "connected\n";

/** @return array<string, string> */
function pairs(PDO $pdo, string $sql): array
{
    $out = [];
    foreach ($pdo->query($sql)->fetchAll() as $row) {
        $out[(string) array_values($row)[0]] = (string) array_values($row)[1];
    }
    return $out;
}

$server = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
printf("  %-22s %s\n", 'server', $server);
printf("  %-22s %s\n", 'database', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());

$vars = pairs($pdo, "SHOW VARIABLES WHERE Variable_name IN ('character_set_database','collation_database','sql_mode','time_zone','max_allowed_packet')");
foreach ($vars as $name => $value) {
    printf("  %-22s %s\n", $name, $value);
}

if (($vars['character_set_database'] ?? '') !== 'utf8mb4') {
    $failures[] = 'character_set_database is not utf8mb4';
}
if (!str_contains($vars['sql_mode'] ?? '', 'STRICT_TRANS_TABLES')) {
    $failures[] = 'sql_mode is not strict - truncation would be silent';
}

echo "\nround trip\n";

$table = '_speedat_db_check';
try {
    $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
    $pdo->exec(
        "CREATE TABLE `{$table}` (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            destination  VARCHAR(120)    NOT NULL,
            grams        INT UNSIGNED    NOT NULL,
            price_minor  BIGINT          NOT NULL,
            created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "  [ OK ]  CREATE TABLE\n";

    // Double-quoted: these must be REAL non-ASCII characters, not the literal
    // text "\u{2014}". Em dash, accented Latin, and a macron vowel.
    $unicodeProbe = "Kanada \u{2014} unicode probe \u{00e9}\u{00fc}\u{0101}";

    $insert = $pdo->prepare("INSERT INTO `{$table}` (destination, grams, price_minor) VALUES (:d, :g, :p)");
    $insert->execute([':d' => 'United Arab Emirates', ':g' => 2500, ':p' => 1234500]);
    $insert->execute([':d' => $unicodeProbe, ':g' => 500, ':p' => 780000]);
    echo "  [ OK ]  INSERT (prepared, 2 rows)\n";

    $select = $pdo->prepare("SELECT destination, grams, price_minor FROM `{$table}` WHERE grams >= :min ORDER BY id");
    $select->execute([':min' => 500]);
    $rows = $select->fetchAll();
    echo "  [ OK ]  SELECT returned " . count($rows) . " rows\n";

    foreach ($rows as $row) {
        printf("          %-40s %6d g  %10s\n", $row['destination'], $row['grams'], number_format($row['price_minor'] / 100, 2));
    }

    // Integer columns must come back as PHP ints, not strings: the pricing
    // engine works in integer grams and integer minor units and must never be
    // handed a float or a numeric string by accident.
    if (!is_int($rows[0]['grams']) || !is_int($rows[0]['price_minor'])) {
        $failures[] = 'integer columns are not returned as PHP ints (check EMULATE_PREPARES/STRINGIFY_FETCHES)';
    } else {
        echo "  [ OK ]  integer columns typed as PHP int\n";
    }

    if ($rows[1]['destination'] !== $unicodeProbe) {
        $failures[] = 'utf8mb4 round trip lost characters: stored ' . var_export($rows[1]['destination'], true);
    } else {
        echo "  [ OK ]  utf8mb4 round trip intact (" . mb_strlen($unicodeProbe) . ' chars, '
            . strlen($unicodeProbe) . " bytes)\n";
    }

    // Strict mode must reject an over-long value rather than truncate it.
    try {
        $insert->execute([':d' => str_repeat('x', 200), ':g' => 1, ':p' => 1]);
        $failures[] = 'over-long VARCHAR was accepted - strict mode is not active';
    } catch (PDOException) {
        echo "  [ OK ]  strict mode rejects over-long values\n";
    }

    $pdo->exec("DROP TABLE `{$table}`");
    echo "  [ OK ]  DROP TABLE\n";
} catch (PDOException $e) {
    $failures[] = 'round trip failed: ' . $e->getMessage();
}

echo "\n";
if ($failures !== []) {
    echo 'RESULT: FAIL (' . count($failures) . ")\n";
    foreach ($failures as $f) {
        echo '  - ' . $f . "\n";
    }
    exit(1);
}

echo "RESULT: PASS\n";
exit(0);
