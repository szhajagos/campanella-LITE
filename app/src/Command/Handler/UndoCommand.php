<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Handler;

use Campanella\Lite\Command\CommandHandler;
use Campanella\Lite\Command\CommandResult;
use Campanella\Lite\Command\Invocation;
use Campanella\Lite\Command\Signature;
use Campanella\Lite\Command\Workspace;

/**
 * Takes back the most recent applied batch.
 *
 * The interpreter runs it itself, because undo restores the files kept
 * before that batch instead of changing the project through the
 * workspace. This class only describes the command.
 */
final class UndoCommand implements CommandHandler
{
    public function signature(): Signature
    {
        return new Signature(
            name: 'undo',
            options: ['force' => false],
            examples: ['undo'],
            standalone: true,
        );
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        throw new \LogicException('The interpreter runs "undo" itself.');
    }
}
