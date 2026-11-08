<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Handler;

use Campanella\Lite\Command\CommandFailed;
use Campanella\Lite\Command\CommandHandler;
use Campanella\Lite\Command\CommandResult;
use Campanella\Lite\Command\Invocation;
use Campanella\Lite\Command\Signature;
use Campanella\Lite\Command\Workspace;

final class RedirectRemoveCommand implements CommandHandler
{
    public function signature(): Signature
    {
        return new Signature(name: 'redirect remove', arguments: ['path'], examples: ['redirect remove /about-us', 'redirect remove /hu/regi-cim']);
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $path = $invocation->requiredArgument(0);
        $redirects = $workspace->project()->redirects;

        if ($redirects->find($path) === null) {
            throw CommandFailed::because('redirect.not_found', ['path' => $path]);
        }

        $workspace->putRedirects($redirects->without($path));

        return CommandResult::ok('ok.redirect_removed', ['path' => $path]);
    }
}
