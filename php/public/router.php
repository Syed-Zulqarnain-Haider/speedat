<?php

declare(strict_types=1);

/**
 * Router for the PHP built-in web server (development only).
 *
 * Hostinger runs Apache, where public/.htaccess sends every request that is
 * not an existing file to index.php. `php -S` has no .htaccess, so this file
 * reproduces that one rule and nothing else.
 *
 * Returning false tells the built-in server to serve the file itself.
 *
 * This file is NOT uploaded to the server - it exists only for serve.ps1.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!is_string($path) || $path === '') {
    $path = '/';
}

$path = rawurldecode($path);

// Refuse traversal outright rather than letting realpath sort it out.
if (str_contains($path, "\0") || str_contains($path, '..')) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Bad request\n";
    return true;
}

$docRoot = realpath(__DIR__);
$target  = realpath($docRoot . str_replace('/', DIRECTORY_SEPARATOR, $path));

if ($target !== false && is_file($target) && str_starts_with($target, (string) $docRoot . DIRECTORY_SEPARATOR)) {
    // Never hand out the router or the Apache config through the dev server.
    $basename = strtolower(basename($target));
    if ($basename === 'router.php' || $basename === '.htaccess') {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Not found\n";
        return true;
    }

    return false;
}

require __DIR__ . '/index.php';
return true;
