<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Handler\Menu;

use Campanella\Lite\Command\CommandHandler;
use Campanella\Lite\Command\CommandResult;
use Campanella\Lite\Command\Handler\Support\Lookup;
use Campanella\Lite\Command\Handler\Support\References;
use Campanella\Lite\Command\Invocation;
use Campanella\Lite\Command\Signature;
use Campanella\Lite\Command\Workspace;

final class MenuDeleteCommand implements CommandHandler
{
    public function signature(): Signature
    {
        return new Signature(
            name: 'menu delete',
            arguments: ['name'],
            options: ['force' => false],
            examples: ['menu delete footer', 'menu delete main --force'],
        );
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $menu = Lookup::menu($workspace, $invocation->requiredArgument(0));
        $references = References::toMenu($workspace->project(), $menu->name);
        Lookup::unlessReferenced($invocation, 'menu.in_use', $menu->name, $references);

        References::removeMenu($workspace, $menu->name);
        $workspace->removeMenu($menu->name);

        return CommandResult::ok('ok.menu_deleted', ['menu' => $menu->name]);
    }
}
