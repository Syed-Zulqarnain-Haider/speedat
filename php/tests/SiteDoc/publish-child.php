<?php

declare(strict_types=1);

/**
 * One publisher, in its own process, for the concurrency test in RepoTest.
 *
 * Two publishes that only take turns inside a single PHP process prove nothing:
 * the lock they would be testing is a MySQL named lock, and a named lock held by
 * the one connection everybody shares is always free. So the test spawns real
 * processes with real connections and has them start at the same instant.
 *
 *   php tests/SiteDoc/publish-child.php <database> <start-microtime> <label> <cache-dir>
 *
 * It prints one line of JSON: {"ok":true,"version":n} or {"ok":false,"error":"..."}.
 * Not a *Test.php file, so the runner does not pick it up as a suite.
 */

use Speedat\Core\Autoloader;
use Speedat\Core\Bootstrap;
use Speedat\Core\Config;
use Speedat\Domain\SiteDoc\PublishArgs;
use Speedat\Domain\SiteDoc\Repo;

$root = dirname(__DIR__, 2);
require $root . '/app/Core/Autoloader.php';
Autoloader::register($root . '/app');
Bootstrap::cli($root);

$database = $argv[1] ?? '';
$start    = (float) ($argv[2] ?? 0);
$label    = $argv[3] ?? 'child';
$cacheDir = $argv[4] ?? '';

if (preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1) {
    echo json_encode(['ok' => false, 'error' => 'refusing a database name that is not [A-Za-z0-9_]']);
    exit(2);
}

Config::set('db.name', $database);
if ($cacheDir !== '') {
    Config::set('paths.cache', $cacheDir);
}

// Spin to the agreed instant so the processes really do collide, instead of
// queueing behind each other's interpreter start-up.
while (microtime(true) < $start) {
    usleep(500);
}

try {
    $draft = Repo::getDraft();

    $published = Repo::publishVersion(new PublishArgs(
        data: $draft->data,
        by: $label,
        source: 'admin',
        summary: $label,
        changeCount: 0,
    ));

    echo json_encode(['ok' => true, 'version' => $published->version]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e::class . ': ' . $e->getMessage()]);
    exit(1);
}
