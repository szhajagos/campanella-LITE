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
use Campanella\Lite\Model\Column;
use Campanella\Lite\Model\Region;

/**
 * Adds a column to a region, addressed as "region.column".
 */
final class LayoutColumnAddCommand implements CommandHandler
{
    public function signature(): Signature
    {
        return new Signature(
            name: 'layout column add',
            arguments: ['layout', 'column'],
            options: ['width' => true, 'after' => true, 'before' => true, 'menu' => true],
            examples: ['layout column add default hero.intro --width=12', 'layout column add default body.aside --width=3 --before=main'],
        );
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $layout = Lookup::layout($workspace, $invocation->requiredArgument(0));
        $path = $invocation->requiredArgument(1);
        [$regionName, $columnName] = array_pad(explode('.', $path, 2), 2, '');

        if ($columnName === '') {
            throw CommandFailed::because('value.invalid_column_path', ['column' => $path]);
        }

        $region = Lookup::region($layout, $regionName);
        Lookup::newName($columnName);
        Lookup::exclusive($invocation, 'after', 'before');

        if ($region->column($columnName) !== null) {
            throw CommandFailed::because('layout.column_exists', ['layout' => $layout->name, 'column' => $path]);
        }

        $width = Lookup::integer(Lookup::requiredOption($invocation, 'width'));
        $menu = $invocation->option('menu');

        if ($menu !== null) {
            Lookup::menu($workspace, $menu);
        }

        $index = self::index($region, $layout->name, $invocation->option('after'), $invocation->option('before'));
        $columns = Lookup::insert($region->columns, $index, [new Column($columnName, $width, [], $menu)]);
        $workspace->putLayout($layout->withRegions(array_map(
            static fn (Region $candidate): Region => $candidate->name === $region->name ? $candidate->withColumns($columns) : $candidate,
            $layout->regions,
        )));

        return CommandResult::ok('ok.column_added', ['layout' => $layout->name, 'column' => $path, 'width' => $width]);
    }

    private static function index(Region $region, string $layout, ?string $after, ?string $before): int
    {
        $anchor = $after ?? $before;

        if ($anchor === null) {
            return count($region->columns);
        }

        foreach ($region->columns as $index => $column) {
            if ($column->name === $anchor) {
                return $after !== null ? $index + 1 : $index;
            }
        }

        throw CommandFailed::because('layout.column_not_found', ['layout' => $layout, 'column' => $region->name . '.' . $anchor]);
    }
}
