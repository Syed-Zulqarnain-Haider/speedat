<?php

declare(strict_types=1);

/**
 * Local toolchain doctor for the Speedat PHP port.
 *
 * Checks that this machine's PHP can do everything the port needs WITHOUT
 * Composer and without any third-party package:
 *   - ZipArchive + XMLReader  -> hand-written .xlsx rate-sheet reader
 *   - openssl                 -> Firebase ID-token verification, TOTP secret encryption
 *   - pdo_mysql               -> the only database driver we use
 *   - mbstring / intl / gd    -> text handling, currency/date formatting, any image work
 *
 * It also asserts that the ini limits match the strictest Hostinger shared
 * hosting, because the whole point of this local setup is to hit those
 * ceilings here rather than in production.
 *
 * Usage:  D:\php\php.exe D:\speedat\php\bin\local\doctor.php
 */

const REQUIRED_EXTENSIONS = [
    'curl',
    'fileinfo',
    'gd',
    'intl',
    'mbstring',
    'mysqli',
    'openssl',
    'pdo_mysql',
    'zip',
    // bundled in core, but assert anyway - the xlsx reader needs them
    'dom',
    'json',
    'session',
    'xmlreader',
];

/**
 * ini setting => expected effective value (as ini_get() returns it)
 *
 * post_max_size is 9M against an 8M upload_max_filesize on purpose, and is not
 * a typo. post_max_size caps the WHOLE request body, so with the two equal a
 * file at exactly the documented limit makes a body slightly over it and PHP
 * discards everything: empty $_POST, empty $_FILES, no error code, and a form
 * that appears to have done nothing. public/.htaccess and
 * deploy/htaccess/user.ini.sample carry the same pair.
 */
const REQUIRED_INI = [
    'date.timezone'       => 'Asia/Karachi',
    'memory_limit'        => '128M',
    'max_execution_time'  => '30',
    'upload_max_filesize' => '8M',
    'post_max_size'       => '9M',
];

$failures = [];
$warnings = [];

function ok(string $label, string $detail = ''): void
{
    printf("  [ OK ]  %-34s %s\n", $label, $detail);
}

function bad(string $label, string $detail = ''): void
{
    global $failures;
    $failures[] = $label . ($detail !== '' ? ' - ' . $detail : '');
    printf("  [FAIL]  %-34s %s\n", $label, $detail);
}

function warn(string $label, string $detail = ''): void
{
    global $warnings;
    $warnings[] = $label . ($detail !== '' ? ' - ' . $detail : '');
    printf("  [WARN]  %-34s %s\n", $label, $detail);
}

/**
 * Last uncommented assignment of $key in the loaded php.ini, or null.
 * php.ini semantics: the last assignment wins.
 */
function ini_file_value(string $key): ?string
{
    $file = php_ini_loaded_file();
    if ($file === false || !is_readable($file)) {
        return null;
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return null;
    }

    $found   = null;
    $pattern = '/^\s*' . preg_quote($key, '/') . '\s*=\s*(.*?)\s*$/';
    foreach ($lines as $line) {
        if (preg_match($pattern, $line, $m) === 1) {
            $found = trim($m[1], " \t\"'");
        }
    }

    return $found;
}

echo "\n== Speedat PHP port :: local toolchain doctor ==\n\n";

echo "PHP runtime\n";
ok('version', PHP_VERSION . ' (' . PHP_OS_FAMILY . ', ' . (PHP_INT_SIZE * 8) . '-bit)');
ok('thread safety', ZEND_THREAD_SAFE ? 'ZTS (unexpected)' : 'NTS');
ok('php.ini', php_ini_loaded_file() !== false ? (string) php_ini_loaded_file() : '(none)');

if (PHP_VERSION_ID < 80200) {
    bad('minimum version', 'need PHP 8.2+, found ' . PHP_VERSION);
} elseif (PHP_VERSION_ID >= 80500) {
    warn('version drift', PHP_VERSION . ' is newer than any Hostinger PHP selector option');
}

echo "\nExtensions\n";
foreach (REQUIRED_EXTENSIONS as $ext) {
    if (extension_loaded($ext)) {
        ok($ext, (string) (phpversion($ext) ?: 'bundled'));
    } else {
        bad($ext, 'not loaded');
    }
}
if (extension_loaded('Zend OPcache') || function_exists('opcache_get_status')) {
    ok('opcache', 'present (enable_cli is intentionally off)');
} else {
    warn('opcache', 'not loaded - fine locally, wanted on the server');
}

echo "\nini limits (must equal the strictest shared host)\n";
$sapi = php_sapi_name();
foreach (REQUIRED_INI as $key => $expected) {
    // The CLI SAPI hard-overrides max_execution_time to 0 (unlimited) no matter
    // what php.ini says, and it does so early enough that ini_get() AND
    // get_cfg_var() both report 0. The only honest source under CLI is the
    // php.ini file itself - that is the value the web SAPI will apply.
    $actual = (string) ini_get($key);
    $note   = '';
    if ($key === 'max_execution_time' && $sapi === 'cli') {
        $actual = ini_file_value($key) ?? $actual;
        $note   = ' (from php.ini; the cli SAPI itself always runs unlimited)';
    }

    if ($actual === $expected) {
        ok($key, $actual . $note);
    } else {
        bad($key, "expected {$expected}, got " . ($actual === '' ? '(empty)' : $actual));
    }
}

echo "\nCapabilities the port depends on\n";

class_exists('ZipArchive')
    ? ok('ZipArchive', 'xlsx container reading')
    : bad('ZipArchive', 'required to read .xlsx rate sheets');

class_exists('XMLReader')
    ? ok('XMLReader', 'streaming sheet parse under 128M')
    : bad('XMLReader', 'required to parse sheet xml without loading it whole');

in_array('aes-256-gcm', openssl_get_cipher_methods(), true)
    ? ok('openssl aes-256-gcm', 'TOTP secret encryption')
    : bad('openssl aes-256-gcm', 'required to encrypt TOTP secrets at rest');

function_exists('openssl_verify')
    ? ok('openssl_verify', 'Firebase ID-token RS256 check')
    : bad('openssl_verify', 'required to verify Google-signed ID tokens');

function_exists('hash_hmac') && in_array('sha1', hash_hmac_algos(), true)
    ? ok('hash_hmac sha1', 'TOTP (RFC 6238)')
    : bad('hash_hmac sha1', 'required for TOTP codes');

defined('PASSWORD_ARGON2ID')
    ? ok('password_hash', 'argon2id available')
    : ok('password_hash', 'bcrypt only (argon2id absent) - acceptable');

in_array('mysql', PDO::getAvailableDrivers(), true)
    ? ok('PDO drivers', implode(', ', PDO::getAvailableDrivers()))
    : bad('PDO drivers', 'mysql driver missing: ' . implode(', ', PDO::getAvailableDrivers()));

echo "\nTimezone arithmetic (delivery estimates)\n";
$tz  = new DateTimeZone(date_default_timezone_get());
$now = new DateTimeImmutable('now', $tz);
ok('default timezone', $tz->getName());
ok('now', $now->format('Y-m-d H:i:s P'));

echo "\n";
if ($failures !== []) {
    echo 'RESULT: FAIL (' . count($failures) . " problem(s))\n";
    foreach ($failures as $f) {
        echo '  - ' . $f . "\n";
    }
    exit(1);
}

echo 'RESULT: PASS' . ($warnings !== [] ? ' with ' . count($warnings) . ' warning(s)' : '') . "\n";
foreach ($warnings as $w) {
    echo '  - ' . $w . "\n";
}
exit(0);
