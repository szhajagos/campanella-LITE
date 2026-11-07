<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Handler\Layout;

use Campanella\Lite\Command\CommandFailed;
use Campanella\Lite\Command\CommandHandler;
use Campanella\Lite\Command\CommandResult;
use Campanella\Lite\Command\Handler\Support\Lookup;
use Campanella\Lite\Command\Handler\Support\References;
use Campanella\Lite\Command\Invocation;
use Campanella\Lite\Command\Signature;
use Campanella\Lite\Command\Workspace;

/**
 * Deletes an unused layout. There is no --force: pages cannot be left without a layout.
 */
final class LayoutDeleteCommand implements CommandHandler
{
    public function signature(): Signature
    {
        return new Signature(name: 'layout delete', arguments: ['name'], examples: ['layout delete landing']);
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $layout = Lookup::layout($workspace, $invocation->requiredArgument(0));
        $references = References::toLayout($workspace->project(), $layout->name);

        if ($references !== []) {
            throw CommandFailed::because('layout.in_use', ['name' => $layout->name, 'references' => implode(', ', array_slice($references, 0, 5))]);
        }

        $workspace->removeLayout($layout->name);

        return CommandResult::ok('ok.layout_deleted', ['layout' => $layout->name]);
    }
}
