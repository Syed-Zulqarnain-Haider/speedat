<?php

declare(strict_types=1);

/**
 * Push the working tree to the staging container and prove it answers.
 *
 *   php bin/deploy-staging.php
 *   php bin/deploy-staging.php --dry-run --list
 *   php bin/deploy-staging.php --no-migrate --debug-config
 *
 * On Windows the one command is bin\local\staging.ps1, which is a wrapper
 * around this file. See docs/staging.md.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS IS FOR
 * ---------------------------------------------------------------------------
 * The port is being written by several people at once and the target is real
 * Linux behind LiteSpeed/.htaccess, which no laptop reproduces. So: one
 * command that takes whatever is in the tree right now, puts it on the staging
 * box in the SAME layout Hostinger will have, migrates, and curls it. Under a
 * minute, twenty times an hour if need be.
 *
 * ---------------------------------------------------------------------------
 * WHAT GOES UP, AND WHAT CANNOT
 * ---------------------------------------------------------------------------
 * Exactly what the release ships, in the release's shape:
 *
 *     public/   ->  site/              (the container's /var/www/html)
 *     app/      ->  app/
 *     config/config.sample.php
 *     db/migrations/*.sql
 *     bin/{cron,migrate,seed}.php
 *     storage/{cache,logs,tmp,uploads}  (empty skeleton)
 *
 * and nothing else: no tests, no docs, no deploy/, no tools/, no dotfiles, no
 * node_modules, no config.php. That list is not re-implemented here. The deny
 * list is bin/build-release.php's refusalReason(), used through require (see
 * requireRefusalReason() below), so a rule added to the release builder is a
 * rule this script obeys on its next run and there is no second copy to drift.
 *
 * The two files this script must never ship are config/config.php (the real
 * one, on this laptop) and .env. Both are refused by that shared deny list,
 * and the refusal is re-checked against the finished manifest, not just the
 * plan.
 *
 * ---------------------------------------------------------------------------
 * HOW IT REACHES THE SERVER
 * ---------------------------------------------------------------------------
 * One tar.gz, built here in PHP (no tar.exe, no zip, no Composer), streamed
 * into `ssh <host> tar -xzf -`. scp and rsync are not available on that box.
 * Extraction lands in ~/speedat-staging/var/incoming, and a generated remote
 * script - shipped inside the same tar - syncs it into place, writes
 * config/config.php, migrates, reloads Apache and curls the site.
 *
 * Nothing outside ~/speedat-staging is read, written or restarted. The server
 * also runs the owner's Prometheus dashboard and a Kubernetes node.
 *
 * ---------------------------------------------------------------------------
 * IDEMPOTENCE
 * ---------------------------------------------------------------------------
 * Running it again is the normal case, not the exception:
 *   - site/ and app/ are synced in place (copy over, then delete what the
 *     payload no longer has). They are bind mounts: replacing the DIRECTORY
 *     would leave the running container looking at the old inode, so only the
 *     contents ever move.
 *   - config/config.php is rewritten, but its generated secrets (app key, cron
 *     and metrics tokens) are read back and KEPT, so sessions and stored TOTP
 *     secrets survive a redeploy.
 *   - migrations are recorded in schema_migrations and run once each.
 *   - every rm on the server is under ~/speedat-staging, and the remote script
 *     refuses to start if that is not where it finds itself.
 */

const EXIT_OK      = 0;
const EXIT_FAILED  = 1;
const EXIT_REFUSED = 2;

$root    = str_replace('\\', '/', dirname(__DIR__));
$started = microtime(true);

// ---------------------------------------------------------------------------
// Options
// ---------------------------------------------------------------------------

const KNOWN_OPTIONS = [
    'help', 'host', 'url', 'probe', 'ssh', 'container', 'remote',
    'dry-run', 'list', 'no-migrate', 'debug-config', 'keep', 'timeout',
];

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (!str_starts_with($argument, '--')) {
        fwrite(STDERR, "Unexpected argument \"$argument\". Try --help.\n");
        exit(EXIT_FAILED);
    }
    $pair = explode('=', substr($argument, 2), 2);
    if (!in_array($pair[0], KNOWN_OPTIONS, true)) {
        fwrite(STDERR, "Unknown option \"--{$pair[0]}\". Try --help.\n");
        exit(EXIT_FAILED);
    }
    $options[$pair[0]] = $pair[1] ?? '';
}

if (isset($options['help'])) {
    echo <<<TEXT
    Usage: php bin/deploy-staging.php [options]

      --host=USER@HOST     staging box            (default admin_tf@110.93.205.230)
      --remote=DIR         remote project dir     (default ~/speedat-staging)
      --container=NAME     web container          (default speedat-staging-web)
      --url=URL            app.url in the generated config
                                                  (default http://127.0.0.1:8085)
      --probe=URL          what to curl from the server
                                                  (default http://127.0.0.1:8085/)
      --ssh=PATH           ssh binary             (default ssh, from PATH)
      --timeout=SECONDS    per ssh step           (default 180)
      --dry-run            pack and report; touch nothing on the server
      --list               print every packed entry and every refusal
      --no-migrate         skip bin/migrate.php on the server
      --debug-config       generated config gets app.debug = true
      --keep               keep the tarball in var/ instead of deleting it
      --help               this

    TEXT;
    exit(EXIT_OK);
}

$host       = (string) ($options['host'] ?? 'admin_tf@110.93.205.230');
$remoteDir  = trim((string) ($options['remote'] ?? '$HOME/speedat-staging'));
$container  = (string) ($options['container'] ?? 'speedat-staging-web');
$appUrl     = rtrim((string) ($options['url'] ?? 'http://127.0.0.1:8085'), '/');
$probeUrl   = (string) ($options['probe'] ?? $appUrl . '/');
$sshBinary  = (string) ($options['ssh'] ?? 'ssh');
$timeout    = max(30, (int) ($options['timeout'] ?? 180));
$dryRun     = isset($options['dry-run']);
$verbose    = isset($options['list']);
$migrate    = !isset($options['no-migrate']);
$debug      = isset($options['debug-config']);
$keep       = isset($options['keep']);

if ($remoteDir === '' || str_contains($remoteDir, ' ') || str_contains($remoteDir, "'")) {
    fwrite(STDERR, "--remote must be a plain path without spaces or quotes.\n");
    exit(EXIT_FAILED);
}

echo "Speedat - staging deploy\n";
echo str_repeat('=', 72) . "\n";
echo 'source : ' . $root . "\n";
echo 'target : ' . ($dryRun ? '(dry run - nothing is sent)' : $host . ':' . $remoteDir) . "\n\n";

// ---------------------------------------------------------------------------
// 1. The one refusal that comes before everything else
// ---------------------------------------------------------------------------
// No front controller means there is nothing to serve and a deploy would
// replace a working staging site with a directory listing.

if (!is_file($root . '/public/index.php')) {
    fwrite(STDERR,
        "Refusing to deploy: php/public/index.php does not exist.\n"
        . "There is no front controller in this tree, so there is nothing to serve.\n");
    exit(EXIT_REFUSED);
}

// ---------------------------------------------------------------------------
// 2. The deny list, borrowed from the release builder
// ---------------------------------------------------------------------------

/**
 * Make bin/build-release.php's refusalReason() callable here.
 *
 * It cannot simply be required: build-release.php is a script, not a library -
 * requiring it builds a release zip and then exits, taking this process with
 * it. So the two constants and the one function are sliced out of it VERBATIM
 * by the tokeniser, written to a cache file keyed by the hash of the source,
 * and that file is required. No logic is copied: change the deny list in
 * build-release.php and the next run of this script picks it up, because the
 * hash changes and the slice is taken again.
 *
 * If the slice ever fails to produce a working function, this stops. A deploy
 * script that silently falls back to "ship everything" is how config.php ends
 * up on a server.
 */
function requireRefusalReason(string $builderPath): string
{
    if (!is_file($builderPath)) {
        fwrite(STDERR, "Cannot find $builderPath - the deny list has no source.\n");
        exit(EXIT_FAILED);
    }

    $source = (string) file_get_contents($builderPath);
    $cache  = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/')
        . '/speedat-release-denylist-' . substr(sha1($source), 0, 16) . '.php';

    if (!is_file($cache)) {
        $slice = sliceDeclarations($source, ['ALLOWED_DOTFILES', 'DENIED_DIRS'], 'refusalReason');

        $generated = "<?php\n\ndeclare(strict_types=1);\n\n"
            . "// GENERATED - do not edit. Sliced verbatim out of bin/build-release.php\n"
            . '// (sha1 ' . sha1($source) . ") by bin/deploy-staging.php, so the staging\n"
            . "// deploy and the release refuse exactly the same files.\n\n"
            . $slice . "\n";

        $temporary = $cache . '.' . getmypid() . '.new';
        if (file_put_contents($temporary, $generated) === false || !@rename($temporary, $cache)) {
            @unlink($temporary);
            fwrite(STDERR, "Cannot write the deny-list cache at $cache.\n");
            exit(EXIT_FAILED);
        }
    }

    require $cache;

    if (!function_exists('refusalReason') || !defined('DENIED_DIRS') || !defined('ALLOWED_DOTFILES')) {
        fwrite(STDERR,
            "bin/build-release.php no longer declares refusalReason() with its two\n"
            . "constants where this script can find them. Fix the slice in\n"
            . "bin/deploy-staging.php rather than re-writing the deny list.\n");
        exit(EXIT_FAILED);
    }

    // Self-test. These three answers are the whole point of the deny list, and
    // a botched slice that still parses would otherwise be discovered by
    // finding config.php on a server.
    $mustRefuse = ['config/config.php', 'app/.env', 'tests/PricingTest.php', 'public_html/router.php'];
    foreach ($mustRefuse as $entry) {
        if (refusalReason($entry) === null) {
            fwrite(STDERR, "The borrowed deny list no longer refuses \"$entry\". Stopping.\n");
            exit(EXIT_FAILED);
        }
    }
    if (refusalReason('app/Core/Router.php') !== null) {
        fwrite(STDERR, "The borrowed deny list refuses ordinary source code. Stopping.\n");
        exit(EXIT_FAILED);
    }

    return $cache;
}

/**
 * Return the source text of the named top-level consts and the named function,
 * in file order, exactly as they appear.
 *
 * @param list<string> $constants
 */
function sliceDeclarations(string $source, array $constants, string $function): string
{
    /** @var list<array{int,string,int}|string> $tokens */
    $tokens = token_get_all($source);
    $count  = count($tokens);
    $depth  = 0;
    $pieces = [];

    $text = static function (array|string $token): string {
        return is_array($token) ? $token[1] : $token;
    };

    /** Index of the next token that is not whitespace or a comment. */
    $next = static function (int $from) use ($tokens, $count): int {
        for ($i = $from; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $i;
            }
        }

        return $count;
    };

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if ($token === '{') {
            $depth++;
            continue;
        }
        if ($token === '}') {
            $depth--;
            continue;
        }
        // "{$x}" and "${x}" inside a double-quoted string or a heredoc open a
        // brace that closes as a plain '}' token. Without counting them the
        // depth drifts negative and every declaration after the first such
        // string looks nested.
        if (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
            $depth++;
            continue;
        }
        if ($depth !== 0 || !is_array($token)) {
            continue;
        }

        // const NAME = ...;   (top level only)
        if ($token[0] === T_CONST) {
            $nameIndex = $next($i + 1);
            $name      = $nameIndex < $count ? $text($tokens[$nameIndex]) : '';
            if (!in_array($name, $constants, true)) {
                continue;
            }
            $piece  = '';
            $inner  = 0;
            for ($j = $i; $j < $count; $j++) {
                $piece .= $text($tokens[$j]);
                $part   = $tokens[$j];
                if ($part === '[' || $part === '(') {
                    $inner++;
                }
                if ($part === ']' || $part === ')') {
                    $inner--;
                }
                if ($part === ';' && $inner === 0) {
                    break;
                }
            }
            $pieces[] = $piece;
            continue;
        }

        // function NAME(...) : ... { ... }   (top level only)
        if ($token[0] === T_FUNCTION) {
            $nameIndex = $next($i + 1);
            if ($nameIndex >= $count || $text($tokens[$nameIndex]) !== $function) {
                continue;
            }
            $piece  = '';
            $braces = 0;
            $opened = false;
            for ($j = $i; $j < $count; $j++) {
                $piece .= $text($tokens[$j]);
                $part   = $tokens[$j];
                if ($part === '{') {
                    $braces++;
                    $opened = true;
                }
                if ($part === '}') {
                    $braces--;
                    if ($opened && $braces === 0) {
                        break;
                    }
                }
            }
            $pieces[] = $piece;
        }
    }

    $wanted = count($constants) + 1;
    if (count($pieces) !== $wanted) {
        fwrite(STDERR,
            "Could not slice the deny list out of bin/build-release.php: expected $wanted\n"
            . 'declarations (' . implode(', ', $constants) . ", $function()), found "
            . count($pieces) . ".\n");
        exit(EXIT_FAILED);
    }

    return implode("\n\n", $pieces);
}

$denyListCache = requireRefusalReason($root . '/bin/build-release.php');

// ---------------------------------------------------------------------------
// 3. Collect the payload
// ---------------------------------------------------------------------------
//
// Two paths matter for every file:
//
//   the RELEASE path  - what the entry would be called inside the release zip.
//                       The deny list is written against those names, and some
//                       of its rules depend on the prefix (an .html under
//                       public_html is a leftover harness; router.php under
//                       public_html is the dev-server router). Checking against
//                       anything else quietly loses those rules.
//   the TAR path      - where it goes on the staging box, where the web root is
//                       called site/ because that is what docker-compose mounts.

/** @var array<string,array{kind:string, source:string, mode:int, mtime:int}> $payload */
$payload = [];
/** @var list<string> $refused */
$refused = [];
/** @var list<string> $directories */
$directories = [];

/**
 * @param array<string,array{kind:string, source:string, mode:int, mtime:int}> $payload
 * @param list<string>                                                        $refused
 * @param list<string>                                                        $skipTop
 */
function addTree(
    array &$payload,
    array &$refused,
    string $sourceDir,
    string $releasePrefix,
    string $tarPrefix,
    array $skipTop = [],
): void {
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

        if ($relative === '' || in_array(explode('/', $relative)[0], $skipTop, true)) {
            continue;
        }

        $releasePath = $releasePrefix === '' ? $relative : $releasePrefix . '/' . $relative;

        // Directories are checked too: a refused directory name (tests, docs,
        // node_modules) must stop its whole subtree, and the iterator hands us
        // the directory before its children.
        $reason = refusalReason($releasePath . ($item->isDir() ? '/' : ''));
        if ($reason !== null) {
            if (!$item->isDir()) {
                $refused[] = sprintf('%-54s %s', $releasePath, $reason);
            }
            continue;
        }

        if (!$item->isFile()) {
            continue;
        }

        if (!$item->isReadable()) {
            $refused[] = sprintf('%-54s is not readable', $releasePath);
            continue;
        }

        addFile($payload, $refused, ($tarPrefix === '' ? $relative : $tarPrefix . '/' . $relative), $releasePath, $absolute);
    }
}

/**
 * @param array<string,array{kind:string, source:string, mode:int, mtime:int}> $payload
 * @param list<string>                                                        $refused
 */
function addFile(array &$payload, array &$refused, string $tarPath, string $releasePath, string $absolute): void
{
    $reason = refusalReason($releasePath);
    if ($reason !== null) {
        $refused[] = sprintf('%-54s %s', $releasePath, $reason);

        return;
    }

    $payload[$tarPath] = [
        'kind'   => 'file',
        'source' => $absolute,
        'mode'   => 0644,
        'mtime'  => (int) (filemtime($absolute) ?: time()),
    ];
}

// The web root. Its contents become site/, which the container mounts at
// /var/www/html - exactly as public/ becomes public_html on Hostinger.
addTree($payload, $refused, $root . '/public', 'public_html', 'site');

// Application code.
addTree($payload, $refused, $root . '/app', 'app', 'app');

// The three bin scripts the server actually runs. bin/local/ is a laptop
// toolbox, and build-release/export-from-nextjs/test are development tools.
foreach (['cron.php', 'migrate.php', 'seed.php'] as $script) {
    $absolute = $root . '/bin/' . $script;
    if (is_file($absolute)) {
        addFile($payload, $refused, 'bin/' . $script, 'bin/' . $script, $absolute);
    }
}

// Configuration: the SAMPLE. config.php is written on the server, from the
// server's own .env, and never travels.
addFile($payload, $refused, 'config/config.sample.php', 'config/config.sample.php', $root . '/config/config.sample.php');

// The schema.
foreach (glob($root . '/db/migrations/*.sql') ?: [] as $migration) {
    $name = 'db/migrations/' . basename($migration);
    addFile($payload, $refused, $name, $name, str_replace('\\', '/', $migration));
}

// The deny-all guards, where the tree ships one. app/.htaccess already came in
// with the tree above; these are the siblings that have no tree of their own.
foreach (['config', 'db', 'storage'] as $guarded) {
    $absolute = $root . '/' . $guarded . '/.htaccess';
    if (is_file($absolute)) {
        addFile($payload, $refused, $guarded . '/.htaccess', $guarded . '/.htaccess', $absolute);
    }
}

// The writable skeleton, empty. Never the laptop's logs and caches.
foreach (['storage', 'storage/cache', 'storage/logs', 'storage/tmp', 'storage/uploads', 'var'] as $writable) {
    $directories[] = $writable;
}

// ---------------------------------------------------------------------------
// 4. Re-check the finished manifest
// ---------------------------------------------------------------------------
// The collector above is the thing most likely to have a bug in it. This loop
// asks the deny list a second time, about the list that is really going to be
// packed, and refuses to send anything at all if it disagrees. A bug here can
// fail a deploy; it cannot leak a secret.

$secrets = [];
foreach ($payload as $tarPath => $entry) {
    $releasePath = str_starts_with($tarPath, 'site/')
        ? 'public_html/' . substr($tarPath, 5)
        : $tarPath;

    $reason = refusalReason($releasePath);
    if ($reason !== null) {
        $secrets[] = "$tarPath ($reason)";
    }
}
if ($secrets !== []) {
    fwrite(STDERR, "Refusing to deploy - the manifest contains files the deny list forbids:\n");
    foreach ($secrets as $secret) {
        fwrite(STDERR, "  $secret\n");
    }
    exit(EXIT_REFUSED);
}

if ($payload === []) {
    fwrite(STDERR, "Nothing to deploy - the manifest is empty.\n");
    exit(EXIT_REFUSED);
}

ksort($payload);
sort($directories);

// ---------------------------------------------------------------------------
// 4b. Refusals that are almost certainly wrong
// ---------------------------------------------------------------------------
// app/ and db/migrations/ are directories whose whole contents are meant to
// ship. A .php refused out of app/, or a .sql refused out of db/migrations/, is
// therefore not a deny list doing its job - it is the deny list eating the
// application, and whatever it ate is missing from the release too, because
// both come from the same refusalReason().
//
// This script does NOT quietly override that: diverging here would mean staging
// serves code the release does not, which is the one thing staging must never
// do. It shouts instead, on every run, until somebody fixes
// bin/build-release.php.

$suspicious = [];
foreach ($refused as $line) {
    $path = strtok(trim($line), ' ');
    if (!is_string($path)) {
        continue;
    }
    if (str_starts_with($path, 'app/') && str_ends_with($path, '.php')) {
        $suspicious[] = $line;
    }
    if (str_starts_with($path, 'db/migrations/') && str_ends_with($path, '.sql')) {
        $suspicious[] = $line;
    }
}

if ($suspicious !== []) {
    echo "!! The shared deny list is refusing application code and/or schema. These\n";
    echo "!! files are missing from THIS DEPLOY and from every release built by\n";
    echo "!! bin/build-release.php. Fix refusalReason() there; nothing is special-cased\n";
    echo "!! here on purpose, because staging must ship what the release ships.\n";
    foreach ($suspicious as $line) {
        echo '!!   ' . $line . "\n";
    }
    echo "\n";
}

// ---------------------------------------------------------------------------
// 5. The remote half, generated here and shipped inside the same tar
// ---------------------------------------------------------------------------

$payload['_deploy/remote.sh'] = [
    'kind'   => 'data',
    'source' => remoteScript($remoteDir, $container, $probeUrl, $migrate),
    'mode'   => 0755,
    'mtime'  => time(),
];
$payload['_deploy/render-config.php'] = [
    'kind'   => 'data',
    'source' => configRenderer($appUrl, $debug),
    'mode'   => 0644,
    'mtime'  => time(),
];

/** Single-quote a value for POSIX sh. */
function shQuote(string $value): string
{
    return "'" . str_replace("'", "'\\''", $value) . "'";
}

function remoteScript(string $remoteDir, string $container, string $probeUrl, bool $migrate): string
{
    $baked = 'R=' . $remoteDir . "\n"
        . 'CONTAINER=' . shQuote($container) . "\n"
        . 'PROBE_URL=' . shQuote($probeUrl) . "\n"
        . 'RUN_MIGRATIONS=' . ($migrate ? 'yes' : 'no') . "\n";

    $script = <<<'SH'
        #!/bin/sh
        # Speedat - staging deploy, remote half. GENERATED by php/bin/deploy-staging.php.
        #
        # Every path this touches is built from $R below, and $R is asserted before
        # anything is removed, because a typo in a remote "rm -rf" is the one mistake
        # this file must not be able to make. This box also runs the owner's
        # Prometheus dashboard and a Kubernetes node: nothing outside ~/speedat-staging
        # is read, written, restarted or inspected here, and sudo is never used.
        set -eu

        __BAKED__
        IN=$R/var/incoming

        case $R in
            */speedat-staging) ;;
            *) echo 'DEPLOY-ERROR refusing to work outside a speedat-staging directory' >&2; exit 9 ;;
        esac

        test -d "$R"                || { echo "DEPLOY-ERROR $R does not exist" >&2; exit 4; }
        test -f "$R/.env"           || { echo "DEPLOY-ERROR $R/.env is missing - no database password to read" >&2; exit 4; }
        test -f "$R/docker-compose.yml" || { echo "DEPLOY-ERROR $R is not the staging compose project" >&2; exit 4; }
        test -f "$IN/site/index.php" || { echo 'DEPLOY-ERROR the payload has no site/index.php' >&2; exit 4; }
        test -d "$IN/app/Core"       || { echo 'DEPLOY-ERROR the payload has no app/Core' >&2; exit 4; }

        started=$(date +%s)
        umask 022

        # --- 1. the bind-mounted trees: CONTENTS only -------------------------------
        # site/, app/, config/ and storage/ are bind mounts into the container. Moving
        # or replacing one of those directories leaves the running container looking at
        # the old inode: the deploy would report success and change nothing. So the
        # contents are synced in place - copy the payload over, then delete whatever
        # the payload no longer has - which also means the site is never absent.
        sync_tree() {
            d=$1
            mkdir -p "$R/$d"
            cp -a "$IN/$d/." "$R/$d/"
            ( cd "$R/$d" && find . -mindepth 1 ! -type d -print ) | while IFS= read -r f; do
                if [ ! -e "$IN/$d/$f" ]; then rm -f "$R/$d/$f"; fi
            done
            find "$R/$d" -mindepth 1 -type d -empty -delete
        }
        sync_tree site
        sync_tree app

        # --- 2. the trees the container does not mount ------------------------------
        # bin/ and db/ have no mount, so they are copied in with docker cp further
        # down; they live here so that copy has a source, and so the layout on disk
        # still looks like Hostinger's.
        for d in bin db; do
            rm -rf "$R/$d"
            mkdir -p "$R/$d"
            cp -a "$IN/$d/." "$R/$d/"
        done

        # --- 3. configuration: the sample travels, config.php never does ------------
        mkdir -p "$R/config"
        cp -a "$IN/config/config.sample.php" "$R/config/config.sample.php"
        if [ -f "$IN/config/.htaccess" ]; then cp -a "$IN/config/.htaccess" "$R/config/.htaccess"; fi

        # --- 4. the writable skeleton ----------------------------------------------
        for d in cache logs tmp uploads; do mkdir -p "$R/storage/$d"; done
        if [ -f "$IN/storage/.htaccess" ]; then cp -a "$IN/storage/.htaccess" "$R/storage/.htaccess"; fi
        chmod -R u+rwX "$R/storage"

        # --- 5. config/config.php ---------------------------------------------------
        # The renderer runs INSIDE the container, because the host has no PHP. The
        # database password reaches it through docker's --env-file, read straight out
        # of ~/speedat-staging/.env: it is never a command-line argument (arguments are
        # visible in ps), never echoed, and never leaves this machine. -u 1000:1000 so
        # the file belongs to admin_tf, which is also www-data inside the container.
        docker cp "$IN/_deploy/render-config.php" "$CONTAINER:/tmp/speedat-render-config.php" >/dev/null
        docker exec -u 1000:1000 --env-file "$R/.env" "$CONTAINER" php /tmp/speedat-render-config.php
        docker exec "$CONTAINER" rm -f /tmp/speedat-render-config.php >/dev/null 2>&1 || true

        # --- 6. migrations ----------------------------------------------------------
        docker exec "$CONTAINER" rm -rf /var/www/bin /var/www/db
        docker cp "$R/bin" "$CONTAINER:/var/www/" >/dev/null
        docker cp "$R/db"  "$CONTAINER:/var/www/" >/dev/null
        migrations=skipped
        if [ "$RUN_MIGRATIONS" = yes ]; then
            docker exec -u 1000:1000 "$CONTAINER" php /var/www/bin/migrate.php
            migrations=ok
        fi

        # --- 7. OPcache -------------------------------------------------------------
        # A graceful Apache reload drops the shared opcache for the web SAPI (the CLI
        # keeps none between runs). php.ini here also has validate_timestamps=1 with
        # revalidate_freq=2, so even a refused reload means the new code is live within
        # two seconds - which is why this is reported rather than fatal.
        opcache=reset
        docker exec "$CONTAINER" apache2ctl -k graceful >/dev/null 2>&1 || opcache=stale-up-to-2s

        # --- 8. does it actually answer? --------------------------------------------
        body=$R/var/probe.txt
        mkdir -p "$R/var"
        : > "$body"
        status=$(curl -s -o "$body" -w '%{http_code}' --max-time 20 "$PROBE_URL") || status=000
        [ -n "$status" ] || status=000
        bytes=$(wc -c < "$body" | tr -d ' ')
        echo "--- $PROBE_URL -> HTTP $status, $bytes bytes, first 20 lines ---"
        head -c 4000 "$body" | head -n 20
        echo "--- end of page ---"

        # --- 9. tidy up -------------------------------------------------------------
        # Only ever inside $R. Left behind on failure, on purpose: set -eu means we
        # never get here, and the payload is then still there to look at.
        rm -rf "$IN" "$body"

        files=$(find "$R/site" "$R/app" "$R/bin" "$R/db" -type f | wc -l | tr -d ' ')
        echo "SPEEDAT-STAGING-RESULT http=$status migrations=$migrations opcache=$opcache remote_files=$files remote_seconds=$(( $(date +%s) - started ))"
        SH;

    // PHP 7.3+ strips the closing marker's indentation from every line of a
    // nowdoc, so what comes out is already flush left.
    return str_replace('__BAKED__', rtrim($baked), $script) . "\n";
}

function configRenderer(string $appUrl, bool $debug): string
{
    $head = <<<'PHP'
        <?php

        declare(strict_types=1);

        /**
         * Write the staging container's config/config.php. GENERATED by
         * php/bin/deploy-staging.php; runs inside the container as uid 1000.
         *
         * The database password arrives as an environment variable, because
         * `docker exec --env-file ~/speedat-staging/.env` read it from the file the
         * compose project already keeps it in. It is never an argument (arguments show
         * up in ps), it is never printed, and nothing writes it back to the laptop.
         *
         * Re-running the deploy must not log everybody out: the generated secrets
         * (app.key, the cron and metrics tokens) are read back out of the existing
         * config and KEPT. app.key in particular encrypts stored TOTP secrets, and
         * rotating it makes them unreadable for good.
         */

        const TARGET = '/var/www/config/config.php';

        $password = (string) getenv('DB_PASSWORD');
        if ($password === '') {
            fwrite(STDERR, "DB_PASSWORD is empty: ~/speedat-staging/.env was not handed to docker exec.\n");
            exit(3);
        }

        /** @var array<string,mixed> $existing */
        $existing = [];
        if (is_file(TARGET)) {
            $loaded = require TARGET;
            if (is_array($loaded)) {
                $existing = $loaded;
            }
        }

        /**
         * A secret already in the file, or '' if there is none to keep.
         *
         * @param array<string,mixed> $config
         */
        function kept(array $config, string $group, string $key): string
        {
            $section = $config[$group] ?? null;
            $value   = is_array($section) ? ($section[$key] ?? null) : null;

            return is_string($value) && $value !== '' ? $value : '';
        }

        $appKey  = kept($existing, 'app', 'key') ?: base64_encode(random_bytes(32));
        $cron    = kept($existing, 'tokens', 'cron') ?: bin2hex(random_bytes(24));
        $metrics = kept($existing, 'tokens', 'metrics') ?: bin2hex(random_bytes(24));

        $config = [
            'app' => [
                'key' => $appKey,
                'url' => APP_URL,
                // Not 'production': staging is where a stack trace in the log is
                // wanted. app.debug stays off all the same - this container is
                // published on 0.0.0.0:8085 - so the detail goes to
                // storage/logs and the visitor still gets the plain page.
                'env'         => 'local',
                'timezone'    => 'Asia/Karachi',
                'debug'       => APP_DEBUG,
                'trust_proxy' => false,
            ],
            'db' => [
                // 'db' is the compose service name on the project's own bridge
                // network. Nothing else on this host can reach it.
                'host'    => 'db',
                'port'    => 3306,
                'name'    => 'speedat',
                'user'    => 'speedat',
                'pass'    => $password,
                'charset' => 'utf8mb4',
            ],
            'auth' => [
                // No Firebase project on staging: sign-in is the password + TOTP
                // path, which is the point of having it. Staging holds test data
                // only and keeps its own admin and its own 2FA.
                'firebase_project_id'  => '',
                'allow_password_login' => true,
                'session_lifetime'     => 432000,
                'session_idle'         => 28800,
            ],
            'tokens' => [
                'cron'    => $cron,
                'metrics' => $metrics,
                // tokens.inbound_email is deliberately absent: the rate-sheet
                // mailbox forwarder was removed from the product (owner, 2026-09-27).
            ],
            'mail' => [
                // 'log' sends nothing. A staging box that emails the office every
                // time somebody tests the lead form is a staging box people turn off.
                'transport' => 'log',
                'from'      => 'staging@speedat.com.pk',
                'from_name' => 'Speedat International (staging)',
                'office'    => 'staging@speedat.com.pk',
                'smtp'      => [
                    'host'   => '',
                    'port'   => 587,
                    'secure' => 'tls',
                    'user'   => '',
                    'pass'   => '',
                ],
            ],
            // The sample's numbers, unchanged: a limit that only exists in
            // production is a limit discovered in production.
            'limits' => [
                'quote_per_hour'      => 120,
                'lead_per_hour'       => 10,
                'contact_per_hour'    => 10,
                'login_per_15min'     => 10,
                'admin_write_per_min' => 60,
                'track_per_hour'      => 120,
            ],
            'security' => [
                // Never on here: staging is plain http, and a browser remembers
                // HSTS for a year.
                'hsts'        => false,
                'csp_script'  => '',
                'csp_connect' => '',
            ],
            'paths' => [
                'storage' => '',
                'cache'   => '',
                'logs'    => '',
                'tmp'     => '',
                'uploads' => '',
            ],
        ];

        $php = "<?php\n\ndeclare(strict_types=1);\n\n"
            . "/**\n"
            . " * GENERATED for the staging container by php/bin/deploy-staging.php.\n"
            . " * Rewritten on every deploy. Edits here are lost; change the renderer.\n"
            . " */\n\n"
            . 'return ' . var_export($config, true) . ";\n";

        $temporary = TARGET . '.new';
        if (file_put_contents($temporary, $php) === false) {
            fwrite(STDERR, "Cannot write " . $temporary . "\n");
            exit(3);
        }
        chmod($temporary, 0600);

        // Parse and sanity-check the file BEFORE it becomes the live config, so a
        // broken renderer cannot 503 the site.
        $verify = require $temporary;
        if (!is_array($verify) || !is_string($verify['app']['key'] ?? null) || $verify['app']['key'] === '') {
            @unlink($temporary);
            fwrite(STDERR, "The generated config did not come back as a usable array.\n");
            exit(3);
        }

        if (!rename($temporary, TARGET)) {
            @unlink($temporary);
            fwrite(STDERR, "Cannot move " . $temporary . " into place\n");
            exit(3);
        }

        echo 'config=written secrets=', $existing === [] ? 'generated' : 'kept',
            ' debug=', APP_DEBUG ? 'on' : 'off', PHP_EOL;
        PHP;

    // As above: the nowdoc arrives flush left already.
    $body = $head;

    // The two values the laptop decides, injected as constants so the renderer
    // has no command line at all.
    $constants = "const APP_URL = " . var_export($appUrl, true) . ";\n"
        . 'const APP_DEBUG = ' . ($debug ? 'true' : 'false') . ";\n";

    return str_replace("const TARGET = ", $constants . "\nconst TARGET = ", $body) . "\n";
}

// ---------------------------------------------------------------------------
// 6. Pack
// ---------------------------------------------------------------------------

/**
 * One 512-byte ustar header. Written by hand because the target has no tar.exe
 * worth relying on, ext-zip is not the wire format, and PharData would put its
 * own metadata in the archive.
 */
function ustarHeader(string $name, int $size, int $mode, int $mtime, string $type): string
{
    $prefix = '';
    if (strlen($name) > 100) {
        $cut = strrpos(substr($name, 0, 156), '/');
        if ($cut === false || strlen($name) - $cut - 1 > 100) {
            fwrite(STDERR, "Path too long for a tar header: $name\n");
            exit(EXIT_FAILED);
        }
        $prefix = substr($name, 0, $cut);
        $name   = substr($name, $cut + 1);
    }

    $header = pack(
        'a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12',
        $name,
        sprintf('%07o', $mode),
        sprintf('%07o', 0),                 // uid: extraction is not root, so 0
        sprintf('%07o', 0),                 // gid
        sprintf('%011o', $size),
        sprintf('%011o', $mtime),
        '        ',                          // checksum, filled in below
        $type,                               // '0' file, '5' directory
        '',                                  // linkname
        'ustar',
        '00',
        '',                                  // uname
        '',                                  // gname
        sprintf('%07o', 0),
        sprintf('%07o', 0),
        $prefix,
        '',
    );

    $checksum = 0;
    for ($i = 0; $i < 512; $i++) {
        $checksum += ord($header[$i]);
    }

    return substr_replace($header, sprintf('%06o', $checksum) . "\0 ", 148, 8);
}

$tarDir = $root . '/var';
if (!is_dir($tarDir) && !@mkdir($tarDir, 0775, true) && !is_dir($tarDir)) {
    fwrite(STDERR, "Cannot create $tarDir for the tarball.\n");
    exit(EXIT_FAILED);
}
$tarball = $tarDir . '/staging-payload-' . gmdate('Ymd-His') . '.tar.gz';

$gz = gzopen($tarball, 'wb6');
if ($gz === false) {
    fwrite(STDERR, "Cannot open $tarball for writing.\n");
    exit(EXIT_FAILED);
}

$now          = time();
$fileCount    = 0;
$rawBytes     = 0;
$archiveBytes = 0;

foreach ($directories as $directory) {
    gzwrite($gz, ustarHeader($directory . '/', 0, 0755, $now, '5'));
    $archiveBytes += 512;
}

foreach ($payload as $tarPath => $entry) {
    if ($entry['kind'] === 'data') {
        $data = $entry['source'];
        gzwrite($gz, ustarHeader($tarPath, strlen($data), $entry['mode'], $entry['mtime'], '0'));
        gzwrite($gz, $data);
        $padding = (512 - (strlen($data) % 512)) % 512;
        if ($padding > 0) {
            gzwrite($gz, str_repeat("\0", $padding));
        }
        $fileCount++;
        $rawBytes     += strlen($data);
        $archiveBytes += 512 + strlen($data) + $padding;
        continue;
    }

    $size   = (int) (filesize($entry['source']) ?: 0);
    $handle = fopen($entry['source'], 'rb');
    if ($handle === false) {
        gzclose($gz);
        @unlink($tarball);
        fwrite(STDERR, "Cannot read {$entry['source']}.\n");
        exit(EXIT_FAILED);
    }

    gzwrite($gz, ustarHeader($tarPath, $size, $entry['mode'], $entry['mtime'], '0'));

    // Exactly $size bytes, no matter what the file does while we read it: the
    // header has already promised that number and a tar whose payload does not
    // match its header is a tar GNU tar refuses halfway through.
    $written = 0;
    while ($written < $size) {
        $chunk = fread($handle, min(262144, $size - $written));
        if ($chunk === false || $chunk === '') {
            break;
        }
        gzwrite($gz, $chunk);
        $written += strlen($chunk);
    }
    fclose($handle);

    if ($written < $size) {
        gzwrite($gz, str_repeat("\0", $size - $written));
    }
    $padding = (512 - ($size % 512)) % 512;
    if ($padding > 0) {
        gzwrite($gz, str_repeat("\0", $padding));
    }

    $fileCount++;
    $rawBytes     += $size;
    $archiveBytes += 512 + $size + $padding;
}

// Two zero blocks end the archive; the rest pads it out to tar's 20-block
// record, which is what every tar expects to read.
gzwrite($gz, str_repeat("\0", 1024));
$archiveBytes += 1024;
$tail = (10240 - ($archiveBytes % 10240)) % 10240;
if ($tail > 0) {
    gzwrite($gz, str_repeat("\0", $tail));
}
gzclose($gz);

$packedBytes = (int) (filesize($tarball) ?: 0);

echo sprintf(
    "packed : %d files (+%d directories), %s raw -> %s compressed\n",
    $fileCount,
    count($directories),
    number_format($rawBytes / 1024, 0) . ' KB',
    number_format($packedBytes / 1024, 0) . ' KB',
);
echo 'refused: ' . count($refused) . " entries the release would not ship either\n";
echo 'denylist: ' . $denyListCache . "\n\n";

if ($verbose) {
    echo "--- packed ---\n";
    foreach ($directories as $directory) {
        echo "  d $directory/\n";
    }
    foreach ($payload as $tarPath => $entry) {
        echo '  ' . ($entry['kind'] === 'data' ? 'g' : '-') . " $tarPath\n";
    }
    echo "\n--- refused ---\n";
    foreach ($refused as $line) {
        echo "  $line\n";
    }
    echo "\n";
}

if ($dryRun) {
    echo "Dry run - the server was not contacted. The tarball is at:\n  $tarball\n";
    exit(EXIT_OK);
}

// ---------------------------------------------------------------------------
// 7. Send
// ---------------------------------------------------------------------------

/**
 * Run a command, streaming its output as it arrives.
 *
 * Both streams go to files rather than pipes: on Windows a proc_open pipe is
 * not selectable, so reading stdout while stderr fills its 64 KB buffer is a
 * deadlock. Files have neither problem and let us tail them live.
 *
 * @param  list<string> $command
 * @return array{status:int, out:string, err:string}
 */
function run(array $command, ?string $stdinFile, int $timeoutSeconds, bool $echo): array
{
    $nullDevice = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    $outFile    = (string) tempnam(sys_get_temp_dir(), 'spd-out');
    $errFile    = (string) tempnam(sys_get_temp_dir(), 'spd-err');

    $specification = [
        0 => ['file', $stdinFile ?? $nullDevice, 'rb'],
        1 => ['file', $outFile, 'wb'],
        2 => ['file', $errFile, 'wb'],
    ];

    $pipes   = [];
    $process = proc_open($command, $specification, $pipes);
    if (!is_resource($process)) {
        @unlink($outFile);
        @unlink($errFile);

        return ['status' => 127, 'out' => '', 'err' => 'Could not start: ' . $command[0]];
    }

    $shown    = 0;
    $deadline = microtime(true) + $timeoutSeconds;
    $exitCode = 0;
    $killed   = false;

    while (true) {
        $status = proc_get_status($process);

        if ($echo) {
            $size = (int) (@filesize($outFile) ?: 0);
            if ($size > $shown) {
                $handle = @fopen($outFile, 'rb');
                if ($handle !== false) {
                    fseek($handle, $shown);
                    $new = (string) stream_get_contents($handle);
                    fclose($handle);
                    echo $new;
                    $shown += strlen($new);
                }
            }
        }

        if ($status['running'] === false) {
            $exitCode = (int) $status['exitcode'];
            break;
        }

        if (microtime(true) > $deadline) {
            proc_terminate($process, 9);
            $killed = true;
            $exitCode = 124;
            break;
        }

        usleep(120000);
    }

    proc_close($process);
    clearstatcache();

    $out = (string) @file_get_contents($outFile);
    $err = (string) @file_get_contents($errFile);
    @unlink($outFile);
    @unlink($errFile);

    if ($echo && strlen($out) > $shown) {
        echo substr($out, $shown);
    }
    if ($killed) {
        $err .= "\nTimed out after {$timeoutSeconds}s.";
    }

    return ['status' => $exitCode, 'out' => $out, 'err' => $err];
}

/**
 * ssh, with the options that matter: never prompt (a deploy that waits for a
 * password is a deploy that hangs), and notice a dead link instead of sitting
 * on it.
 *
 * @return list<string>
 */
function sshCommand(string $binary, string $host, string $remoteCommand, bool $noStdin): array
{
    $command = [
        $binary,
        '-o', 'BatchMode=yes',
        '-o', 'ConnectTimeout=20',
        '-o', 'ServerAliveInterval=15',
        '-o', 'ServerAliveCountMax=4',
    ];
    if ($noStdin) {
        $command[] = '-n';
    }
    $command[] = $host;
    $command[] = $remoteCommand;

    return $command;
}

// Is there an ssh at all? On Windows the OpenSSH client lives somewhere PATH
// does not always mention, so try the known place before giving up.
$probe = run([$sshBinary, '-V'], null, 20, false);
if ($probe['status'] !== 0 && !isset($options['ssh'])) {
    $windows = 'C:/Windows/System32/OpenSSH/ssh.exe';
    if (is_file($windows) && run([$windows, '-V'], null, 20, false)['status'] === 0) {
        $sshBinary = $windows;
    } else {
        fwrite(STDERR, "No usable ssh client (tried \"$sshBinary\"). Pass --ssh=PATH.\n");
        exit(EXIT_FAILED);
    }
}

// Step one: unpack the payload into a scratch directory under the project.
// No double quotes in this command - it travels through two shells.
$extract = 'set -e; R=' . $remoteDir . '; test -d $R || exit 4;'
    . ' rm -rf $R/var/incoming; mkdir -p $R/var/incoming;'
    . ' tar -xzf - -C $R/var/incoming';

echo "sending payload ...\n";
$upload = run(sshCommand($sshBinary, $host, $extract, false), $tarball, $timeout, true);

if ($upload['status'] !== 0) {
    fwrite(STDERR, "\nUpload failed (exit {$upload['status']}).\n" . trim($upload['err']) . "\n");
    if (!$keep) {
        @unlink($tarball);
    }
    exit(EXIT_FAILED);
}

// Step two: the remote half, which came up inside the payload.
echo "running remote deploy ...\n\n";
$deploy = run(
    sshCommand($sshBinary, $host, 'sh ' . $remoteDir . '/var/incoming/_deploy/remote.sh', true),
    null,
    $timeout,
    true,
);

if (trim($deploy['err']) !== '') {
    fwrite(STDERR, "\n[server stderr]\n" . trim($deploy['err']) . "\n");
}

if (!$keep) {
    @unlink($tarball);
} else {
    echo "\ntarball kept: $tarball\n";
}

// ---------------------------------------------------------------------------
// 8. The one line
// ---------------------------------------------------------------------------

$seconds = round(microtime(true) - $started, 1);

$httpStatus = '000';
$migrations = 'unknown';
$opcache    = 'unknown';
if (preg_match('/^SPEEDAT-STAGING-RESULT (.*)$/m', $deploy['out'], $match) === 1) {
    foreach (explode(' ', trim($match[1])) as $pair) {
        [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
        if ($key === 'http') {
            $httpStatus = $value;
        }
        if ($key === 'migrations') {
            $migrations = $value;
        }
        if ($key === 'opcache') {
            $opcache = $value;
        }
    }
}

echo "\n" . sprintf(
    'SUMMARY files=%d bytes=%d seconds=%s http=%s migrations=%s opcache=%s url=%s',
    $fileCount,
    $packedBytes,
    number_format($seconds, 1),
    $httpStatus,
    $migrations,
    $opcache,
    $probeUrl,
) . "\n";

if ($deploy['status'] !== 0) {
    fwrite(STDERR, "The remote deploy exited {$deploy['status']}.\n");
    exit(EXIT_FAILED);
}

// A 2xx or a 3xx means the stack answered. Anything else is a deploy that
// technically copied files and practically did nothing, so it is a failure.
if (preg_match('/^[23]\d\d$/', $httpStatus) !== 1) {
    fwrite(STDERR, "The site answered HTTP $httpStatus. See docs/staging.md for the logs.\n");
    exit(EXIT_FAILED);
}

exit(EXIT_OK);
