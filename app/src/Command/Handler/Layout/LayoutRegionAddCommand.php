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
use Campanella\Lite\Model\ContainerType;
use Campanella\Lite\Model\Layout;
use Campanella\Lite\Model\Region;

/**
 * Adds an empty region (a Bootstrap row) to a layout, at the bottom or next to another region.
 */
final class LayoutRegionAddCommand implements CommandHandler
{
    public function signature(): Signature
    {
        return new Signature(
            name: 'layout region add',
            arguments: ['layout', 'region'],
            options: ['after' => true, 'before' => true, 'container' => true],
            examples: ['layout region add default hero --after=nav --container=fluid', 'layout region add default notice --before=footer'],
        );
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $layout = Lookup::layout($workspace, $invocation->requiredArgument(0));
        $name = Lookup::newName($invocation->requiredArgument(1));
        Lookup::exclusive($invocation, 'after', 'before');

        if ($layout->region($name) !== null) {
            throw CommandFailed::because('layout.region_exists', ['layout' => $layout->name, 'region' => $name]);
        }

        $container = ContainerType::from(Lookup::choice($invocation->option('container') ?? 'fixed', ['fixed', 'fluid']));
        $index = self::index($layout, $invocation->option('after'), $invocation->option('before'));
        $regions = Lookup::insert($layout->regions, $index, [new Region($name, [], $container)]);
        $workspace->putLayout($layout->withRegions($regions));

        return CommandResult::ok('ok.region_added', ['layout' => $layout->name, 'region' => $name]);
    }

    private static function index(Layout $layout, ?string $after, ?string $before): int
    {
        $anchor = $after ?? $before;

        if ($anchor === null) {
            return count($layout->regions);
        }

        foreach ($layout->regions as $index => $region) {
            if ($region->name === $anchor) {
                return $after !== null ? $index + 1 : $index;
            }
        }

        throw CommandFailed::because('layout.region_not_found', ['layout' => $layout->name, 'region' => $anchor]);
    }
}
