<?php

declare(strict_types=1);

/**
 * Extract a release zip into a throwaway directory and prove, against the files
 * that actually landed on disk, that it is safe to upload.
 *
 *   php deploy/verify-release.php ../var/speedat-release-20260927-101500.zip
 *   php deploy/verify-release.php RELEASE.zip --keep
 *
 * WHY THIS EXISTS WHEN build-release.php ALREADY CHECKS ITSELF
 * The builder checks its own work with its own deny list. If that list is wrong
 * - as it was on its first run, when it refused SecretBox.php - the builder
 * agrees with itself and says everything is fine. This script starts from the
 * zip, unpacks it the way File Manager will, and asks a different set of
 * questions of the result:
 *
 *   * is there a config.php, an .env, a .git, a test, a log, a dump?
 *   * does every non-public directory have a deny-all .htaccess?
 *   * does public_html have its rewrite, and does app/ sit BESIDE it, not in it?
 *   * does every .php file in the archive actually parse?
 *   * is there anything that would escape the extraction directory?
 *
 * It is the last thing to run before an upload, and it exits non-zero if any
 * check fails, so it can sit in front of the upload step in any script.
 *
 * It needs nothing from the application and writes only inside the system
 * temporary directory, which it removes again unless you pass --keep.
 */

const EXIT_OK     = 0;
const EXIT_FAILED = 1;
const EXIT_USAGE  = 2;

$arguments = array_slice($argv, 1);
$zipPath   = '';
$keep      = false;

foreach ($arguments as $argument) {
    if ($argument === '--keep') {
        $keep = true;
    } elseif (str_starts_with($argument, '--')) {
        fwrite(STDERR, "Unknown option $argument\n");
        exit(EXIT_USAGE);
    } else {
        $zipPath = $argument;
    }
}

if ($zipPath === '') {
    fwrite(STDERR, "Usage: php deploy/verify-release.php <release.zip> [--keep]\n");
    exit(EXIT_USAGE);
}

if (!is_file($zipPath)) {
    fwrite(STDERR, "No such file: $zipPath\n");
    exit(EXIT_USAGE);
}

if (!extension_loaded('zip')) {
    fwrite(STDERR, "ext-zip is not loaded; this script cannot unpack anything.\n");
    exit(EXIT_FAILED);
}

echo "Speedat - release verification\n";
echo str_repeat('=', 72) . "\n";
echo 'archive : ' . str_replace('\\', '/', (string) realpath($zipPath)) . "\n";
echo 'size    : ' . number_format((float) filesize($zipPath) / 1024 / 1024, 2) . " MiB\n";
echo 'sha256  : ' . hash_file('sha256', $zipPath) . "\n";

// ---------------------------------------------------------------------------
// Unpack into a fresh temporary directory
// ---------------------------------------------------------------------------

$temp = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/')
    . '/speedat-verify-' . bin2hex(random_bytes(6));

if (!mkdir($temp, 0700, true) && !is_dir($temp)) {
    fwrite(STDERR, "Cannot create $temp\n");
    exit(EXIT_FAILED);
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CHECKCONS) !== true) {
    fwrite(STDERR, "The archive will not open, or fails its own consistency check.\n");
    exit(EXIT_FAILED);
}

// Refuse a path that would escape before extractTo is allowed near the disk.
// extractTo is generally safe, but "generally" is not a word that belongs
// between an archive and a filesystem.
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = (string) $zip->getNameIndex($i);
    if (str_starts_with($name, '/') || str_contains($name, '..') || preg_match('/^[A-Za-z]:/', $name) === 1) {
        fwrite(STDERR, "REFUSING TO EXTRACT: \"$name\" would escape the target directory.\n");
        exit(EXIT_FAILED);
    }
}

$entryCount = $zip->numFiles;
if (!$zip->extractTo($temp)) {
    fwrite(STDERR, "Extraction failed.\n");
    exit(EXIT_FAILED);
}
$zip->close();

echo 'entries : ' . $entryCount . "\n";
echo 'unpacked: ' . $temp . "\n\n";

// ---------------------------------------------------------------------------
// Walk what actually landed
// ---------------------------------------------------------------------------

/** @var list<string> $paths relative, forward slashes */
$paths = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
    RecursiveIteratorIterator::SELF_FIRST,
);
foreach ($iterator as $item) {
    /** @var SplFileInfo $item */
    $relative = ltrim(substr(str_replace('\\', '/', $item->getPathname()), strlen($temp)), '/');
    $paths[]  = $item->isDir() ? $relative . '/' : $relative;
}
sort($paths);

$files = array_values(array_filter($paths, static fn (string $p): bool => !str_ends_with($p, '/')));

/** @var list<string> $failures */
$failures = [];
/** @var list<string> $passes */
$passes = [];

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures, $passes;
    if ($ok) {
        $passes[] = $label;
        echo '  ok    ' . $label . ($detail !== '' ? '  ' . $detail : '') . "\n";
    } else {
        $failures[] = $label . ($detail !== '' ? ': ' . $detail : '');
        echo '  FAIL  ' . $label . ($detail !== '' ? '  ' . $detail : '') . "\n";
    }
}

/**
 * @param list<string> $files
 * @return list<string>
 */
function matching(array $files, string $pattern): array
{
    return array_values(array_filter($files, static fn (string $f): bool => preg_match($pattern, $f) === 1));
}

// --- 1. nothing that must never be there -----------------------------------
echo "1. Nothing that must never ship\n";

$forbidden = [
    // Case-insensitive on purpose: the audit proved Config.PHP slipped past a
    // case-sensitive guard, and the release builder's own rules were fixed the
    // same way. Two checkers with different rules is how a leak comes back.
    //
    // SCOPED to config/ and the web root, which is what the builder polices.
    // A blanket "(^|/)config\.php$" plus the /i flag refused app/Core/Config.php
    // - the class that READS configuration and holds none of it - so no archive
    // could pass while check 2 of this same script demanded that file be present.
    'no real configuration in config/'      => '#^config/(?!config\.sample\.php$).*\.php$#i',
    'no config.php in the web root'         => '#^public_html/(.*/)?config\.php$#i',
    'no *.local.php override'               => '#\.local\.php$#i',
    'no .env file'                          => '#(^|/)\.env#',
    'no .git directory'                     => '#(^|/)\.git(/|$)#',
    'no tests'                              => '#(^|/)(tests?|__tests__)/|Test\.php$#',
    'no documentation'                      => '#\.(md|markdown)$#',
    'no node_modules'                       => '#(^|/)node_modules(/|$)#',
    'no log files'                          => '#\.log$#',
    'no keys or certificates'               => '#\.(pem|key|p12|pfx|crt|csr|ppk)$#',
    'no TypeScript or source maps'          => '#\.(ts|tsx|mjs|cjs|map)$#',
    'no shell scripts'                      => '#\.(sh|bash|ps1|bat|cmd)$#',
    'no editor or OS droppings'             => '#(^|/)(\.DS_Store|Thumbs\.db|.*\.swp|.*\.bak|.*\.orig)$#',
    'no dev-server router'                  => '#(^|/)router\.php$#',
    // Nothing the site serves is a .json file; the things that arrive as .json
    // are Firebase service accounts and package manifests.
    'no .json files'                        => '#\.jsonc?$#',
    'nothing named like a credential store' => '#(service.?account|adminsdk|credential|private.?key)#i',
];

foreach ($forbidden as $what => $pattern) {
    $hits = matching($files, $pattern);
    check($what, $hits === [], $hits === [] ? '' : implode(', ', array_slice($hits, 0, 3)));
}

// Dotfiles, allowing only the two that are configuration the server reads.
$dotfiles = array_values(array_filter(
    $files,
    static function (string $file): bool {
        foreach (explode('/', $file) as $segment) {
            if ($segment !== '' && $segment[0] === '.' && !in_array($segment, ['.htaccess', '.user.ini'], true)) {
                return true;
            }
        }
        return false;
    },
));
check('no dotfiles except .htaccess/.user.ini', $dotfiles === [], implode(', ', array_slice($dotfiles, 0, 5)));

// .sql is allowed only as the schema.
// Only NUMBERED migrations, not merely "anything under db/migrations": a data
// dump dropped into that directory would otherwise ship the customer list.
$stray = array_values(array_filter(
    matching($files, '#\.sql$#i'),
    static fn (string $f): bool => preg_match('#^db/migrations/\d{4}_[a-z0-9_]+\.sql$#i', $f) !== 1,
));
check('only numbered migrations carry .sql', $stray === [], implode(', ', $stray));

// --- 2. everything that must be there --------------------------------------
echo "\n2. Everything the site needs\n";

$required = [
    'public_html/index.php',
    'public_html/.htaccess',
    // BOTH, always. A host reads .htaccess (mod_php) or .user.ini (LiteSpeed,
    // PHP-FPM) and never says which. Ship one of the pair and the live body
    // limits are the host's defaults - on Hostinger, post_max_size 128M at a
    // site whose largest honest request is a few hundred text fields.
    'public_html/.user.ini',
    'app/Core/Bootstrap.php',
    'app/Core/Config.php',
    'app/routes.php',
    'bin/cron.php',
    'config/config.sample.php',
    'db/migrations/0001_init.sql',
    'INSTALL.txt',
];
foreach ($required as $entry) {
    check("has $entry", in_array($entry, $files, true));
}

$writable = ['storage', 'storage/cache', 'storage/logs', 'storage/tmp', 'storage/uploads', 'var'];
foreach ($writable as $directory) {
    check("has the $directory directory", is_dir($temp . '/' . $directory));
}

// Empty means empty of DATA. A guard file is not data.
foreach (['storage/cache', 'storage/logs', 'storage/tmp', 'storage/uploads'] as $directory) {
    $contents = array_values(array_diff(scandir($temp . '/' . $directory) ?: [], ['.', '..', '.htaccess', 'index.html']));
    check("$directory carries no runtime data", $contents === [], implode(', ', $contents));
}

// --- 3. the layout is the security model -----------------------------------
echo "\n3. The layout\n";

check(
    'app/ is BESIDE public_html, not inside it',
    is_dir($temp . '/app') && !is_dir($temp . '/public_html/app'),
);
check(
    'config/ is BESIDE public_html, not inside it',
    is_dir($temp . '/config') && !is_dir($temp . '/public_html/config'),
);
check(
    'storage/ is BESIDE public_html, not inside it',
    is_dir($temp . '/storage') && !is_dir($temp . '/public_html/storage'),
);
check(
    'public_html holds the site itself, not a nested public/',
    !is_dir($temp . '/public_html/public'),
);
check(
    'no PHP outside public_html/index.php in the web root',
    matching($files, '#^public_html/[^/]+\.php$#') === ['public_html/index.php'],
    implode(', ', matching($files, '#^public_html/[^/]+\.php$#')),
);

// --- 4. the guards ----------------------------------------------------------
echo "\n4. The .htaccess guards\n";

foreach (['app', 'bin', 'config', 'db', 'db/migrations', 'storage',
    'storage/cache', 'storage/logs', 'storage/tmp', 'storage/uploads', 'var'] as $directory) {
    $guard = $temp . '/' . $directory . '/.htaccess';
    $body  = is_file($guard) ? (string) file_get_contents($guard) : '';
    check(
        "$directory/.htaccess denies everything",
        str_contains($body, 'Require all denied') || str_contains($body, 'Deny from all'),
    );
}

$webRoot = (string) file_get_contents($temp . '/public_html/.htaccess');
check('public_html/.htaccess routes to index.php', str_contains($webRoot, 'index.php'));
check('public_html/.htaccess turns off directory listing', str_contains($webRoot, '-Indexes'));
check('public_html/.htaccess denies dotfiles', str_contains($webRoot, '^\\.'));
check('public_html/.htaccess denies .sql', (bool) preg_match('/FilesMatch[^>]*\bsql\b/', $webRoot));

/*
 * The web server's own body cap, and the .user.ini that carries the same
 * numbers for a host that ignores php_value.
 *
 * Checked HERE, on the extracted archive, and not only in the source tree:
 * tests/Core/UserIniTest.php holds the two files in step, but this is the last
 * place anything looks at the bytes that are actually about to be uploaded.
 */
check(
    'public_html/.htaccess caps the request body (LimitRequestBody)',
    (bool) preg_match('/^\s*LimitRequestBody\s+\d+/mi', $webRoot)
);
check(
    'public_html/.htaccess keeps LimitRequestBody inside an <IfModule>',
    // Nothing between the directive and the start of the file closes more
    // IfModule blocks than it opens - i.e. the directive is nested. An
    // unsupported directive outside one is a 500 on every page of the site.
    (static function (string $text): bool {
        $depth = 0;
        foreach (preg_split('/\R/', $text) ?: [] as $raw) {
            $line = trim($raw);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (preg_match('/^<IfModule/i', $line) === 1) {
                $depth++;
            } elseif (preg_match('#^</IfModule>#i', $line) === 1) {
                $depth--;
            } elseif (preg_match('/^LimitRequestBody\b/i', $line) === 1 && $depth < 1) {
                return false;
            }
        }

        return true;
    })($webRoot)
);

$userIni = (string) file_get_contents($temp . '/public_html/.user.ini');
foreach (['upload_max_filesize', 'post_max_size', 'max_input_vars'] as $directive) {
    check(
        'public_html/.user.ini sets ' . $directive,
        (bool) preg_match('/^\s*' . preg_quote($directive, '/') . '\s*=/mi', $userIni)
    );
}
check(
    'public_html/.user.ini sets no PHP_INI_SYSTEM directive PHP would ignore',
    // Ignored, not refused - so the line stays in the file reading like a
    // setting that was made. That is the same lie as php_value under LiteSpeed.
    preg_match('/^\s*(expose_php|max_file_uploads|open_basedir|disable_functions|allow_url_fopen)\s*=/mi', $userIni) !== 1
);

// --- 5. every shipped .php parses ------------------------------------------
echo "\n5. Every shipped .php parses\n";

$php       = matching($files, '#\.php$#');
$binary    = PHP_BINARY;
$brokenPhp = [];

foreach ($php as $file) {
    $output = [];
    $status = 0;
    exec(escapeshellarg($binary) . ' -l ' . escapeshellarg($temp . '/' . $file) . ' 2>&1', $output, $status);
    if ($status !== 0) {
        $brokenPhp[] = $file . ' - ' . implode(' ', $output);
    }
}
check(count($php) . ' .php file(s) parse under ' . PHP_VERSION, $brokenPhp === [], implode('; ', array_slice($brokenPhp, 0, 3)));

// config.sample.php must return an array and must not have been filled in.
$sample = require $temp . '/config/config.sample.php';
check('config.sample.php returns an array', is_array($sample));
check('config.sample.php has no application key in it', ($sample['app']['key'] ?? '') === '');
check('config.sample.php has no database password in it', ($sample['db']['pass'] ?? '') === '');
check('config.sample.php has no cron token in it', ($sample['tokens']['cron'] ?? '') === '');

// --- 6. a last sweep for anything that reads like a secret ------------------
echo "\n6. A grep for things that look like secrets\n";

$suspects = [];
$secretish = [
    '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
    '/\bAIza[0-9A-Za-z_\-]{30,}/',                 // Google API key
    '/\bsk_live_[0-9A-Za-z]{10,}/',                // Stripe live key
    '/"private_key"\s*:/',                         // service-account JSON
];

foreach ($files as $file) {
    $full = $temp . '/' . $file;
    if (filesize($full) > 2 * 1024 * 1024) {
        continue;                                   // flag SVGs; not text we wrote
    }
    $body = (string) file_get_contents($full);
    foreach ($secretish as $pattern) {
        if (preg_match($pattern, $body) === 1) {
            $suspects[] = $file;
            break;
        }
    }
}
check('nothing matching a private key or API key pattern', $suspects === [], implode(', ', $suspects));

// ---------------------------------------------------------------------------
// Clean up and report
// ---------------------------------------------------------------------------

if ($keep) {
    echo "\nkept: $temp\n";
} else {
    removeTree($temp);
}

echo "\n" . str_repeat('-', 72) . "\n";
printf("%d check(s): %d passed, %d failed\n", count($passes) + count($failures), count($passes), count($failures));

if ($failures !== []) {
    echo "\nDO NOT UPLOAD THIS ARCHIVE.\n";
    foreach ($failures as $failure) {
        echo "  * $failure\n";
    }
    exit(EXIT_FAILED);
}

echo "\nThe archive is safe to upload.\n";
exit(EXIT_OK);

// ===========================================================================

function removeTree(string $directory): void
{
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        /** @var SplFileInfo $item */
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }

    @rmdir($directory);
}
