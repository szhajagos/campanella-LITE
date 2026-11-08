<?php

declare(strict_types=1);

/*
 * Campanella Lite front controller.
 *
 * The web server serves generated pages directly; only requests for pages
 * that have not been generated yet (or do not exist) reach this file.
 */

use Campanella\Lite\Application;
use Campanella\Lite\Paths;

require dirname(__DIR__) . '/app/vendor/autoload.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$response = (new Application(new Paths(dirname(__DIR__))))
    ->frontController()
    ->handle($method, $_SERVER['REQUEST_URI'] ?? '/');

$response->send($method !== 'HEAD');
