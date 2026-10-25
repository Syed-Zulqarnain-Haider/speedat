<?php

declare(strict_types=1);

/**
 * Put a freshly migrated database into a state the application can start from.
 *
 *   php bin/seed.php --owner=someone@example.com --name="Full Name"
 *   php bin/seed.php --owner=someone@example.com --database=speedat_php_test
 *   php bin/seed.php --owner=someone@example.com --password-file=../config/first-password.txt
 *   php bin/seed.php                      (settings and document only, no admin)
 *
 * It is idempotent: run it as often as you like. It never overwrites a setting
 * that is already there, never publishes over somebody's rates, and never
 * downgrades an existing admin.
 *
 * WHY --owner MATTERS NOW. The live site starts fresh with the rates and
 * nothing else (owner's decision, 2026-09-27), so bin/export-from-nextjs.php
 * carries no admins table. THIS is what creates the first person who can sign
 * in. Without it, a correctly migrated database has a published site and nobody
 * who can edit it.
 *
 * THE FIRST PUBLISHED VERSION. If `versions` is empty, the sample document from
 * Domain\SiteDoc\Seed is published as version 1, flagged `live: false` so the
 * site shows its "sample rates" notice until the owner publishes real ones. It
 * is needed because the admin editor branches its draft from the live version
 * and throws without one. Run the rates import FIRST and this step does
 * nothing: a database that already has a version is left exactly as it is, so
 * the sample can never land on top of the real prices.
 *
 * Passwords are not set on the command line, where they land in shell history.
 * Pass one through the environment if you want one:
 *   SPEEDAT_SEED_PASSWORD='...' php bin/seed.php --owner=someone@example.com
 *
 * ON A HOST WITH NO SHELL, USE --password-file INSTEAD. Hostinger's SSH is a
 * Premium-and-above extra and is off by default, so the only way to run this on
 * the live site is a one-off hPanel cron entry - and hPanel's command field is
 * not a shell: there is nowhere to put `VAR=value` in front of the command, and
 * it rejects `>` and `2>&1` outright (docs/hostinger-constraints.md section 6).
 * So the password is written into a file with File Manager and named here:
 *
 *   php bin/seed.php --owner=me@example.com --password-file=../config/first-password.txt
 *
 * The file is read, used, OVERWRITTEN WITH ZEROS AND DELETED in the same run.
 * It has to be, and by this script rather than by the owner remembering: a
 * plaintext password left in the account is a plaintext password in every
 * nightly backup from then on. If the delete fails the run says so loudly and
 * names the file, because the one outcome worse than no password is a password
 * lying about being gone.
 *
 * THE PERMISSIONS ARE SET AT THE END OF THIS SCRIPT (see bin/harden.php). A
 * zip carries no unix modes and File Manager extracts with the account's umask,
 * so a correct release lands 664/775 - group-writable, config.php included
 * (docs/go-live-rehearsal.md section 2.2). This is the first-run path, so it is
 * where the tree gets the modes the extract did not give it.
 */

use Speedat\Core\Audit;
use Speedat\Core\Autoloader;
use Speedat\Core\Bootstrap;
use Speedat\Core\Clock;
use Speedat\Core\Config;
use Speedat\Core\Db;
use Speedat\Core\Str;
use Speedat\Domain\SiteDoc\PublishArgs;
use Speedat\Domain\SiteDoc\Repo;
use Speedat\Domain\SiteDoc\Seed;

$root = dirname(__DIR__);
require $root . '/app/Core/Autoloader.php';
Autoloader::register($root . '/app');
Bootstrap::cli($root);

// The permissions walk. Required, not run: harden.php only executes when it is
// itself the script, so this brings in the functions and nothing else.
require_once __DIR__ . '/harden.php';

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--')) {
        $pair              = explode('=', substr($argument, 2), 2);
        $options[$pair[0]] = $pair[1] ?? '';
    }
}

if (isset($options['database']) && $options['database'] !== '') {
    if (preg_match('/^[A-Za-z0-9_]+$/', $options['database']) !== 1) {
        fwrite(STDERR, "Refusing a database name that is not [A-Za-z0-9_].\n");
        exit(2);
    }
    Config::set('db.name', $options['database']);
}

echo 'Seeding ' . Config::str('db.name') . PHP_EOL . PHP_EOL;

try {
    /**
     * The price hold, off. Its shape is the port of Hold in
     * src/lib/site/hold-shared.ts: on, message, by, since.
     *
     * This is the ONLY app_settings row the seed writes. The Next.js app also
     * wrote an `intake` row holding the rate-sheet mailbox settings; the owner
     * removed that feature from the product on 2026-09-27, so the row is not
     * created here and is not carried by the rates import either. A settings
     * row nothing reads is a thing the next person has to work out.
     */
    settingDefault('hold', [
        'on'      => false,
        'message' => 'Our rates are being updated. Please message us on WhatsApp for a price today.',
        'by'      => 'seed',
        'since'   => null,
    ]);

    seedFirstVersion();

    if (isset($options['owner']) && $options['owner'] !== '') {
        seedOwner($options['owner'], $options['name'] ?? '');
    } else {
        echo "  = no --owner given; skipping the admin row\n";
        echo "    (nobody can sign in yet: re-run with --owner=<email>)\n";
    }

    Audit::log('system', 'seed_run', ['database' => Config::str('db.name')]);
} catch (Throwable $e) {
    fwrite(STDERR, 'Seed failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

tightenPermissions($root);

echo PHP_EOL . "Done.\n";
exit(0);

/**
 * Write an app_settings row only if the key is absent. An existing value is
 * somebody's decision and is left exactly as it is.
 *
 * @param array<string,mixed> $value
 */
function settingDefault(string $key, array $value): void
{
    $exists = Db::value('SELECT 1 FROM app_settings WHERE k = ?', [$key]);

    if ($exists !== null) {
        echo "  = app_settings[{$key}] already set\n";

        return;
    }

    Db::insert('app_settings', [
        'k'          => $key,
        'v'          => Db::json($value),
        'updated_at' => Clock::sql(),
        'updated_by' => 'seed',
    ]);

    echo "  + app_settings[{$key}]\n";
}

/**
 * Publish the sample document as version 1, but ONLY on an empty table.
 *
 * The guard is the whole point. The intended order on a real host is: import
 * the schema, import the rates, then seed. If the rates are already in, this
 * sees a version and does nothing - it must never be possible for a seed run to
 * put made-up prices on top of the airline's real ones.
 *
 * The document is `live: false`, which keeps the "sample rates" notice on the
 * site until the owner ticks the box himself.
 */
function seedFirstVersion(): void
{
    $highest = Db::value('SELECT MAX(version) FROM versions');

    if ($highest !== null) {
        echo '  = versions already has ' . (int) $highest . " published version(s)\n";

        return;
    }

    $published = Repo::publishVersion(new PublishArgs(
        data: Seed::site(),
        by: 'seed',
        source: 'seed',
        summary: 'Sample rates',
        changeCount: 0,
    ));

    echo '  + version ' . $published->version . " published (sample rates, not flagged live)\n";
    echo "    The admin editor needs a live version to branch its draft from.\n";
}

/** Create the first owner, or promote an existing admin to owner. Never demotes. */
function seedOwner(string $email, string $name): void
{
    $email = Str::email($email);

    if (!Str::isEmail($email)) {
        throw new RuntimeException('--owner must be an email address');
    }

    $existing = Db::one('SELECT email, role FROM admins WHERE email = ?', [$email]);

    if ($existing !== null) {
        if (($existing['role'] ?? '') !== 'owner') {
            Db::exec('UPDATE admins SET role = ? WHERE email = ?', ['owner', $email]);
            echo "  ~ {$email} promoted to owner\n";
        } else {
            echo "  = {$email} is already an owner\n";
        }
    } else {
        Db::insert('admins', [
            'email'      => $email,
            'name'       => Str::limit($name !== '' ? $name : $email, 190),
            'role'       => 'owner',
            'added_by'   => 'seed',
            'created_at' => Clock::sql(),
        ]);
        echo "  + {$email} added as owner\n";
    }

    $password = firstPassword();

    if (is_string($password) && $password !== '') {
        if (strlen($password) < 12) {
            throw new RuntimeException('the first password must be at least 12 characters');
        }

        // argon2id where the host has it, bcrypt everywhere else. PASSWORD_DEFAULT
        // would do the same thing, but naming it makes the fallback visible.
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        $hash      = password_hash($password, $algorithm);

        Db::exec('UPDATE admins SET password_hash = ? WHERE email = ?', [$hash, $email]);
        echo '  ~ password set (' . (defined('PASSWORD_ARGON2ID') ? 'argon2id' : 'bcrypt') . ")\n";
    }
}

/**
 * The first password, from the environment or from --password-file.
 *
 * The environment is the developer's route; the file is the only one that
 * works through an hPanel cron entry, which is not a shell and has nowhere to
 * put `VAR=value`. Whichever it came from, the value never appears in an
 * argument: arguments are world-readable in /proc on a shared box, which is
 * the same reason the rehearsal piped its SQL from a 600 file rather than
 * typing it (docs/go-live-rehearsal.md section 2.3).
 *
 * The file is destroyed here rather than left for the owner to remember. It is
 * overwritten with its own length in zeros before the unlink, so the bytes are
 * not simply unlinked and left in place for anything that reads the raw
 * filesystem - a courtesy, not a guarantee, on a host whose disk we do not own.
 */
function firstPassword(): ?string
{
    global $options;

    $fromEnvironment = getenv('SPEEDAT_SEED_PASSWORD');

    if (is_string($fromEnvironment) && $fromEnvironment !== '') {
        return $fromEnvironment;
    }

    $file = $options['password-file'] ?? '';

    if (!is_string($file) || $file === '') {
        return null;
    }

    if (!is_file($file) || !is_readable($file)) {
        throw new RuntimeException('--password-file: cannot read ' . $file);
    }

    $contents = @file_get_contents($file);

    if (!is_string($contents)) {
        throw new RuntimeException('--password-file: cannot read ' . $file);
    }

    // Trailing newline only. A password may legitimately start or end with a
    // space, and trimming both ends would silently sign the owner in with a
    // different password than the one he typed into File Manager.
    $password = rtrim($contents, "\r\n");

    $length = strlen($contents);
    $wiped  = @file_put_contents($file, str_repeat("\0", $length > 0 ? $length : 1)) !== false;
    $gone   = @unlink($file);

    if (!$gone) {
        // Loud, and naming the file. A plaintext password still sitting in the
        // account is a plaintext password in every nightly backup from now on,
        // and the one thing worse than that is not being told.
        fwrite(STDERR, PHP_EOL
            . "COULD NOT DELETE THE PASSWORD FILE: " . $file . PHP_EOL
            . ($wiped ? "Its contents were overwritten, but the file is still there." : "Its contents are STILL READABLE.") . PHP_EOL
            . "Delete it yourself now - hPanel -> File Manager -> right-click -> Delete." . PHP_EOL . PHP_EOL);
    } else {
        echo "  ~ password read from " . basename($file) . ", which has been wiped and deleted\n";
    }

    return $password;
}

/**
 * Set the permissions the extract did not set.
 *
 * THIS IS THE FIRST-RUN PATH, so this is where it belongs. An install reaches
 * here having already extracted the archive, and an extract writes every file
 * with the account's umask: the rehearsal's correct release landed 664/775,
 * group-writable, config.php included. Doing it here means the ordinary
 * procedure - extract, fill config, run the seed - ends with the modes right
 * even if the owner never opens the permissions dialog.
 *
 * It never FAILS the seed. The admin row and the first version are the things
 * this script exists to write and they are already written by now; a host that
 * refuses a chmod is a thing to report, not a reason to leave a site with no
 * owner. bin/harden.php run on its own exits non-zero for exactly that case.
 */
function tightenPermissions(string $root): void
{
    echo PHP_EOL . "Permissions\n";

    if (DIRECTORY_SEPARATOR === '\\') {
        echo "  = Windows has no POSIX modes to set; nothing to do here.\n";

        return;
    }

    if (!function_exists('chmod')) {
        echo "  ! chmod() is disabled in this PHP build.\n";
        echo "    Set them by hand: hPanel -> File Manager -> right-click -> Permissions.\n";

        return;
    }

    $report = speedat_harden($root, true);

    echo '  ' . $report['changed'] . ' changed, ' . $report['kept'] . " already correct\n";

    foreach (array_slice($report['failed'], 0, 5) as $failure) {
        echo '  ! refused: ' . $failure . "\n";
    }

    if ($report['loose'] !== []) {
        echo '  ! ' . count($report['loose']) . " path(s) are still group- or world-writable.\n";
        echo "    Run bin/harden.php on its own, or use the File Manager dialog.\n";

        return;
    }

    echo "  Nothing is group- or world-writable.\n";
}
