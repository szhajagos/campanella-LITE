<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Handler\Page;

use Campanella\Lite\Command\CommandFailed;
use Campanella\Lite\Command\CommandHandler;
use Campanella\Lite\Command\CommandResult;
use Campanella\Lite\Command\Handler\Support\Lookup;
use Campanella\Lite\Command\Invocation;
use Campanella\Lite\Command\Signature;
use Campanella\Lite\Command\Workspace;

/**
 * "page place", "page unplace" and "page reset": what a page shows in a column.
 *
 * A page shows the layout's default content in a column until it places or
 * removes something there; from then on it has its own list for that
 * column. "page place" and "page unplace" start from what the page shows
 * now, so they add to or remove from the visible content. "page reset"
 * returns the column to the layout's defaults.
 */
final class PagePlacementCommand implements CommandHandler
{
    public const PLACE = 'place';
    public const UNPLACE = 'unplace';
    public const RESET = 'reset';

    /**
     * @param self::PLACE|self::UNPLACE|self::RESET $action
     */
    public function __construct(private readonly string $action)
    {
    }

    public function signature(): Signature
    {
        return match ($this->action) {
            self::PLACE => new Signature(
                name: 'page place',
                arguments: ['page', 'column', 'content...'],
                options: ['at' => true],
                examples: ['page place 2 body.main intro team', 'page place /about body.sidebar contact-box --at=1'],
            ),
            self::UNPLACE => new Signature(
                name: 'page unplace',
                arguments: ['page', 'column', 'content'],
                examples: ['page unplace 2 body.sidebar contact-box'],
            ),
            self::RESET => new Signature(
                name: 'page reset',
                arguments: ['page', 'column'],
                examples: ['page reset 2 body.sidebar'],
            ),
        };
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $page = Lookup::page($workspace, $invocation->requiredArgument(0));
        $columnPath = $invocation->requiredArgument(1);
        $column = Lookup::column(Lookup::layout($workspace, $page->layout), $columnPath);
        $placements = $page->placements;
        $current = $placements[$columnPath] ?? $column->content;
        $params = ['page' => $page->id, 'column' => $columnPath];

        if ($this->action === self::RESET) {
            unset($placements[$columnPath]);
            $workspace->putPage($page->with(placements: $placements));

            return CommandResult::ok('ok.page_reset', $params);
        }

        if ($this->action === self::UNPLACE) {
            $name = $invocation->requiredArgument(2);

            if (!in_array($name, $current, true)) {
                throw CommandFailed::because('placement.not_present', ['content' => $name] + $params);
            }

            $placements[$columnPath] = array_values(array_filter($current, static fn (string $n): bool => $n !== $name));
            $workspace->putPage($page->with(placements: $placements));

            return CommandResult::ok('ok.unplaced', ['content' => $name, 'target' => 'page ' . $page->id, 'column' => $columnPath]);
        }

        $names = $invocation->rest(2);

        if ($names === []) {
            throw CommandFailed::because('command.missing_argument', ['argument' => 'content', 'usage' => $invocation->signature->usage()]);
        }

        foreach ($names as $name) {
            Lookup::content($workspace, $name);

            if (in_array($name, $current, true) || count(array_keys($names, $name, true)) > 1) {
                throw CommandFailed::because('placement.already_present', ['content' => $name] + $params);
            }
        }

        $placements[$columnPath] = Lookup::insert($current, Lookup::position($invocation->option('at'), count($current)), $names);
        $workspace->putPage($page->with(placements: $placements));

        return CommandResult::ok('ok.placed', ['content' => implode(', ', $names), 'target' => 'page ' . $page->id, 'column' => $columnPath]);
    }
}
