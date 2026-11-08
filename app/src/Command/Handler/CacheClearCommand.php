<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Handler;

use Campanella\Lite\Command\CommandHandler;
use Campanella\Lite\Command\CommandResult;
use Campanella\Lite\Command\Invocation;
use Campanella\Lite\Command\Signature;
use Campanella\Lite\Command\Workspace;
use Campanella\Lite\Render\PageCache;

/**
 * Deletes the generated pages and published files; they are generated
 * again on their next visit.
 */
final class CacheClearCommand implements CommandHandler
{
    public function __construct(private readonly PageCache $cache, private readonly ?PageCache $files = null)
    {
    }

    public function signature(): Signature
    {
        return new Signature(name: 'cache clear', examples: ['cache clear']);
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        return CommandResult::deferred(
            'ok.cache_clear_planned',
            ['count' => count($this->cache->files()) + count($this->files?->files() ?? [])],
            fn (): CommandResult => CommandResult::ok('ok.cache_cleared', ['count' => $this->cache->clear() + ($this->files?->clear() ?? 0)]),
        );
    }
}
