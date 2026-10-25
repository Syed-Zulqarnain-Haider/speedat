<?php

declare(strict_types=1);

/**
 * Add a service to the live rate card, through the ordinary publish path.
 *
 *   php bin/local/add-service.php --id=saver --name="Express Saver" \
 *       --note="A slower air service at a lower price" --for=gb,de
 *   php bin/local/add-service.php ... --apply
 *
 * The service is created with NO prices. That is deliberate: prices are the
 * owner's to type, and the site shows a service only where it has a price, so
 * a country that does not offer it is simply not offered it. The named
 * destinations get the service's days copied from their Express entry as a
 * starting point (a Saver is never faster than Express) and empty price boxes
 * the owner fills in Admin -> Rates.
 *
 * A stop-gap: managing services belongs in the admin, and is being built. This
 * exists so the owner can start keying Express Saver prices today.
 */

require __DIR__ . '/../../app/Core/Autoloader.php';

use Speedat\Core\Autoloader;

Autoloader::register(__DIR__ . '/../../app');

use Speedat\Core\Config;
use Speedat\Core\Paths;
use Speedat\Domain\SiteDoc\PublishArgs;
use Speedat\Domain\SiteDoc\Repo;
use Speedat\Domain\SiteDoc\SiteData;

Paths::init(dirname(__DIR__, 2));
Config::load(__DIR__ . '/../../config/config.php');

/** @return array<string,string> */
function options(array $argv): array
{
    $out = [];
    foreach ($argv as $arg) {
        if (preg_match('/^--([a-z-]+)=(.*)$/', (string) $arg, $m) === 1) {
            $out[$m[1]] = $m[2];
        }
    }

    return $out;
}

$opt  = options($argv);
$id   = strtolower(trim($opt['id'] ?? ''));
$name = trim($opt['name'] ?? '');
$note = trim($opt['note'] ?? '');
$for  = array_values(array_filter(array_map('trim', explode(',', $opt['for'] ?? ''))));

if ($id === '' || $name === '' || preg_match('/^[a-z0-9-]{2,40}$/', $id) !== 1) {
    fwrite(STDERR, "Usage: php bin/local/add-service.php --id=saver --name=\"Express Saver\" [--note=\"...\"] [--for=gb,de] [--apply]\n");
    exit(2);
}

$live = Repo::getLatestVersion();

if ($live === null) {
    fwrite(STDERR, "Nothing is published yet - run bin/seed.php first.\n");
    exit(1);
}

$doc = $live->data->toArray();

foreach ($doc['services'] ?? [] as $service) {
    if (($service['id'] ?? '') === $id) {
        echo "The service \"$id\" already exists - nothing to do.\n";
        exit(0);
    }
}

$doc['services'][] = ['id' => $id, 'name' => $name, 'note' => $note];
$changes           = ['service "' . $name . '" added'];
$touched           = [];

foreach ($doc['destinations'] as $i => $destination) {
    $destId = (string) ($destination['id'] ?? '');

    if ($for !== [] && !in_array($destId, $for, true)) {
        continue;
    }

    // Empty prices, but carry the days across so the row reads sensibly before
    // anything is typed. The Saver is never quicker than Express.
    $days = (string) ($destination['rates']['express']['days'] ?? '');

    $doc['destinations'][$i]['rates'][$id] = ['days' => $days, 'grid' => new stdClass()];
    $touched[]                             = (string) ($destination['name'] ?? $destId);
}

if ($touched !== []) {
    $changes[] = 'offered on ' . implode(', ', $touched) . ' (no prices yet)';
}

echo "Version {$live->version} would become version " . ($live->version + 1) . ":\n";
foreach ($changes as $line) {
    echo '  - ' . $line . "\n";
}

if (!in_array('--apply', $argv, true)) {
    echo "\nNothing published. Re-run with --apply.\n";
    exit(0);
}

$published = Repo::publishVersion(new PublishArgs(
    data: SiteData::fromArray($doc),
    by: 'add-service script',
    source: 'admin',
    summary: implode('; ', $changes),
    changeCount: count($changes),
    expectedBase: $live->version,
));

echo "\nPublished version {$published->version}. Type its prices in Admin -> Rates.\n";
