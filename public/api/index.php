<?php

declare(strict_types=1);

/*
 * Campanella Lite API entry point: everything under /api, for AI users.
 */

use Campanella\Lite\Application;
use Campanella\Lite\Http\Request;
use Campanella\Lite\Paths;

require dirname(__DIR__, 2) . '/app/vendor/autoload.php';

$request = Request::fromGlobals();

(new Application(new Paths(dirname(__DIR__, 2))))
    ->apiController()
    ->handle($request)
    ->send($request->method !== 'HEAD');
