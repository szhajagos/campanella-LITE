<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Handler;

use Campanella\Lite\Command\CommandHandler;
use Campanella\Lite\Command\CommandResult;
use Campanella\Lite\Command\Handler\Support\Lookup;
use Campanella\Lite\Command\Invocation;
use Campanella\Lite\Command\Signature;
use Campanella\Lite\Command\Workspace;
use Campanella\Lite\Render\SiteBuilder;

/**
 * Generates published pages now, instead of on the first visit.
 * Runs after the batch has been written, so it sees the batch's changes.
 */
final class BuildCommand implements CommandHandler
{
    public function __construct(private readonly SiteBuilder $builder)
    {
    }

    public function signature(): Signature
    {
        return new Signature(name: 'build', arguments: ['page?'], examples: ['build', 'build /about']);
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $reference = $invocation->argument(0);
        $pageId = $reference === null ? null : Lookup::page($workspace, $reference)->id;
        $pages = static function () use ($pageId, $workspace): ?array {
            $page = $pageId === null ? null : $workspace->project()->page($pageId);

            return $pageId === null ? null : ($page === null ? [] : [$page]);
        };

        return CommandResult::deferred(
            'ok.build_planned',
            ['count' => $this->builder->count($workspace->project(), $pages())],
            fn (): CommandResult => CommandResult::ok('ok.built', ['count' => $this->builder->build($workspace->project(), $pages())]),
        );
    }
}
