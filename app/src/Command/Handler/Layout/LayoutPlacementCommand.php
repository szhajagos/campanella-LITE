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

/**
 * "layout place" and "layout unplace": the default content of a layout column,
 * shown on every page that does not override the column.
 */
final class LayoutPlacementCommand implements CommandHandler
{
    public function __construct(private readonly bool $place)
    {
    }

    public function signature(): Signature
    {
        return $this->place
            ? new Signature(
                name: 'layout place',
                arguments: ['layout', 'column', 'content...'],
                options: ['at' => true],
                examples: ['layout place default footer.footer footer-text', 'layout place default body.sidebar news --at=1'],
            )
            : new Signature(
                name: 'layout unplace',
                arguments: ['layout', 'column', 'content'],
                examples: ['layout unplace default body.sidebar contact-box'],
            );
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $layout = Lookup::layout($workspace, $invocation->requiredArgument(0));
        $path = $invocation->requiredArgument(1);
        $column = Lookup::column($layout, $path);
        $params = ['target' => 'layout ' . $layout->name, 'column' => $path];

        if (!$this->place) {
            $name = $invocation->requiredArgument(2);

            if (!in_array($name, $column->content, true)) {
                throw CommandFailed::because('placement.not_present', ['content' => $name, 'column' => $path, 'page' => $layout->name]);
            }

            $content = array_values(array_filter($column->content, static fn (string $n): bool => $n !== $name));
            $workspace->putLayout($layout->withColumn($path, static fn (Column $c): Column => $c->withContent($content)));

            return CommandResult::ok('ok.unplaced', ['content' => $name] + $params);
        }

        $names = $invocation->rest(2);

        if ($names === []) {
            throw CommandFailed::because('command.missing_argument', ['argument' => 'content', 'usage' => $invocation->signature->usage()]);
        }

        foreach ($names as $name) {
            Lookup::content($workspace, $name);

            if (in_array($name, $column->content, true) || count(array_keys($names, $name, true)) > 1) {
                throw CommandFailed::because('placement.already_present', ['content' => $name, 'column' => $path, 'page' => $layout->name]);
            }
        }

        $content = Lookup::insert($column->content, Lookup::position($invocation->option('at'), count($column->content)), $names);
        $workspace->putLayout($layout->withColumn($path, static fn (Column $c): Column => $c->withContent($content)));

        return CommandResult::ok('ok.placed', ['content' => implode(', ', $names)] + $params);
    }
}
