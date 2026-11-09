<?php

declare(strict_types=1);

/*
 * Campanella Lite MCP entry point: /mcp, for AI clients (Claude and others).
 */

use Campanella\Lite\Ai\McpServer;
use Campanella\Lite\Application;
use Campanella\Lite\Http\Request;
use Campanella\Lite\Paths;

require dirname(__DIR__, 2) . '/app/vendor/autoload.php';

$request = Request::fromGlobals(McpServer::MAX_BODY);

(new Application(new Paths(dirname(__DIR__, 2))))
    ->mcpServer()
    ->handle($request)
    ->send($request->method !== 'HEAD');
