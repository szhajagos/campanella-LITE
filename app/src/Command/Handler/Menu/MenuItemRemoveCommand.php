<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Handler\Menu;

use Campanella\Lite\Command\CommandHandler;
use Campanella\Lite\Command\CommandResult;
use Campanella\Lite\Command\Handler\Support\Lookup;
use Campanella\Lite\Command\Invocation;
use Campanella\Lite\Command\Signature;
use Campanella\Lite\Command\Workspace;

/**
 * Removes a menu item together with its children.
 */
final class MenuItemRemoveCommand implements CommandHandler
{
    public function signature(): Signature
    {
        return new Signature(name: 'menu item remove', arguments: ['menu', 'item'], examples: ['menu item remove main 3', 'menu item remove main 2.1']);
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $menu = Lookup::menu($workspace, $invocation->requiredArgument(0));
        $item = $invocation->requiredArgument(1);
        $workspace->putMenu(MenuItems::remove($menu, MenuItems::position($menu, $item)));

        return CommandResult::ok('ok.menu_item_removed', ['menu' => $menu->name, 'item' => $item]);
    }
}
