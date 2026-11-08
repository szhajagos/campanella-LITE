<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Handler\File;

use Campanella\Lite\Command\CommandFailed;
use Campanella\Lite\Command\CommandHandler;
use Campanella\Lite\Command\CommandResult;
use Campanella\Lite\Command\Handler\Support\Lookup;
use Campanella\Lite\Command\Invocation;
use Campanella\Lite\Command\Signature;
use Campanella\Lite\Command\Workspace;

/**
 * Brings back an archived file.
 */
final class FileRestoreCommand implements CommandHandler
{
    public function signature(): Signature
    {
        return new Signature(name: 'file restore', arguments: ['file'], examples: ['file restore 12']);
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $file = Lookup::file($workspace, $invocation->requiredArgument(0));

        if (!$file->isArchived()) {
            throw CommandFailed::because('file.not_archived', ['file' => $file->id]);
        }

        $workspace->putFile($file->with(archivedAt: ''));

        return CommandResult::ok('ok.file_restored', ['file' => $file->id, 'filename' => $file->filename]);
    }
}
