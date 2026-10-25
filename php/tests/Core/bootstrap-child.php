<?php

declare(strict_types=1);

/**
 * One deliberately broken process, for tests/Core/BootstrapCliTest.php.
 *
 *   SPEEDAT_CHILD_MODE=uncaught php     tests/Core/bootstrap-child.php
 *   SPEEDAT_CHILD_MODE=uncaught php-cgi tests/Core/bootstrap-child.php
 *
 * WHY A CHILD AT ALL. Bootstrap::lastResort() ends in exit(1) under CLI, and a
 * shutdown-function fatal cannot be provoked in-process without taking the test
 * runner down with it. The exit code is the entire point of the thing being
 * tested, and an exit code can only be read from outside.
 *
 * THE MODE COMES FROM THE ENVIRONMENT, not $argv: register_argc_argv is off by
 * default in the CGI SAPI, and the CGI SAPI is how the web half gets tested.
 *
 * Not a *Test.php file, so bin/test.php does not pick it up as a suite.
 */

use Speedat\Core\Autoloader;
use Speedat\Core\Bootstrap;

$root = dirname(__DIR__, 2);
require $root . '/app/Core/Autoloader.php';
Autoloader::register($root . '/app');

$mode = (string) getenv('SPEEDAT_CHILD_MODE');

// Both entry points install the same handlers; which one runs decides which
// half of lastResort() the failure comes out of.
if (PHP_SAPI === 'cli') {
    Bootstrap::cli($root);
} else {
    Bootstrap::web($root);
}

switch ($mode) {
    case 'ok':
        // The control. A healthy run must still print its own output and exit 0
        // with the handlers installed - a safety net that catches the acrobat
        // mid-act is not a safety net.
        echo 'child ok';
        exit(0);

    case 'uncaught':
        throw new \RuntimeException('deliberate: an uncaught exception in a child process');

    case 'warning':
        // A notice, which set_error_handler() turns into an ErrorException that
        // nothing catches. An unnoticed warning is how a wrong price ships.
        trigger_error('deliberate: a warning in a child process', E_USER_WARNING);
        echo 'this line must never be reached';
        exit(0);

    case 'fatal':
        // A REAL fatal, not an exception: memory exhaustion never reaches
        // set_exception_handler() and is only seen by the shutdown function.
        // The limit is set just above what this process has already used so the
        // next allocation is the one that fails.
        ini_set('memory_limit', (string) (memory_get_usage(true) + 1_048_576));
        $blob = str_repeat('x', 64_000_000);
        echo 'this line must never be reached either: ' . strlen($blob);
        exit(0);

    default:
        fwrite(defined('STDERR') ? STDERR : fopen('php://stderr', 'wb'), 'unknown SPEEDAT_CHILD_MODE' . PHP_EOL);
        exit(9);
}
