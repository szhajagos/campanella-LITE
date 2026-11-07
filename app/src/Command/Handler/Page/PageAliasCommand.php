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

final class PageAliasCommand implements CommandHandler
{
    public function signature(): Signature
    {
        return new Signature(
            name: 'page alias',
            arguments: ['page', 'alias?'],
            options: ['lang' => true, 'remove' => false],
            examples: ['page alias 2 /about', 'page alias 2 /rolunk --lang=hu', 'page alias /about --remove'],
        );
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $page = Lookup::page($workspace, $invocation->requiredArgument(0));
        $language = Lookup::language($workspace, $invocation->option('lang'));
        $alias = $invocation->argument(1);
        $path = sprintf('page.%d.alias.%s', $page->id, $language);

        if ($invocation->flag('remove')) {
            if ($alias !== null) {
                throw CommandFailed::because('command.too_many_arguments', ['argument' => $alias, 'usage' => $invocation->signature->usage()]);
            }

            $workspace->putPage($page->with(alias: $page->alias->without($language)));

            return CommandResult::ok('ok.unset', ['path' => $path]);
        }

        if ($alias === null) {
            throw CommandFailed::because('command.missing_argument', ['argument' => 'alias', 'usage' => $invocation->signature->usage()]);
        }

        $workspace->putPage($page->with(alias: $page->alias->with($language, $alias)));

        return CommandResult::ok('ok.set', ['path' => $path, 'value' => $alias]);
    }
}
