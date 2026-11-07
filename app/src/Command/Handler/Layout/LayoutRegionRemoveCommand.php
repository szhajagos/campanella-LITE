<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Handler\Layout;

use Campanella\Lite\Command\CommandHandler;
use Campanella\Lite\Command\CommandResult;
use Campanella\Lite\Command\Handler\Support\Lookup;
use Campanella\Lite\Command\Handler\Support\References;
use Campanella\Lite\Command\Invocation;
use Campanella\Lite\Command\Signature;
use Campanella\Lite\Command\Workspace;
use Campanella\Lite\Model\Region;

/**
 * Removes a region with its columns. Pages that place content into those
 * columns block the removal unless --force, which removes those placements.
 */
final class LayoutRegionRemoveCommand implements CommandHandler
{
    public function signature(): Signature
    {
        return new Signature(
            name: 'layout region remove',
            arguments: ['layout', 'region'],
            options: ['force' => false],
            examples: ['layout region remove default hero', 'layout region remove default hero --force'],
        );
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $layout = Lookup::layout($workspace, $invocation->requiredArgument(0));
        $region = Lookup::region($layout, $invocation->requiredArgument(1));
        $columns = array_map(static fn ($column): string => $region->name . '.' . $column->name, $region->columns);

        $references = References::toColumns($workspace->project(), $layout->name, $columns);
        Lookup::unlessReferenced($invocation, 'layout.region_in_use', $region->name, $references);

        References::removePlacements($workspace, $layout->name, $columns);
        $workspace->putLayout($layout->withRegions(array_values(array_filter(
            $layout->regions,
            static fn (Region $candidate): bool => $candidate->name !== $region->name,
        ))));

        return CommandResult::ok('ok.region_removed', ['layout' => $layout->name, 'region' => $region->name]);
    }
}
