<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Handler\Page;

use Campanella\Lite\Command\CommandHandler;
use Campanella\Lite\Command\CommandResult;
use Campanella\Lite\Command\Handler\Support\Lookup;
use Campanella\Lite\Command\Handler\Support\References;
use Campanella\Lite\Command\Invocation;
use Campanella\Lite\Command\Signature;
use Campanella\Lite\Command\Workspace;

final class PageDeleteCommand implements CommandHandler
{
    public function signature(): Signature
    {
        return new Signature(
            name: 'page delete',
            arguments: ['page'],
            options: ['force' => false],
            examples: ['page delete 7', 'page delete /old-offer --force'],
        );
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $page = Lookup::page($workspace, $invocation->requiredArgument(0));
        $references = References::toPage($workspace->project(), $page->id);
        Lookup::unlessReferenced($invocation, 'page.in_use', (string) $page->id, $references);

        References::removePage($workspace, $page->id);
        $workspace->removePage($page->id);

        return CommandResult::ok(
            $references === [] ? 'ok.page_deleted' : 'ok.page_deleted_with_references',
            ['page' => $page->id, 'count' => count($references)],
        );
    }
}
