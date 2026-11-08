<?php

declare(strict_types=1);

/*
 * Campanella Lite admin entry point: everything under /admin.
 */

use Campanella\Lite\Admin\Session\NativeSession;
use Campanella\Lite\Application;
use Campanella\Lite\Http\Request;
use Campanella\Lite\Paths;

require dirname(__DIR__, 2) . '/app/vendor/autoload.php';

$request = Request::fromGlobals();

(new Application(new Paths(dirname(__DIR__, 2))))
    ->adminController(new NativeSession($request->secure))
    ->handle($request)
    ->send($request->method !== 'HEAD');
