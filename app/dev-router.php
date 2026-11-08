<?php

declare(strict_types=1);

/*
 * Router for PHP's built-in web server, for development only. It mirrors
 * public/.htaccess: /admin goes to the admin, files are served as they are,
 * generated pages from {path}/index.html, and everything else goes to the
 * front controller.
 *
 *     php -S localhost:8000 -t public app/dev-router.php
 */

$public = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') ?: dirname(__DIR__) . '/public';
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

if ($path === '/admin' || str_starts_with($path, '/admin/')) {
    require $public . '/admin/index.php';

    return true;
}

if (!str_contains($path, '..') && !str_contains($path, '/.')) {
    $file = $public . $path;

    if (is_file($file) && basename($file) !== 'index.php') {
        return false;
    }

    $index = rtrim($file, '/') . '/index.html';

    if (is_file($index)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($index);

        return true;
    }
}

require $public . '/index.php';

return true;
