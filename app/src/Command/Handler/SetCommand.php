<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Handler;

use Campanella\Lite\Command\CommandHandler;
use Campanella\Lite\Command\CommandResult;
use Campanella\Lite\Command\Invocation;
use Campanella\Lite\Command\ProjectPaths;
use Campanella\Lite\Command\Signature;
use Campanella\Lite\Command\Workspace;

final class SetCommand implements CommandHandler
{
    public function __construct(private readonly ProjectPaths $paths = new ProjectPaths())
    {
    }

    public function signature(): Signature
    {
        return new Signature(
            name: 'set',
            arguments: ['path', 'value'],
            examples: [
                'set site.name "Example Ltd."',
                'set page.2.title "About us"',
                'set page./about.title.hu "Rólunk"',
                'set layout.default.body.main.width 9',
                'set page.2.description ""',
            ],
        );
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $value = $invocation->requiredArgument(1);
        $written = $this->paths->set($workspace, $invocation->requiredArgument(0), $value);

        if ($value === '') {
            return CommandResult::ok('ok.unset', ['path' => $written]);
        }

        return CommandResult::ok('ok.set', ['path' => $written, 'value' => $value]);
    }
}
