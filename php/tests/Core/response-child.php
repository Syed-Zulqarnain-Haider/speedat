<?php

declare(strict_types=1);

/**
 * What Response decides about Content-Length, reported from a process whose
 * compression settings are real. For tests/Core/ResponseTest.php.
 *
 *   php                                 tests/Core/response-child.php
 *   php -d zlib.output_compression=1    tests/Core/response-child.php
 *   SPEEDAT_RESPONSE_CASE=gzhandler php tests/Core/response-child.php
 *
 * WHY A CHILD. zlib.output_compression is PHP_INI_ALL on paper but changing it
 * after the first byte of output has no effect, and the whole question is what
 * happens on a host where it was on from the start. ini_set() inside the test
 * runner would prove something about a flag, not about a request.
 *
 * The answer goes to STDERR as one line of JSON, deliberately: if PHP or a
 * buffer handler mangles stdout - which is the very thing being detected - the
 * measurement must not be mangled with it.
 *
 * Not a *Test.php file, so bin/test.php does not pick it up as a suite.
 */

use Speedat\Core\Autoloader;
use Speedat\Core\Bootstrap;
use Speedat\Core\Response;

$root = dirname(__DIR__, 2);
require $root . '/app/Core/Autoloader.php';
Autoloader::register($root . '/app');
Bootstrap::cli($root);

if ((string) getenv('SPEEDAT_RESPONSE_CASE') === 'gzhandler') {
    // The second compressor Response has to notice: not the ini flag, but a
    // gzipping buffer handler somebody started.
    ob_start('ob_gzhandler');
}

// Deliberately not ASCII: Content-Length counts BYTES, and a body with any Urdu
// in it is longer in bytes than in characters. A Content-Length taken from
// mb_strlen() would under-promise and the browser would render a page cut short
// by exactly the difference.
$body     = 'کراچی to London, and back again';
$response = Response::html($body);

$report = [
    'zlib'     => (string) ini_get('zlib.output_compression'),
    'handlers' => array_map('strval', ob_list_handlers()),
    'survives' => Response::bodyLengthSurvives(),
    'bytes'    => strlen($body),
    'chars'    => mb_strlen($body, 'UTF-8'),
    'headers'  => $response->headersToSend(),
    'headHeaders' => $response->headersToSend(false),
];

fwrite(STDERR, json_encode($report, JSON_UNESCAPED_SLASHES) . PHP_EOL);

exit(0);
