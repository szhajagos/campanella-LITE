<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Handler\Layout;

use Campanella\Lite\Command\CommandFailed;
use Campanella\Lite\Command\CommandHandler;
use Campanella\Lite\Command\CommandResult;
use Campanella\Lite\Command\Handler\Support\Lookup;
use Campanella\Lite\Command\Invocation;
use Campanella\Lite\Command\Signature;
use Campanella\Lite\Command\Workspace;
use Campanella\Lite\Model\Layout;

/**
 * Creates an empty layout, or a copy of an existing one with --from.
 */
final class LayoutCreateCommand implements CommandHandler
{
    public function signature(): Signature
    {
        return new Signature(
            name: 'layout create',
            arguments: ['name'],
            options: ['from' => true, 'title' => true, 'lang' => true],
            examples: ['layout create landing --from=default --title="Landing page"', 'layout create blank'],
        );
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $name = Lookup::newName($invocation->requiredArgument(0));

        if ($workspace->project()->layout($name) !== null) {
            throw CommandFailed::because('layout.exists', ['layout' => $name]);
        }

        $from = $invocation->option('from');
        $layout = $from === null ? new Layout($name, []) : Lookup::layout($workspace, $from)->withName($name);
        $title = $invocation->option('title');

        if ($title !== null) {
            $layout = $layout->withTitle($layout->title->with(Lookup::language($workspace, $invocation->option('lang')), $title));
        }

        $workspace->putLayout($layout);

        return CommandResult::ok($from === null ? 'ok.layout_created' : 'ok.layout_copied', ['layout' => $name, 'from' => $from ?? '']);
    }
}
