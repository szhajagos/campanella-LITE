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
use Campanella\Lite\Model\LocalizedText;
use Campanella\Lite\Model\MenuItem;

final class MenuItemAddCommand implements CommandHandler
{
    public function signature(): Signature
    {
        return new Signature(
            name: 'menu item add',
            arguments: ['menu'],
            options: ['label' => true, 'page' => true, 'url' => true, 'parent' => true, 'at' => true, 'lang' => true],
            examples: [
                'menu item add main --label="About" --page=/about',
                'menu item add main --label="Blog" --url=https://blog.example.com',
                'menu item add main --label="Team" --page=5 --parent=2 --at=1',
            ],
        );
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $menu = Lookup::menu($workspace, $invocation->requiredArgument(0));
        $label = Lookup::requiredOption($invocation, 'label');
        $language = Lookup::language($workspace, $invocation->option('lang'));
        $pageReference = $invocation->option('page');
        $url = $invocation->option('url');

        if (($pageReference === null) === ($url === null)) {
            throw CommandFailed::because('menu.item_target', ['usage' => $invocation->signature->usage()]);
        }

        $page = $pageReference === null ? null : Lookup::page($workspace, $pageReference)->id;
        $parentReference = $invocation->option('parent');
        $parent = $parentReference === null ? [] : MenuItems::position($menu, $parentReference);

        $item = new MenuItem(LocalizedText::of($language, $label), $page, $url);
        $changed = MenuItems::insert($menu, $parent, $invocation->option('at'), $item);
        $workspace->putMenu($changed);

        $siblings = MenuItems::childrenAt($changed, $parent);
        $index = array_search($item, $siblings, true);
        $position = implode('.', array_map(static fn (int $i): int => $i + 1, [...$parent, is_int($index) ? $index : count($siblings) - 1]));

        return CommandResult::ok('ok.menu_item_added', ['menu' => $menu->name, 'item' => $position]);
    }
}
