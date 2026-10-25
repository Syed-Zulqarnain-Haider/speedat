<?php

declare(strict_types=1);

/**
 * Build the release zip that gets uploaded to Hostinger.
 *
 *   php bin/build-release.php
 *   php bin/build-release.php --out=../dist --name=speedat-2026-09-27.zip
 *   php bin/build-release.php --list          also print every entry
 *   php bin/build-release.php --skip-tests    build from an UNPROVEN tree
 *
 * ---------------------------------------------------------------------------
 * IT RUNS THE TEST SUITE FIRST, AND A RED SUITE MEANS NO ZIP
 * ---------------------------------------------------------------------------
 * Every check further down this script is a check on the SHAPE of the archive:
 * is the entry list right, does each file match its source byte for byte, is a
 * secret hiding inside one. Not one of them runs a line of the application, so
 * every one of them passes on an archive of code that cannot serve a page.
 *
 * That is not hypothetical. A rehearsal shipped a release whose views read a
 * property that had been removed from the view model. Every file parsed, every
 * entry matched its source, nothing on the deny list was inside it - and the
 * installed home page answered 500. The suite had been red the whole time. It
 * simply was never asked.
 *
 * So bin/test.php runs BEFORE anything is collected, and its exit code decides
 * whether there is a build at all. --skip-tests exists because a machine with
 * no test database still has to be able to cut a release, but it prints a
 * warning nobody can miss and stamps "TESTS NOT RUN" into the archive comment
 * and INSTALL.txt - so a zip built that way is identifiable long after the
 * terminal it was built in has been closed.
 *
 * ---------------------------------------------------------------------------
 * WHY A SCRIPT AND NOT "ZIP THE FOLDER"
 * ---------------------------------------------------------------------------
 * Because zipping the folder ships config/config.php. That file holds the
 * application key, the database password, the SMTP password and the cron token,
 * and it would land in public_html's parent on a server where it is one
 * misconfigured document root away from being downloadable. It has happened to
 * better projects than this one.
 *
 * So the rule is inverted: this script builds an explicit manifest, refuses
 * anything on a deny list, and then - the part that actually matters - RE-CHECKS
 * the deny list against the entries that are really inside the finished zip and
 * deletes the zip if any of them should not be there. A bug in the collector
 * cannot ship a secret; it can only fail the build.
 *
 * ---------------------------------------------------------------------------
 * WHAT COMES OUT
 * ---------------------------------------------------------------------------
 * The zip's top level IS the contents of ~/domains/<site>/ on Hostinger:
 *
 *     public_html/        the contents of php/public - the ONLY web-served dir
 *                         including .htaccess AND .user.ini, because a host
 *                         reads one or the other and never announces which
 *     speedat_main/php/   THE APPLICATION, exactly where public/index.php
 *                         looks for it first:
 *       app/                application code
 *       bin/                cron.php, migrate.php, seed.php
 *       config/             config.sample.php only. NEVER config.php.
 *       db/migrations/      the schema
 *       public/             a second copy of public_html (see below)
 *       storage/            cache logs tmp uploads - empty, guarded, writable
 *       var/                empty, guarded
 *     INSTALL.txt         the ten-line version of docs/deploy-hostinger.md
 *
 * WHY speedat_main/php AND NOT app/ BESIDE public_html. The live site runs from
 * domains/<site>/speedat_main/php - that is the first place index.php looks,
 * and the only place it looks when that folder exists. This archive used to put
 * app/, config/ and the rest straight beside public_html. Extracting it
 * replaced public_html and wrote a whole second application next to it that
 * nothing ever ran: the new index.php found speedat_main/php and kept serving
 * the OLD app/, routes and views, new CSS and JS were served against old HTML,
 * a cron pointed at domains/<site>/bin/cron.php booted a tree with no config,
 * and "roll back by extracting the previous zip" never touched the running code
 * either (audit, 2026-10-23). One layout, the one the server actually reads.
 *
 * WHY public/ IS SHIPPED TWICE. Paths::publicDir() is speedat_main/php/public,
 * and three things read it rather than the served web root: the inline icon
 * sprite, the share-card existence check, and View's fallback when
 * DOCUMENT_ROOT is unset (cron, CLI). Leaving it out of the release leaves that
 * copy as old as the last hand-upload. It is the same bytes as public_html and
 * sits outside the web root, so it costs zip size and nothing else.
 *
 * Extract it AT ~/domains/<site>/, not inside public_html. That single mistake
 * is what every deny-all .htaccess in the archive exists to survive.
 *
 * bin/ is in the release even though nothing web-facing uses it: the one hPanel
 * cron entry runs bin/cron.php, and without it the sessions, rate-limit windows
 * and logs are never swept. bin/local/, this script and the migration exporter
 * are development tools and are left out.
 */

const EXIT_OK     = 0;
const EXIT_FAILED = 1;

/**
 * Where the application sits inside the archive, and so on the host.
 *
 * Everything below this line - the manifest, the deny list, the guards, the
 * required list - speaks in the application's OWN paths (`app/...`,
 * `config/...`, `public_html/...`), because those are the paths every rule was
 * written and tested against. Only the writer and the final check translate,
 * through archivePath() and keyOf(), so the prefix lives in one place and a rule
 * scoped to `app/` or `db/migrations/` cannot silently stop matching because
 * every entry now starts with `speedat_main/`.
 */
const APP_PREFIX = 'speedat_main/php';

$root = str_replace('\\', '/', dirname(__DIR__));

// ---------------------------------------------------------------------------
// Options
// ---------------------------------------------------------------------------

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (!str_starts_with($argument, '--')) {
        fwrite(STDERR, "Unexpected argument \"$argument\".\n");
        exit(EXIT_FAILED);
    }
    $pair              = explode('=', substr($argument, 2), 2);
    $options[$pair[0]] = $pair[1] ?? '';
}

if (isset($options['help'])) {
    echo "Usage: php bin/build-release.php [--out=DIR] [--name=FILE.zip] [--list] [--skip-tests]\n";
    exit(EXIT_OK);
}

$outDir    = str_replace('\\', '/', (string) ($options['out'] ?? $root . '/var'));
$zipName   = (string) ($options['name'] ?? 'speedat-release-' . gmdate('Ymd-His') . '.zip');
$verbose   = isset($options['list']);
$skipTests = isset($options['skip-tests']);

if (!extension_loaded('zip')) {
    fwrite(STDERR,
        "ext-zip is not loaded, so no zip can be written.\n"
        . "This script runs on the DEVELOPMENT machine, not on Hostinger, so\n"
        . "enabling it is a one-line change to php.ini - uncomment extension=zip.\n");
    exit(EXIT_FAILED);
}

echo "Speedat - release builder\n";
echo str_repeat('=', 72) . "\n";
echo "source : $root\n";
echo "target : $outDir/$zipName\n\n";

// ---------------------------------------------------------------------------
// The tree has to be green before it is worth packing
// ---------------------------------------------------------------------------
//
// FIRST, not last, and before a single file is collected. A build that packs
// for forty seconds and then says "by the way, the suite is red" has already
// cost the forty seconds and - worse - has written a zip somebody may pick up
// out of var/ tomorrow.
//
// The tests are run in a CHILD process, not required into this one. This script
// has global state of its own (the manifest, the deny list), bin/test.php calls
// exit() on its own schedule, and the test suite truncates a database. Sharing
// a process with any of that is how a build starts passing because a test set a
// constant.
$testsRan = false;

/*
 * ALREADY INSIDE THE SUITE? Then the tree is being proven by the run that
 * started this, and asking for a second one is a loop.
 *
 * tests/Core/BuildReleaseTest.php runs this script to check the collector, and
 * bin/test.php sets SPEEDAT_TEST_SUITE=1 before anything else so that a
 * builder started from in there does not turn round and run the suite again.
 * Without it the two call each other and six tests fail with "the builder
 * refused a clean tree" - an unfixable red, on a tree with nothing wrong.
 *
 * This is NOT a way to skip the gate by hand: setting the variable in a shell
 * to dodge a red suite is exactly the thing --skip-tests exists to do loudly
 * instead, and a release built that way carries no TESTS NOT RUN stamp to
 * admit it.
 */
$insideTheSuite = getenv('SPEEDAT_TEST_SUITE') === '1';

if ($insideTheSuite && !$skipTests) {
    echo "tests  : already running inside bin/test.php - not starting a second suite\n\n";
} elseif ($skipTests) {
    // Loud, on STDERR, framed. This is the one line that has to survive being
    // scrolled past in a terminal at the end of a long day.
    fwrite(STDERR, str_repeat('!', 72) . "\n");
    fwrite(STDERR, "!! --skip-tests: THIS RELEASE IS BUILT FROM AN UNPROVEN TREE.\n");
    fwrite(STDERR, "!! Nothing here runs the application. Every check in this script is a\n");
    fwrite(STDERR, "!! check on the SHAPE of the archive, and all of them pass on code that\n");
    fwrite(STDERR, "!! cannot serve a page. A release built this way has 500'd a live home\n");
    fwrite(STDERR, "!! page before. The archive is stamped TESTS NOT RUN so this is still\n");
    fwrite(STDERR, "!! discoverable tomorrow.\n");
    fwrite(STDERR, str_repeat('!', 72) . "\n\n");
} else {
    $testRunner = $root . '/bin/test.php';
    if (!is_file($testRunner)) {
        fwrite(STDERR, "bin/test.php is missing, so the tree cannot be proven. Refusing to build.\n");
        fwrite(STDERR, "Use --skip-tests only if you accept an unproven release.\n");
        exit(EXIT_FAILED);
    }

    echo "tests  : running bin/test.php (use --skip-tests to build without it)\n";
    echo str_repeat('-', 72) . "\n";

    // PHP_BINARY, not "php": the machine that builds this is as likely to have
    // three PHP versions on its PATH as one, and the tree has to be proven by
    // the interpreter running this script - otherwise the suite passes on 8.4
    // and the release is cut from code that does not parse on the host's 8.2.
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($testRunner);

    // Streamed, not captured. A suite takes a while and a build that shows a
    // blank terminal for a minute is a build people ctrl-C. passthru() also
    // keeps the runner's own colours and its failure detail, which is the part
    // somebody has to read when this refuses.
    $status = null;
    passthru($command, $status);
    $status = (int) $status;

    echo str_repeat('-', 72) . "\n";

    if ($status !== 0) {
        fwrite(STDERR, "\nTHE TEST SUITE DID NOT PASS (bin/test.php exited $status). NOTHING WAS BUILT.\n\n");
        // The runner's own exit codes, so the operator is told what to do rather
        // than only that something went wrong. 2 and 3 are setup problems on this
        // machine, not defects in the tree, and they have different cures.
        if ($status === 2) {
            fwrite(STDERR, "Exit 2 is a refused --database name, which this script never passes.\n");
        } elseif ($status === 3) {
            fwrite(STDERR, "Exit 3 means the test database is reachable but has no schema.\n");
            fwrite(STDERR, "Run bin/migrate.php against it, then build again.\n");
        } else {
            fwrite(STDERR, "Fix the failures above, or accept an unproven release with --skip-tests.\n");
        }
        exit(EXIT_FAILED);
    }

    $testsRan = true;
    echo "tests  : PASS\n\n";
}

// ---------------------------------------------------------------------------
// The deny list. Checked twice: once while collecting, once on the finished zip.
// ---------------------------------------------------------------------------

/**
 * The ONLY dotfiles allowed into the release. Everything else beginning with a
 * dot is refused, which is what keeps .env, .env.local, .git*, .npmrc and an
 * editor's droppings out without having to name them all.
 */
const ALLOWED_DOTFILES = ['.htaccess', '.user.ini'];

/** Directory names that are never shipped, wherever they appear. */
const DENIED_DIRS = [
    '.git', '.github', '.gitlab', '.idea', '.vscode', '.claude',
    'node_modules', 'tests', 'test', '__tests__', 'docs',
    'coverage', 'dumps', 'deploy', 'tools', 'local',
];

/**
 * A Composer vendor tree at the archive root is never shipped - but this
 * project has no Composer, and app/Vendor/ is OUR hand-written code (the SMTP
 * client the site sends mail with). "vendor" was in DENIED_DIRS as a directory
 * NAME, which refused all seven of those files and would have shipped an
 * application that cannot send a single email. The rule is a path, not a name.
 */
const DENIED_ROOT_DIRS = ['vendor'];

/**
 * Why this zip entry must not exist, or null if it is fine.
 *
 * It takes the path INSIDE the zip, so it can be run against a finished archive
 * without touching the filesystem. That is the whole point: the final check
 * reads the zip, not the plan.
 *
 * EVERY NAME RULE BELOW IS CASE-INSENSITIVE, and that is not tidiness. The
 * rules used to compare exact lower-case strings while the deployment host is
 * Linux, where `Config.PHP` and `config.php` are two different files. A tree
 * was copied, `config/config.php` was filled with canary secrets, three crafted
 * filenames were planted, and the builder said:
 *
 *     Verified: opens, every entry matches its source, and nothing on the deny
 *     list is inside it.
 *
 * The finished archive then contained `app/Core/Settings.LOCAL.php` - a file
 * whose entire content was the production configuration, under a name this very
 * rule was written to catch (audit round 1, finding 7). `config/config.php` was
 * caught, because that is the one spelling the rule knew.
 *
 * And a name rule is only ever the first lock: nothing stops somebody calling
 * the file `Helpers.php`. That is what the CONTENT check at the end of this
 * script is for - it compares every shipped byte against the literal values in
 * config/config.php, so a rename, a copy or a symlink cannot get a secret out.
 */
function refusalReason(string $entry): ?string
{
    $entry    = ltrim(str_replace('\\', '/', $entry), '/');
    $segments = explode('/', $entry);
    $base     = $segments[count($segments) - 1];

    // Directory entries end in '/' and have an empty last segment.
    if ($base === '') {
        $base = $segments[count($segments) - 2] ?? '';
    }

    // Folded once, here, and used by every name rule below. mb_strtolower
    // because a filename is bytes and a Turkish "İ" folds to something
    // strtolower() does not touch - which is one more spelling of the same hole
    // this finding is about. The extension is folded the same way.
    $lower = function_exists('mb_strtolower') ? mb_strtolower($base, 'UTF-8') : strtolower($base);

    if (in_array(strtolower($segments[0] ?? ''), DENIED_ROOT_DIRS, true)) {
        return "sits in a root directory that is never shipped (\"{$segments[0]}\")";
    }

    foreach ($segments as $segment) {
        if ($segment !== '' && in_array(strtolower($segment), DENIED_DIRS, true)) {
            return "sits inside a directory that is never shipped (\"$segment\")";
        }
        // A dotfile anywhere in the path, not only at the end: a file inside
        // .git/ or .well-known-ish directory is caught here too.
        if ($segment !== '' && $segment[0] === '.' && !in_array($segment, ALLOWED_DOTFILES, true)) {
            return "is or is inside a dotfile (\"$segment\")";
        }
    }

    // The one file this whole script exists to keep out - in every spelling,
    // because `Settings.LOCAL.php` is the one that got through.
    //
    // `*.local.php` is refused ANYWHERE: the `.local.` infix is a configuration
    // convention and no class in this codebase is named that way.
    //
    // `config.php` is refused only where real configuration lives - the `config/`
    // directory and the archive root. NOT everywhere, and that scope is the
    // whole lesson of this edit: folding the case of a rule that matched
    // `config.php` anywhere immediately refused `app/Core/Config.php`, the
    // application's own configuration READER, and `app/Core/Router.php` went the
    // same way to the dev-router rule below. Both builds still "succeeded". A
    // name rule that can delete source code is worse than the hole it closes -
    // this script says so about the credential heuristic a few lines down, and it
    // is just as true here. A copy of the real configuration hidden under an
    // innocent name somewhere else is caught by the CONTENT check instead, which
    // is what closes the class of bug rather than the instance.
    if (str_ends_with($lower, '.local.php')) {
        return 'is real configuration (secrets), not the sample';
    }

    // `config.php` in any spelling, ANYWHERE EXCEPT app/. app/ is the PSR-4
    // class tree - `app/Core/Config.php` is the class that READS the
    // configuration and holds none of it - and no file under app/ is a
    // configuration file. Everywhere else, a file called `config.php` is the one
    // this script exists to keep out, however it is capitalised.
    if ($lower === 'config.php' && strtolower($segments[0] ?? '') !== 'app') {
        return 'is real configuration (secrets), not the sample';
    }

    // DELIBERATELY NOT case-folded, unlike every rule around it. A stray test
    // outside tests/ is noise, not a secret - the tests DIRECTORY is already
    // refused by DENIED_DIRS in any case - whereas `/test\.php$/i` also refuses
    // `Latest.php`, and this script's own rule is that a name heuristic which
    // can delete source code is worse than the hole it closes. `phpunit*` is
    // folded, because no source file is called that in any spelling.
    if (preg_match('/Test\.php$/', $base) === 1 || str_starts_with($lower, 'phpunit')) {
        return 'is a test';
    }

    // Editor and merge droppings. These have no extension the list below knows
    // about - `pathinfo('index.php~')` reports the extension as "php~" - and they
    // do not start with a dot either, so nothing refused them and `index.php~`
    // shipped into the web root as readable source of a file PHP executes.
    // public/.htaccess carries the same rule for the same names (finding 17).
    if (preg_match('/(~|\.(save|rej|tmp|temp|old|copy|orig|swp|swo))$/i', $base) === 1) {
        return 'is an editor or merge dropping';
    }

    $extension = function_exists('mb_strtolower')
        ? mb_strtolower((string) pathinfo($base, PATHINFO_EXTENSION), 'UTF-8')
        : strtolower((string) pathinfo($base, PATHINFO_EXTENSION));

    // Files NAMED like a credential store: firebase-adminsdk-xxxx.json,
    // service-account.json, secrets.yml, credentials.txt.
    //
    // Deliberately NOT applied to .php source. The first build of this release
    // refused app/Domain/Auth/SecretBox.php - a class whose job is to hold no
    // secret at all - and would have shipped an application that fatals on its
    // first admin login. A name heuristic that can delete source code is worse
    // than the hole it closes, so .php gets the precise rules below instead.
    // ... and NOT applied to the schema either. 0002_session_csrf_secret.sql is
    // a migration whose name contains the word "secret" because it creates the
    // column that holds one; refusing it shipped a database missing a table the
    // application needs on its first request. db/migrations is allow-listed by
    // the precise .sql rule below, so the heuristic has no business there.
    if ($extension !== 'php'
        && !str_starts_with($entry, 'db/migrations/')
        && preg_match('/(service.?account|adminsdk|credential|secret|private.?key)/i', $base) === 1) {
        return 'is named like a credential file';
    }

    // The precise rule for .php: config/ ships exactly one file, the sample.
    // Anything else in there is somebody's real configuration under a new name.
    //
    // The DIRECTORY is matched case-insensitively (a tree copied through a
    // case-insensitive filesystem can arrive as `Config/`), and the allowed name
    // is matched EXACTLY: `Config.Sample.PHP` is not the file this script vetted,
    // so strictness here is the safe direction.
    if (strtolower($segments[0] ?? '') === 'config' && $extension === 'php' && $base !== 'config.sample.php') {
        return 'is a .php file in config/ that is not config.sample.php';
    }

    // .sql is allowed, but ONLY the schema under db/migrations. A .sql anywhere
    // else is a data dump, and a data dump of this site is the customer list.
    if (str_ends_with($lower, '.sql') && !str_starts_with($entry, 'db/migrations/')) {
        return 'is a .sql file outside db/migrations (a data dump?)';
    }

    // ... and being IN db/migrations is not enough either. The old rule allowed
    // the whole directory, so `db/migrations/9999_customer_dump.sql` - whose
    // content was the config file - shipped in an archive both checkers called
    // clean (audit round 1, finding 12). db/migrations is exactly where a
    // developer debugging a migration saves a dump, and the comment above states
    // the stake: a data dump of this site is the customer list.
    //
    // So the rule is the NAMING PATTERN, not the directory: four or more digits,
    // an underscore, lower-case words, .sql - which is what every migration in
    // the repository is called and what bin/migrate.php orders by. `.htaccess`
    // is the one other thing that belongs there (the deny-all guard), and it is
    // already vetted by the dotfile rule above.
    if (str_starts_with($entry, 'db/migrations/')
        && !in_array($base, ALLOWED_DOTFILES, true)
        && preg_match('/^\d{4,}_[a-z0-9_]+\.sql$/', $base) !== 1) {
        return 'is a file in db/migrations/ that is not a numbered migration';
    }

    // The naming pattern is NOT enough on its own, and the file the audit
    // actually shipped proves it: `9999_customer_dump.sql` matches
    // /^\d{4,}_[a-z0-9_]+\.sql$/ perfectly. A dump saved with a migration-shaped
    // name gets past every name rule there can be, so the remaining check has to
    // look at the bytes - see looksLikeADataDump(), called from the collector and
    // again on the finished archive.

    // A development harness under the web root. This site serves no .html of
    // its own - every page is rendered by index.php - so an .html inside
    // public_html is by definition something a developer left behind. The one
    // that exists today (assets/dev-preview.html) carries an inline <script>
    // the site's own Content-Security-Policy would refuse, and it is reachable
    // by anyone who guesses the path.
    if ($extension === 'html' && str_starts_with($entry, 'public_html/')) {
        return 'is an .html file under the web root (a development harness)';
    }

    $denied = [
        // .json is on the list because NOTHING this site serves is a .json
        // file - the API answers are generated by index.php and have no
        // extension - while the things that arrive as .json are Firebase
        // service accounts and composer manifests. If a web manifest is ever
        // needed it is site.webmanifest, which is its correct name anyway.
        'json', 'jsonc',
        'env', 'log', 'md', 'markdown', 'ts', 'tsx', 'mjs', 'cjs', 'map',
        'lock', 'yml', 'yaml', 'sh', 'bash', 'ps1', 'bat', 'cmd',
        'pem', 'key', 'p12', 'pfx', 'crt', 'csr', 'ppk',
        'gz', 'tgz', 'zip', 'rar', '7z', 'bak', 'orig', 'swp', 'swo', 'dist',
        'sqlite', 'sqlite3', 'db',
    ];
    if (in_array($extension, $denied, true)) {
        return "has the extension .$extension, which is never shipped";
    }

    // The PHP built-in server's router. Useless and confusing under LiteSpeed.
    //
    // Scoped to the web root, where the dev router is the only thing by that
    // name - NOT the whole tree, or `app/Core/Router.php` is refused as "the
    // dev-server router" and the release ships an application with no router in
    // it. The basename is folded so `public_html/Router.php` is caught too.
    if ($lower === 'router.php' && strtolower($segments[0] ?? '') === 'public_html') {
        return 'is the dev-server router';
    }

    return null;
}

/**
 * Does this .sql read as a data dump rather than a schema migration?
 *
 * WHY A CONTENT CHECK FOR THIS TOO. The naming rule above refuses `dump.sql` and
 * `backup-2026-09-27.sql`, and does nothing at all about
 * `9999_customer_dump.sql` - which is the exact file the audit shipped, and which
 * matches the migration pattern character for character. No name rule can tell a
 * migration from a dump that was named like one. The bytes can.
 *
 * Three signals, in increasing order of how much they have to read:
 *
 *   1. SIZE. This project's largest migration is 18 KB. A dump of this site
 *      starts in the hundreds of KB, because it is the customer list.
 *   2. A DUMP BANNER. mysqldump and phpMyAdmin both announce themselves in the
 *      first few lines, and a developer producing a dump by hand from hPanel gets
 *      one of those two.
 *   3. VOLUME OF INSERTs. A migration may legitimately seed a few rows - the
 *      threshold is twenty, which no migration in this repository comes close to
 *      (0001_init.sql's only "INSERT INTO" is inside a comment) and which a dump
 *      passes in its first table.
 *
 * Returns the reason, or null when the file reads like a migration.
 */
function looksLikeADataDump(string $body): ?string
{
    if (strlen($body) > 512 * 1024) {
        return 'is far larger than any schema migration (' . kb(strlen($body)) . ') - a data dump?';
    }

    if (preg_match('/(mysqldump|MySQL dump|MariaDB dump|phpMyAdmin SQL Dump)/i', $body) === 1) {
        return 'carries a database-dump banner, so it is a dump and not a migration';
    }

    $inserts = preg_match_all('/\bINSERT\s+INTO\b/i', $body);
    if ($inserts > 20) {
        return 'is ' . $inserts . ' INSERT statements, which is a data dump and not a migration';
    }

    return null;
}

/**
 * The literal secret values in config/config.php, so no shipped byte may contain
 * one.
 *
 * WHY A CONTENT CHECK EXISTS AT ALL. Every rule above is a NAME rule, and a name
 * rule closes an instance, never the class: `config/config.php` was caught while
 * `app/Core/Settings.LOCAL.php` and `app/Core/Overrides.php` - both containing
 * the whole production configuration - were not (audit round 1, finding 7). No
 * name rule ever will catch `Helpers.php`. Comparing against the real values
 * costs one str_contains per file and cannot be fooled by a rename, a copy or a
 * symlink, because it does not look at the name.
 *
 * The file is `require`d rather than parsed. It is this project's own
 * configuration, it returns an array, and a regex over it would miss a value
 * written as a concatenation or read from an environment variable - which is
 * exactly the kind of value somebody would be clever with.
 *
 * ONLY values whose KEY says secret, and only ones long enough to be one. A
 * short or common value would flag a source file for containing the word, and a
 * build that cries wolf is a build people stop reading.
 *
 * @return list<string>
 */
function liveSecretLiterals(string $root): array
{
    $file = $root . '/config/config.php';
    if (!is_file($file)) {
        return [];      // a clean checkout has none; nothing to compare against
    }

    try {
        /** @psalm-suppress UnresolvableInclude */
        $config = require $file;
    } catch (Throwable $e) {
        fwrite(STDERR, "Cannot read config/config.php to check the archive against it: " . $e->getMessage() . "\n");
        exit(EXIT_FAILED);
    }

    if (!is_array($config)) {
        return [];
    }

    $secrets = [];
    $walk    = static function (array $node, string $path) use (&$walk, &$secrets): void {
        foreach ($node as $key => $value) {
            $here = $path === '' ? (string) $key : $path . '.' . $key;

            if (is_array($value)) {
                $walk($value, $here);
                continue;
            }

            if (!is_string($value) || strlen($value) <= 8) {
                continue;
            }

            if (preg_match('/(key|pass|secret|token|credential)/i', $here) === 1) {
                $secrets[] = $value;
            }
        }
    };

    $walk($config, '');

    return array_values(array_unique($secrets));
}

// ---------------------------------------------------------------------------
// Collect
// ---------------------------------------------------------------------------

/** @var array<string,string> $files  zip path => absolute source path */
$files = [];
/** @var list<string> $emptyDirs */
$emptyDirs = [];
/** @var array<string,string> $generated  zip path => literal contents */
$generated = [];
/** @var list<string> $skipped */
$skipped = [];
/** @var list<string> $notes */
$notes = [];

/**
 * Add every file under $sourceDir at $prefix inside the zip, refusing whatever
 * the deny list refuses.
 *
 * @param list<string> $skipTop top-level names under $sourceDir to leave out
 */
function addTree(string $sourceDir, string $prefix, array $skipTop = []): void
{
    global $files, $skipped;

    if (!is_dir($sourceDir)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $item) {
        /** @var SplFileInfo $item */
        $absolute = str_replace('\\', '/', $item->getPathname());
        $relative = ltrim(substr($absolute, strlen($sourceDir)), '/');

        if ($relative === '') {
            continue;
        }

        $top = explode('/', $relative)[0];
        if (in_array($top, $skipTop, true)) {
            continue;
        }

        $entry = $prefix === '' ? $relative : $prefix . '/' . $relative;

        // A SYMLINK IS NEVER FOLLOWED. isFile() is true for a link to a file, so
        // addFile() read through it and the CRC check compared the entry against
        // the same link - which passes. A link named like ordinary source
        // therefore ships the file it points at, config/config.php included,
        // and every name rule in refusalReason() looks at the wrong name
        // (audit round 1, finding 7, "Related, same file").
        if ($item->isLink()) {
            $skipped[] = sprintf('%-52s %s', $entry, 'is a symbolic link');
            continue;
        }

        if (!$item->isFile()) {
            continue;
        }

        // And the belt: the resolved path is really inside the directory being
        // walked. A junction, a hard link or a mount that the check above does
        // not recognise still cannot reach outside the tree.
        $real = realpath($absolute);
        $real = $real === false ? '' : str_replace('\\', '/', $real);
        if ($real === '' || !str_starts_with($real . '/', rtrim($sourceDir, '/') . '/')) {
            $skipped[] = sprintf('%-52s %s', $entry, 'resolves outside ' . $sourceDir);
            continue;
        }

        $reason = refusalReason($entry);

        if ($reason !== null) {
            $skipped[] = sprintf('%-52s %s', $entry, $reason);
            continue;
        }

        if (!$item->isReadable()) {
            $skipped[] = sprintf('%-52s is not readable', $entry);
            continue;
        }

        $files[$entry] = $absolute;
    }
}

// --- the web root ----------------------------------------------------------
// The CONTENTS of php/public become public_html. Not a "public" folder inside
// it: the front controller resolves everything from dirname(__DIR__), so a
// nested folder puts app/ one level too deep and the site 503s with
// "not configured".
addTree($root . '/public', 'public_html');

// --- application code ------------------------------------------------------
addTree($root . '/app', 'app');

// --- the cron entry point and the migration runner -------------------------
// bin/local/ is a development toolbox (it starts a database and runs a web
// server). This script and the Postgres exporter run on a laptop, never here.
addTree($root . '/bin', 'bin', ['local']);
foreach (['bin/build-release.php', 'bin/export-from-nextjs.php', 'bin/test.php'] as $developmentTool) {
    if (isset($files[$developmentTool])) {
        unset($files[$developmentTool]);
        $skipped[] = sprintf('%-52s %s', $developmentTool, 'is a development tool');
    }
}

// --- configuration: the SAMPLE only ----------------------------------------
$files['config/config.sample.php'] = $root . '/config/config.sample.php';

// --- the schema ------------------------------------------------------------
// Through refusalReason() like everything else. This loop used to add whatever
// the glob found without consulting the deny list at all, which is how
// `9999_customer_dump.sql` shipped (finding 12).
foreach (glob($root . '/db/migrations/*.sql') ?: [] as $migration) {
    $entry  = 'db/migrations/' . basename($migration);
    $reason = refusalReason($entry) ?? looksLikeADataDump((string) file_get_contents($migration));

    if ($reason !== null) {
        $skipped[] = sprintf('%-52s %s', $entry, $reason);
        continue;
    }

    $files[$entry] = str_replace('\\', '/', $migration);
}

// --- writable directories, empty --------------------------------------------
// Shipped as EMPTY directories with their guards. The source tree's storage/
// has real logs in it by now; a release that carries last Tuesday's log file to
// production is a release that carries last Tuesday's error messages with it.
$emptyDirs[] = 'storage';
foreach (['cache', 'logs', 'tmp', 'uploads'] as $writable) {
    $emptyDirs[] = "storage/$writable";
}
$emptyDirs[] = 'var';

// --- the guards -------------------------------------------------------------
// Every non-public directory gets a deny-all .htaccess. Where the source tree
// already ships one (phase 1 wrote app/, config/, db/, storage/ and var/) that
// file is used; the rest are filled in from deploy/htaccess/deny-all.htaccess.
$denyAllSource = $root . '/deploy/htaccess/deny-all.htaccess';
if (!is_file($denyAllSource)) {
    fwrite(STDERR, "Missing $denyAllSource - the deny-all guard has no source.\n");
    exit(EXIT_FAILED);
}
$denyAll = (string) file_get_contents($denyAllSource);

$guarded = ['app', 'bin', 'config', 'db', 'db/migrations', 'storage',
    'storage/cache', 'storage/logs', 'storage/tmp', 'storage/uploads', 'var'];

foreach ($guarded as $directory) {
    $entry = $directory . '/.htaccess';
    if (isset($files[$entry])) {
        continue;                                  // the source tree ships one
    }
    $ownCopy = $root . '/' . $entry;
    if (is_file($ownCopy)) {
        $files[$entry] = str_replace('\\', '/', $ownCopy);
        continue;
    }
    $generated[$entry] = $denyAll;
}

// The web root's .htaccess. php/public/.htaccess is the file the application is
// developed against, so that is the one that ships; deploy/htaccess/ holds the
// annotated copy for pasting into File Manager later. If the app's copy is
// missing, fall back loudly rather than shipping a web root with no rewrite
// and no deny rules - that is a site that serves its own source.
if (isset($files['public_html/.htaccess'])) {
    $notes[] = 'public_html/.htaccess  <- php/public/.htaccess';
} elseif (is_file($root . '/deploy/htaccess/public_html.htaccess')) {
    $files['public_html/.htaccess'] = $root . '/deploy/htaccess/public_html.htaccess';
    $notes[] = 'public_html/.htaccess  <- deploy/htaccess/public_html.htaccess  (!! php/public/.htaccess is MISSING)';
} else {
    fwrite(STDERR, "No .htaccess for the web root, in php/public/ or in deploy/htaccess/. Refusing to build.\n");
    exit(EXIT_FAILED);
}

// The blank directory guards the source tree ships (a server that somehow
// serves one of these directories serves an empty page rather than a listing).
foreach (['storage', 'storage/cache', 'storage/logs', 'storage/tmp', 'storage/uploads'] as $directory) {
    $guard = $root . '/' . $directory . '/index.html';
    if (is_file($guard)) {
        $files[$directory . '/index.html'] = str_replace('\\', '/', $guard);
    }
}

// --- the application's bundled public/ ---------------------------------------
// The same files as public_html, again, at speedat_main/php/public - the folder
// Paths::publicDir() names (see "WHY public/ IS SHIPPED TWICE" at the top).
// Mirrored from what public_html ACCEPTED rather than walked a second time, so
// the two copies cannot disagree and nothing the deny list refused for the web
// root slips in under the other name. Done after the .htaccess fallback above,
// so the copy carries whichever web-root .htaccess actually ships.
foreach ($files as $entry => $absolute) {
    if (str_starts_with($entry, 'public_html/')) {
        $files['public/' . substr($entry, strlen('public_html/'))] = $absolute;
    }
}

// --- INSTALL.txt -------------------------------------------------------------
$generated['INSTALL.txt'] = installText($zipName, gmdate('Y-m-d H:i:s') . ' UTC', $testsRan);

// ---------------------------------------------------------------------------
// Nothing may be missing
// ---------------------------------------------------------------------------

$required = [
    'public_html/index.php',
    'public_html/.htaccess',
    // BOTH, always. A host reads .htaccess (mod_php) or .user.ini (LiteSpeed,
    // PHP-FPM) and never says which; shipping one of the pair means the body
    // limits are whatever the host's defaults happen to be - on Hostinger,
    // post_max_size 128M at a form that takes no file at all. The dotfile rule
    // in refusalReason() allows exactly these two names, so a typo in either
    // filename lands here as a refused build rather than as a silent default.
    'public_html/.user.ini',
    'app/Core/Bootstrap.php',
    'app/routes.php',
    // The two files that make the mirror above real: the copy Paths::publicDir()
    // reads, and the sprite that is inlined from it on every page.
    'public/index.php',
    'public/assets/img/icons.svg',
    'bin/cron.php',
    'config/config.sample.php',
    'config/.htaccess',
    'db/migrations/0001_init.sql',
    'INSTALL.txt',
];

/*
 * THE CORE CLASSES WHOSE NAMES LOOK LIKE SOMETHING THIS SCRIPT REFUSES.
 *
 * This list exists because of a mistake worth remembering. Making the name rules
 * case-insensitive (finding 7) immediately refused `app/Core/Config.php` as "real
 * configuration" and `app/Core/Router.php` as "the dev-server router" - the
 * application's own configuration READER and its own router - and the build
 * reported success both times, because nothing required them. An archive missing
 * two Core classes is an application that fatals on its first request, and it
 * would have been found on the host rather than here.
 *
 * Every name below collides with a rule in refusalReason(): config, router,
 * session, secret-ish, test-ish. Requiring them turns "a name heuristic ate a
 * class" from a silent success into a refused build. The whole-directory version
 * of this invariant - every .php under app/Core ships - lives in
 * tests/Core/BuildReleaseTest.php, where it can be asserted against a tree with
 * nothing planted in it.
 *
 * If one of these is ever renamed the build fails and names the file, which is a
 * one-line edit here and the correct outcome.
 */
foreach ([
    'app/Core/Autoloader.php',
    'app/Core/Config.php',
    'app/Core/Db.php',
    'app/Core/Paths.php',
    'app/Core/Request.php',
    'app/Core/Response.php',
    'app/Core/Router.php',
    'app/Core/Session.php',
    'app/Core/Csrf.php',
    'app/Core/View.php',
    'app/Core/helpers.php',
] as $atRisk) {
    $required[] = $atRisk;
}
$required = array_values(array_unique($required));

$missing = [];
foreach ($required as $entry) {
    if (!isset($files[$entry]) && !isset($generated[$entry])) {
        $missing[] = $entry;
    }
}
if ($missing !== []) {
    fwrite(STDERR, "Refusing to build - the release would be missing:\n");
    foreach ($missing as $entry) {
        fwrite(STDERR, "  * $entry\n");
    }
    exit(EXIT_FAILED);
}

// ---------------------------------------------------------------------------
// Write
// ---------------------------------------------------------------------------

if (!is_dir($outDir) && !@mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Cannot create $outDir\n");
    exit(EXIT_FAILED);
}

$zipPath = $outDir . '/' . $zipName;
if (is_file($zipPath) && !@unlink($zipPath)) {
    fwrite(STDERR, "Cannot replace the existing $zipPath\n");
    exit(EXIT_FAILED);
}

$zip    = new ZipArchive();
$opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL);
if ($opened !== true) {
    fwrite(STDERR, "Cannot open $zipPath for writing (ZipArchive error $opened)\n");
    exit(EXIT_FAILED);
}

ksort($files);       // deterministic order: two builds of the same tree match
ksort($generated);
sort($emptyDirs);

// The ONLY place the manifest's own paths become archive paths (APP_PREFIX).
foreach ($emptyDirs as $directory) {
    $zip->addEmptyDir(archivePath($directory));
}

$uncompressed = 0;
foreach ($files as $entry => $absolute) {
    if (!$zip->addFile($absolute, archivePath($entry))) {
        $zip->close();
        @unlink($zipPath);
        fwrite(STDERR, "Cannot add $entry\n");
        exit(EXIT_FAILED);
    }
    $uncompressed += (int) filesize($absolute);
}

foreach ($generated as $entry => $contents) {
    if (!$zip->addFromString(archivePath($entry), $contents)) {
        $zip->close();
        @unlink($zipPath);
        fwrite(STDERR, "Cannot add generated $entry\n");
        exit(EXIT_FAILED);
    }
    $uncompressed += strlen($contents);
}

$zip->setArchiveComment(
    "Speedat release $zipName\n"
    . 'Built ' . gmdate('Y-m-d H:i:s') . " UTC by bin/build-release.php\n"
    . ($testsRan ? "Tests: PASSED at build time.\n" : "Tests: NOT RUN (--skip-tests). UNPROVEN TREE.\n")
    . "Extract at ~/domains/<site>/ - NOT inside public_html. The app lands in speedat_main/php/.\n"
);

if (!$zip->close()) {
    @unlink($zipPath);
    fwrite(STDERR, "Writing the zip failed on close.\n");
    exit(EXIT_FAILED);
}

// ---------------------------------------------------------------------------
// Verify the finished archive. This is the check that counts.
// ---------------------------------------------------------------------------

$check = new ZipArchive();
if ($check->open($zipPath, ZipArchive::CHECKCONS) !== true) {
    @unlink($zipPath);
    fwrite(STDERR, "The zip that was just written will not open. It has been deleted.\n");
    exit(EXIT_FAILED);
}

$secretLiterals = liveSecretLiterals($root);

$problems  = [];
$fileCount = 0;
$dirCount  = 0;
/** @var array<string,int> $byTop */
$byTop = [];
/** @var list<array{string,int}> $sizes */
$sizes = [];

for ($index = 0; $index < $check->numFiles; $index++) {
    $stat = $check->statIndex($index);
    if ($stat === false) {
        $problems[] = "entry #$index cannot be read back";
        continue;
    }

    $entry = $stat['name'];

    // Back to the manifest's own path, so every rule below reads the name it
    // was written against. An entry that maps to nothing is either one of the
    // two parent folders of the prefix (ZipArchive may or may not write those)
    // or something outside the layout - and the second is a refusal: an `app/`
    // beside public_html is exactly the dead tree this layout exists to stop.
    $key = keyOf($entry);
    if ($key === null) {
        if (in_array($entry, ['speedat_main/', APP_PREFIX . '/'], true)) {
            $dirCount++;
        } else {
            $problems[] = "$entry sits outside public_html/ and " . APP_PREFIX . '/, where nothing on the host runs it';
        }
        continue;
    }

    if (str_ends_with($entry, '/')) {
        $dirCount++;
    } else {
        $fileCount++;
        $sizes[] = [$entry, (int) $stat['size']];

        // THE CONTENT CHECKS. Read out of the finished archive, so they see what
        // a host would extract - not what the collector meant to put there. These
        // are what catch the files that got past every name rule: a copy of the
        // configuration called something innocent, and a data dump named like a
        // migration.
        $body = $check->getFromIndex($index);

        if (is_string($body)) {
            foreach ($secretLiterals as $secret) {
                if (str_contains($body, $secret)) {
                    // The value is never printed - a build log is a thing people
                    // paste into chat.
                    $problems[] = "$entry CONTAINS A VALUE FROM config/config.php";
                    break;
                }
            }

            if (str_starts_with($key, 'db/migrations/') && str_ends_with(strtolower($key), '.sql')) {
                $dump = looksLikeADataDump($body);
                if ($dump !== null) {
                    $problems[] = "$entry $dump";
                }
            }
        }
    }

    // THE deny-list check: on what is really in the archive, not on the plan.
    // The bundled public/ copy is judged as the web root it mirrors, so the
    // web-root-only rules (no .html harness, no dev router) hold for it too.
    $reason = refusalReason(str_starts_with($key, 'public/') ? 'public_html/' . substr($key, 7) : $key);
    if ($reason !== null) {
        $problems[] = "$entry $reason";
    }

    // Absolute paths and .. escape the extraction directory. ZipArchive would
    // not have written one, but a release is worth one belt.
    if (str_starts_with($entry, '/') || str_contains($entry, '..')) {
        $problems[] = "$entry is not a safe relative path";
    }

    // The bytes really are the bytes of the source. Comparing CRCs rather than
    // sizes catches a truncated or half-flushed write, which is the failure a
    // "the zip looks about right" eyeball never catches.
    if (isset($files[$key])) {
        if (sprintf('%08x', (int) $stat['crc']) !== hash_file('crc32b', $files[$key])) {
            $problems[] = "$entry does not match its source file (crc)";
        }
    } elseif (isset($generated[$key])) {
        if (sprintf('%08x', (int) $stat['crc']) !== hash('crc32b', $generated[$key])) {
            $problems[] = "$entry does not match what was generated for it (crc)";
        }
    }
}

// Read two entries back out, so "the archive is readable" is demonstrated and
// not assumed.
foreach (['INSTALL.txt', 'config/config.sample.php'] as $entry) {
    $contents = $check->getFromName(archivePath($entry));
    if ($contents === false || $contents === '') {
        $problems[] = archivePath($entry) . ' cannot be read back out of the archive';
    }
}

foreach ($required as $entry) {
    if ($check->locateName(archivePath($entry)) === false) {
        $problems[] = archivePath($entry) . ' is missing from the archive';
    }
}

// The named things that must NOT be there, spelled out so the check is legible
// in the output even when the generic deny list would already have caught them.
// Both spellings of each: where it would land in this layout, and bare at the
// archive root, where the old layout would have put it.
foreach (['config/config.php', '.env', 'public_html/.env', 'public_html/router.php', 'public/router.php'] as $entry) {
    foreach (array_unique([archivePath($entry), $entry]) as $spelling) {
        if ($check->locateName($spelling) !== false) {
            $problems[] = "$spelling IS IN THE ARCHIVE";
        }
    }
}

$check->close();

if ($problems !== []) {
    @unlink($zipPath);
    fwrite(STDERR, "\nTHE ARCHIVE FAILED ITS OWN CHECKS AND HAS BEEN DELETED:\n\n");
    foreach ($problems as $problem) {
        fwrite(STDERR, "  * $problem\n");
    }
    exit(EXIT_FAILED);
}

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------

// array_merge, not `+`: on two lists `+` unions by numeric index and silently
// throws away the shorter one's tail.
foreach (array_merge(array_keys($files), array_keys($generated)) as $entry) {
    $top         = explode('/', $entry)[0];
    $byTop[$top] = ($byTop[$top] ?? 0) + 1;
}
ksort($byTop);

if ($verbose) {
    // The archive's own names, prefix included: this listing is what somebody
    // compares against File Manager after an extract.
    foreach (array_keys($files) as $entry) {
        echo '  ' . archivePath($entry) . "\n";
    }
    foreach (array_keys($generated) as $entry) {
        echo '  ' . archivePath($entry) . "  (generated)\n";
    }
    echo "\n";
}

foreach ($notes as $note) {
    echo "htaccess    $note\n";
}
echo "\n";

echo "contents\n";
foreach ($byTop as $top => $count) {
    echo '  ' . str_pad($top, 16) . str_pad((string) $count, 5, ' ', STR_PAD_LEFT) . " file(s)\n";
}
echo '  ' . str_pad('(empty dirs)', 16) . str_pad((string) count($emptyDirs), 5, ' ', STR_PAD_LEFT) . "\n";

usort($sizes, static fn (array $a, array $b): int => $b[1] <=> $a[1]);
echo "\nlargest entries\n";
foreach (array_slice($sizes, 0, 5) as [$entry, $size]) {
    echo '  ' . str_pad(kb($size), 10, ' ', STR_PAD_LEFT) . '  ' . $entry . "\n";
}

if ($skipped !== []) {
    echo "\nrefused (" . count($skipped) . ")\n";
    sort($skipped);
    foreach (array_slice($skipped, 0, 25) as $line) {
        echo '  ' . $line . "\n";
    }
    if (count($skipped) > 25) {
        echo '  ... and ' . (count($skipped) - 25) . " more\n";
    }
}

$zipBytes = (int) filesize($zipPath);

echo "\n" . str_repeat('-', 72) . "\n";
echo 'files        : ' . $fileCount . "\n";
echo 'directories  : ' . $dirCount . "\n";
echo 'uncompressed : ' . kb($uncompressed) . "\n";
echo 'zip          : ' . kb($zipBytes) . '  ('
    . ($uncompressed > 0 ? round(100 - $zipBytes / $uncompressed * 100) : 0) . "% smaller)\n";
echo 'sha256       : ' . hash_file('sha256', $zipPath) . "\n";
echo 'path         : ' . $zipPath . "\n";
echo str_repeat('-', 72) . "\n";
echo "\nVerified: opens, every entry matches its source, and nothing on the deny\n";
echo "list is inside it. Next stop is docs/deploy-hostinger.md.\n";

exit(EXIT_OK);

// ===========================================================================

function kb(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return number_format($bytes / 1024, 1) . ' KiB';
    }

    return number_format($bytes / 1024 / 1024, 2) . ' MiB';
}

/**
 * The manifest's own path -> the path inside the archive.
 *
 * public_html/ and INSTALL.txt sit at the top, beside each other; everything
 * else is the application and goes under APP_PREFIX - `public/` included, which
 * is the bundled copy, not the web root.
 */
function archivePath(string $key): string
{
    if ($key === 'INSTALL.txt' || $key === 'public_html' || str_starts_with($key, 'public_html/')) {
        return $key;
    }

    return APP_PREFIX . '/' . $key;
}

/**
 * The inverse of archivePath(): an archive entry -> the manifest's own path, or
 * null when the entry is not inside the layout at all (or is one of the
 * prefix's own parent folders, which the caller tells apart).
 */
function keyOf(string $entry): ?string
{
    if ($entry === 'INSTALL.txt' || str_starts_with($entry, 'public_html/')) {
        return $entry;
    }

    if (str_starts_with($entry, APP_PREFIX . '/')) {
        $rest = substr($entry, strlen(APP_PREFIX) + 1);

        return $rest === '' ? null : $rest;
    }

    return null;
}

function installText(string $zipName, string $builtAt, bool $testsRan): string
{
    // Stated in the archive, not only in the terminal. A zip outlives the shell
    // it was built in, and "was the suite green when this was cut?" is a
    // question somebody asks a week later with only the file in hand.
    $proof = $testsRan
        ? 'Test suite: PASSED at build time (bin/test.php, run by the builder).'
        : "Test suite: *** NOT RUN *** - built with --skip-tests, from an\n    UNPROVEN tree. Run bin/test.php before trusting this archive.";

    return <<<TXT
    SPEEDAT - INSTALL
    =================
    $zipName, built $builtAt.
    $proof

    Ten steps, in order, all of them in hPanel. No shell is needed at any point
    and none is assumed: Hostinger's SSH is a Premium-and-above extra and is off
    by default. Every step says what you should SEE if it worked; if you do not
    see it, stop there rather than carrying on.

    The same procedure with the reasoning behind it is in the repository at
    php/docs/deploy-hostinger.md, and the tick-list to record evidence against
    is php/docs/go-live-checklist.md.

    WHAT THE HOST MUST PROVIDE
    --------------------------
    PHP         8.2 or 8.3. Set it in hPanel -> Advanced -> PHP Configuration.
                Not 8.4 or 8.5: the code parses clean on them but nothing has
                been run on them, and go-live is not the moment to find out.
    Required    pdo_mysql, mbstring, json, openssl
                Without pdo_mysql nothing reaches the database at all.
    Wanted      gd, fileinfo    (image handling and upload type checks)
    Optional    zip, intl, sodium - the application checks for each and falls
                back. Note which are off; do not chase them.
    Database    MySQL / MariaDB 10.4 or newer.
    Server      LiteSpeed or Apache reading .htaccess. Both .htaccess and
                .user.ini ship in public_html, because a host reads one or the
                other and never says which.

    WHERE THIS GOES
    ---------------
    Extract this archive AT:

        /home/uXXXXXXXX/domains/<your-domain>/

    NOT inside public_html. The archive already contains a public_html folder,
    and the application is its SIBLING, in speedat_main/php/. That is the whole
    security design: there is no URL that reaches the configuration, because the
    configuration is not under the web root.

    After extracting you should see:

        domains/<your-domain>/
          public_html/          <- the website
          speedat_main/php/     <- THE APPLICATION. The site runs from here.
            app/                <- code
            bin/                <- cron.php, seed.php, harden.php
            config/             <- config.sample.php (you will copy it)
            db/migrations/      <- the schema
            public/             <- a copy of public_html the code reads from
            storage/            <- writable: cache, logs, tmp, uploads
            var/
          INSTALL.txt           <- this file

    Every path below that is not public_html is under speedat_main/php/.

    If instead you see public_html/speedat_main/, you extracted one level too
    deep. Delete what you extracted and do it again; do not drag folders around.

    If an OLDER release left app/, bin/, config/, db/, storage/ or var/
    directly in domains/<your-domain>/ (beside public_html, not inside
    speedat_main/php), nothing runs them - public_html/index.php uses
    speedat_main/php whenever it exists. Leave them until this install is
    proven, then delete them, so nobody edits a config.php the site never reads.

    THE TEN STEPS
    -------------
    1. CREATE THE DATABASE.
       hPanel -> Databases -> MySQL Databases -> Create a New MySQL Database.
       Name it speedat, user speedat, and use the password the panel generates.
       SEE: the new database in the list, and its REAL names - Hostinger
       prefixes both with your account id, so "speedat" is really something
       like u123456789_speedat. Write those down. They are what goes in
       config.php, and typing the unprefixed name is the commonest mistake
       here.

    2. UPLOAD AND EXTRACT.
       hPanel -> Files -> File Manager -> domains/<your-domain>/.
       Upload this zip THERE (not inside public_html), check its size matches
       what the builder printed, right-click -> Extract -> here, then delete
       the zip from the server.
       SEE: public_html/, speedat_main/ and INSTALL.txt side by side, and
       inside speedat_main/php/ the seven folders listed above.

    3. SET THE PERMISSIONS. DO THIS NOW, AND AGAIN AFTER EVERY UPGRADE.
       The zip carries no permissions - File Manager writes every extracted
       file with your account's own umask, which on a real account produced
       664 on every file and 775 on every directory: group-writable, all of
       it. This step is not a tidy-up. It is what CREATES the permissions.
       In File Manager, select the extracted folders, right-click ->
       Permissions, tick "Apply to all subfolders and files", and set:
         speedat_main/php/storage and speedat_main/php/var   700
         speedat_main/php/config                              700
         public_html, and speedat_main/php/app, bin, db, public
                                         755 folders / 644 files
       SEE: the modes in the File Manager listing.
       (Step 6 sets config.php itself to 600, once it exists.)

    4. IMPORT THE SCHEMA.
       hPanel -> Databases -> phpMyAdmin -> your database -> Import.
       Import speedat_main/php/db/migrations/0001_init.sql, then EVERY
       later 00NN_*.sql in
       filename order. All of them are safe to import twice.
       SEE: "Import has been successfully finished", and then, under
       Structure, TWELVE tables:
         admins  app_settings  audit_log  draft  leads  quotes  rate_limits
         schema_migrations  sessions  shipment_events  shipments  versions
       Fewer than twelve means an import stopped early. Read the error.

    5. FILL IN THE CONFIGURATION.
       File Manager -> speedat_main/php/config/. Right-click
       config.sample.php -> Copy into the
       same folder, then rename the copy to config.php. (Copy, not rename: the
       sample is what the next deploy diffs against.)
       Edit it and fill in app.key, app.url, app.env=production, db.name,
       db.user, db.pass (the PREFIXED names from step 1), tokens.cron,
       tokens.metrics, mail.from and mail.office.
       SEE: step 9's /health answering {"ok":true}. Until then there is nothing
       to look at, which is why /health is step 9 and not step 10.

    6. CLOSE config.php.
       Right-click config.php -> Permissions -> 600. It holds the application
       key, the database password and the mail password.
       SEE: 600 beside it in File Manager.

    7. TURN ON A WAY TO SIGN IN. Do this BEFORE step 8.
       This release ships with BOTH sign-in methods off, so a correct install
       shows a login page with no form on it and the sentence "Sign-in is not
       configured on this deployment yet." That is the code being honest, not
       a fault. Choose one, in speedat_main/php/config/config.php:
         'firebase_project_id'  => 'your-firebase-project',   Google sign-in
         'allow_password_login' => true,                      password + TOTP
       For Google, also add your domain in the Firebase console under
       Authentication -> Settings -> Authorised domains, or sign-in fails in
       the browser and NOTHING appears in any server log.
       SEE: /admin/login showing the method you chose.

    8. CREATE THE FIRST ADMIN - no shell required.
       For Google sign-in, one row is enough. phpMyAdmin -> SQL:
         INSERT INTO admins (email, name, role, added_by)
         VALUES ('owner@example.com', 'Owner name', 'owner', 'install');
       For password sign-in, use a ONE-OFF cron entry instead, because the
       password must be hashed. File Manager -> speedat_main/php/config/ ->
       New File ->
       first-password.txt, type the password (12 characters or more), save.
       Then hPanel -> Advanced -> Cron Jobs -> Custom, "once a minute":
         /usr/bin/php /home/uXXXXXXXX/domains/<your-domain>/speedat_main/php/bin/seed.php
           --owner=owner@example.com
           --password-file=/home/uXXXXXXXX/domains/<your-domain>/speedat_main/php/config/first-password.txt
       (all on ONE line). Wait a minute, then DELETE THE CRON ENTRY.
       seed.php wipes and deletes first-password.txt itself; check in File
       Manager that it is gone.
       SEE: in phpMyAdmin, SELECT email, role FROM admins; - one owner row.

    9. ADD THE HOURLY CRON, AND RUN IT ONCE.
       hPanel -> Advanced -> Cron Jobs -> Custom, hourly (0 * * * *):
         /usr/bin/php /home/uXXXXXXXX/domains/<your-domain>/speedat_main/php/bin/cron.php
       Absolute path, nothing after it: the field rejects >, 2>&1 and
       /dev/null, and the job writes its own log. The cron clock is UTC.
       A cron pointed at domains/<your-domain>/bin/cron.php (no
       speedat_main/php) runs an old copy with no config, or nothing at all.
       SEE: speedat_main/php/storage/logs/app-<today>.log with a
       cron_environment line naming
       the PHP version and the extensions, then a cron_run line. If
       pdo_mysql is missing from that line the cron is pointed at a different
       PHP build and nothing below it means anything.

    10. WALK THE SITE.
         https://your-domain/health     -> {"ok":true}
           (the detail - database up or down - needs the header
            Authorization: Bearer <tokens.metrics>; ?token= is ignored)
         https://your-domain/           -> the calculator, with prices
         https://your-domain/admin      -> sign in
         publish a version              -> it appears in History
         reload / as a stranger         -> the price you typed is on the page
         https://your-domain/.env       -> 403 or 404, NEVER a file
         https://your-domain/router.php -> 403 or 404
         https://your-domain/config/config.php -> 404, NEVER PHP source
       A 200 on any of the last three stops the launch: rename
       public_html/index.php to take the site down and re-read "WHERE THIS
       GOES" above.
       Last of all, key in the REAL prices and publish with "Rates are live"
       ticked. Until you do, the site serves sample rates and says so.

    WHAT MUST NEVER BE WORLD-READABLE
    ---------------------------------
    (all under speedat_main/php/)
      config/config.php   600.  The application key, the database password and
                                the SMTP password. Everything else on this list
                                is recoverable; this file is not.
      config/             700.  Its name is what a scanner asks for first.
      storage/ and below  700.  Logs carry what people typed into forms;
                                uploads carry whatever was uploaded.
      var/                700.  Reports and working files.
      first-password.txt        Must not exist at all once step 8 has run. A
                                plaintext password left in the account is a
                                plaintext password in every nightly backup
                                from then on.
    Nothing in this release should ever be 777. LiteSpeed may refuse to execute
    world-writable PHP, and on shared hosting a world-writable directory is one
    other accounts may be able to reach.

    IF SOMETHING IS WRONG
    ---------------------
    "This site is not configured yet."   speedat_main/php/config/config.php is
                                         missing or a value it needs is
                                         blank. The reason is in the
                                         host's error log (Files -> Error Log).
    "The database is unavailable."       db.name / db.user are missing the
                                         u123456789_ prefix, or the password is
                                         wrong.
    A login page with no form on it      Step 7. Neither sign-in method is on.
    A blank page or 500                  Host error log. display_errors is Off
                                         on purpose; the error is never shown to
                                         a visitor.
    Your fix did not take effect         FIRST check where it landed: the site
                                         runs speedat_main/php/app, not an
                                         app/ beside public_html. Then OPcache:
                                         it is on by default and hPanel's Cache
                                         Manager does not clear it. Wait out the
                                         revalidate window or call the opcache
                                         reset endpoint (header token, see
                                         docs/runbook.md section 9).
    403 on an admin form only            ModSecurity. See docs/deploy-hostinger.md.
    The site serves PHP source as text   Stop. Rename public_html/index.php to
                                         take the site down, then re-read
                                         "WHERE THIS GOES".

    ROLLBACK
    --------
    Keep, for every release: the previous zip, and a database dump taken just
    before the import.
      Code:  upload the previous zip to domains/<your-domain>/, Extract here
             overwriting, then REDO STEP 3 - an extract-over re-creates the
             same group-writable modes on every file it replaces. config.php
             is not in any release zip, so it survives untouched. Then reset
             OPcache or wait out the revalidate window.
             Check the previous zip first: open it on your computer. Its top
             level must hold speedat_main/ beside public_html/ (its comment
             says "The app lands in speedat_main/php/"). An older zip with
             app/ at the top level puts app/ BESIDE public_html, so extracting
             it rolls back public_html and nothing else. Roll back with a zip
             that passes that check, or not at all.
      Data:  phpMyAdmin -> Import the pre-import dump.
    Roll the code back first, and the data only if you must: newer schema
    usually tolerates older data, older code against newer data does not.
    To stop the bleeding in ten seconds without rolling back anything, turn the
    price hold on in the admin - the site stops quoting and shows a message.
    Use the admin switch, not SQL: the switch clears the site cache at once.
    A hold set by SQL in phpMyAdmin, or a data import, can take up to FIVE
    MINUTES to show, unless you then delete
    speedat_main/php/storage/cache/site/stamp in File Manager.
    TXT;
}
