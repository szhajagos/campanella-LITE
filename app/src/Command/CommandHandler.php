<?php

declare(strict_types=1);

namespace Campanella\Lite\Command;

/**
 * One command of the command language.
 *
 * A handler reads and changes the project only through the workspace and
 * reports failure by throwing CommandFailed. It never writes files: the
 * interpreter writes everything at the end, if the whole batch succeeded.
 */
interface CommandHandler
{
    public function signature(): Signature;

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult;
}
