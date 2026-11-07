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
use Campanella\Lite\Model\Column;
use Campanella\Lite\Model\Region;

final class LayoutColumnRemoveCommand implements CommandHandler
{
    public function signature(): Signature
    {
        return new Signature(
            name: 'layout column remove',
            arguments: ['layout', 'column'],
            options: ['force' => false],
            examples: ['layout column remove default body.sidebar', 'layout column remove default body.sidebar --force'],
        );
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $layout = Lookup::layout($workspace, $invocation->requiredArgument(0));
        $path = $invocation->requiredArgument(1);
        $column = Lookup::column($layout, $path);
        $regionName = explode('.', $path, 2)[0];

        $references = References::toColumns($workspace->project(), $layout->name, [$path]);
        Lookup::unlessReferenced($invocation, 'layout.column_in_use', $path, $references);

        References::removePlacements($workspace, $layout->name, [$path]);
        $workspace->putLayout($layout->withRegions(array_map(
            static fn (Region $region): Region => $region->name !== $regionName ? $region : $region->withColumns(array_values(array_filter(
                $region->columns,
                static fn (Column $candidate): bool => $candidate->name !== $column->name,
            ))),
            $layout->regions,
        )));

        return CommandResult::ok('ok.column_removed', ['layout' => $layout->name, 'column' => $path]);
    }
}
