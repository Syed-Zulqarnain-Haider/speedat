<?php

declare(strict_types=1);

/**
 * Apply the owner's settings decisions to the LIVE document, through the
 * ordinary publish path (schema-checked, versioned, auditable) rather than by
 * editing database rows.
 *
 *   php bin/local/owner-settings.php            # show what would change
 *   php bin/local/owner-settings.php --apply    # publish it
 *
 * What it sets, and why (owner, 2026-09-27):
 *  - the WhatsApp number bookings are sent to: +92 333 7667076
 *  - the phone number shown and dialled: the Lahore landline
 *  - the rates-board caption says the rates are Monday to Saturday, which is
 *    the question customers ask before they book on a Saturday evening
 *  - the pickup add-on is removed: "the PKR 500 pickup and service charges
 *    should not be added to the final price" - what a customer reads is the
 *    shipping rate itself. The add-on MECHANISM stays in Settings, so an
 *    optional charge can come back whenever the owner wants one.
 *
 * A local convenience for development. On the live site the same three things
 * are done in Admin -> Settings, which is where they belong.
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

const WHATSAPP = '923337667076';
const PHONE    = '+92 42 3521 9098';

$live = Repo::getLatestVersion();

if ($live === null) {
    fwrite(STDERR, "Nothing is published yet - run bin/seed.php first.\n");
    exit(1);
}

// The array form is the stored document, so this script cannot drift with the
// value objects' constructors; SiteData::fromArray puts it back together and
// publishVersion re-checks it against the schema.
$doc     = $live->data->toArray();
$changes = [];

if (($doc['company']['whatsapp'] ?? '') !== WHATSAPP) {
    $changes[] = sprintf('WhatsApp %s -> %s', (string) ($doc['company']['whatsapp'] ?? ''), WHATSAPP);
    $doc['company']['whatsapp'] = WHATSAPP;
}

if (($doc['company']['phone'] ?? '') !== PHONE) {
    $changes[] = sprintf('phone %s -> %s', (string) ($doc['company']['phone'] ?? ''), PHONE);
    $doc['company']['phone'] = PHONE;
}

$caption = '1 kg parcel - rates apply Monday to Saturday';

if (trim((string) ($doc['content']['routesNote'] ?? '')) !== $caption) {
    $changes[] = 'rates-board caption -> "' . $caption . '"';
    $doc['content']['routesNote'] = $caption;
}

if (trim((string) ($doc['settings']['addons'] ?? '')) !== '') {
    $changes[] = sprintf('add-ons removed (%s)', trim((string) $doc['settings']['addons']));
    $doc['settings']['addons'] = '';
}

if ($changes === []) {
    echo "Already as the owner asked - nothing to publish.\n";
    exit(0);
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
    by: 'owner-settings script',
    source: 'admin',
    summary: implode('; ', $changes),
    changeCount: count($changes),
    expectedBase: $live->version,
));

echo "\nPublished version {$published->version}.\n";
