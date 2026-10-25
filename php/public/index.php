<?php

declare(strict_types=1);

/**
 * Speedat - front controller.
 *
 * This is the ONLY PHP file the internet can reach. On this host the web root
 * is `domains/<domain>/public_html` and the application sits BESIDE it in
 * `domains/<domain>/speedat_main/php`, so app/, config/, storage/ and db/ are
 * one level up and outside the document root - which is why a misconfigured
 * rewrite exposes a 404 rather than a password.
 *
 * Nothing but wiring happens here: find the application, load the autoloader,
 * start, register the routes, run. Anything that grows in this file belongs in
 * app/ - the canonical redirect, the routing and the error pages are all
 * `Speedat\Http\Kernel`, so that every one of them can be exercised by a test
 * without a web server (`tests/Http`).
 *
 * ASSETS DO NOT COME THROUGH HERE. `public_html/.htaccess` on LiteSpeed serves
 * anything that exists on disk and only sends what does not to this file. A
 * stylesheet never costs a PHP worker or a database connection.
 */

use Speedat\Core\Autoloader;
use Speedat\Core\Bootstrap;
use Speedat\Core\ConfigError;
use Speedat\Core\Router;
use Speedat\Http\Kernel;

/**
 * WHERE THE APPLICATION IS, WORKED OUT RATHER THAN WRITTEN DOWN.
 *
 * This used to be an absolute path typed into the file, and it cost an
 * afternoon: the host's file manager displays `domains/speedat.co/` as if it
 * were the home directory, so the path looked right in the file manager and
 * was wrong on disk by exactly one folder. Every request answered 500 and the
 * error said only "failed opening required".
 *
 * `dirname(__DIR__)` is this file's own parent's parent - whatever the account
 * is called, wherever the host mounts it. The application is its `speedat_main
 * /php` sibling. Move the account, rename the domain, migrate to another host:
 * as long as `public_html` and `speedat_main` stay next to each other, this
 * keeps working and nobody has to remember to edit a path.
 */
$root = dirname(__DIR__) . '/speedat_main/php';

$autoloader = $root . '/app/Core/Autoloader.php';

/**
 * SAY WHAT IS WRONG, IN A SENTENCE, RATHER THAN 500.
 *
 * A missing application is an installation mistake, not a bug, and it is the
 * one failure that happens before the logger, the config and the error pages
 * all exist. A bare 500 sends the operator to a server log they may not have;
 * this names the directory that is missing and what has to sit next to what.
 * It tells a visitor nothing, because a visitor never sees it: this state only
 * exists between uploading the web root and uploading the application.
 */
if (!is_file($autoloader)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    error_log('speedat: application not found at ' . $root);
    echo "This site is not installed yet.\n\n";
    echo "Expected the application at:\n  " . $root . "\n\n";
    echo "The `speedat_main` folder must sit NEXT TO `public_html`, not inside it.\n";
    exit;
}

/**
 * SAY WHICH SCRIPT THIS IS, BEFORE ANYTHING READS THE REQUEST.
 *
 * `Core\Request::fromGlobals()` works out how much of the URL is the
 * installation's own prefix from `dirname(SCRIPT_NAME)`, and strips that much
 * off the front of the path before the router ever sees it. That is right when
 * `SCRIPT_NAME` names this file, which is what Apache and LiteSpeed report for
 * every request their rewrite sends here.
 *
 * THE PHP BUILT-IN SERVER DOES NOT REPORT THAT. Running behind a router script
 * it sets `SCRIPT_NAME` to the REQUESTED URL whenever the URL looks like it has
 * a file extension - so `/admin/rates/export.csv` arrived with `SCRIPT_NAME` of
 * `/admin/rates/export.csv`, a prefix of `/admin/rates`, and a path of
 * `/export.csv` that matches no route. The CSV export answered 404 on the
 * development server and 200 on the real one, which is the worst shape a
 * difference between the two can take: it is invisible until somebody tries to
 * download the rates and cannot.
 *
 * So the front controller states what it is. A `SCRIPT_NAME` whose basename is
 * `index.php` is the server's honest answer and is left exactly alone - that is
 * the path a subdirectory install depends on. Anything else was pointed at the
 * request rather than at this file, and is replaced with this file's own
 * location under the document root.
 */
if (basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) !== 'index.php') {
    $docRoot = str_replace('\\', '/', (string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $self    = str_replace('\\', '/', __FILE__);
    $docRoot = rtrim($docRoot, '/');

    $_SERVER['SCRIPT_NAME'] = $docRoot !== '' && str_starts_with($self, $docRoot . '/')
        ? substr($self, strlen($docRoot))
        : '/index.php';
}

require $autoloader;
Autoloader::register($root . '/app');

try {
    $app = Bootstrap::web($root);
} catch (ConfigError $e) {
    // The application is not configured. This is the one failure that happens
    // before the logger exists, so it explains itself to the operator through
    // the host's error log and tells the visitor nothing at all. It cannot go
    // through Kernel: there is no config, so there is no document to render a
    // designed page from.
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    error_log('speedat config error: ' . $e->getMessage());
    echo "This site is not configured yet.\n";
    exit;
}

$router = new Router();

/** @var \Closure(Router):void $routes */
$routes = require $root . '/app/routes.php';
$routes($router);

Kernel::run($app, $router);
