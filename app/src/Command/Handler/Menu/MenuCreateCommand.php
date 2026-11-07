<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Handler\Menu;

use Campanella\Lite\Command\CommandFailed;
use Campanella\Lite\Command\CommandHandler;
use Campanella\Lite\Command\CommandResult;
use Campanella\Lite\Command\Handler\Support\Lookup;
use Campanella\Lite\Command\Invocation;
use Campanella\Lite\Command\Signature;
use Campanella\Lite\Command\Workspace;
use Campanella\Lite\Model\Menu;

final class MenuCreateCommand implements CommandHandler
{
    public function signature(): Signature
    {
        return new Signature(name: 'menu create', arguments: ['name'], examples: ['menu create footer']);
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $name = Lookup::newName($invocation->requiredArgument(0));

        if ($workspace->project()->menu($name) !== null) {
            throw CommandFailed::because('menu.exists', ['menu' => $name]);
        }

        $workspace->putMenu(new Menu($name));

        return CommandResult::ok('ok.menu_created', ['menu' => $name]);
    }
}
