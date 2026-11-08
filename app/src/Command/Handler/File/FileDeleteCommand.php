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
use Campanella\Lite\Files\FileReferences;

/**
 * Archives a file: it disappears from the website and from "list files",
 * but "undo" or "file restore" brings it back. A file that content still
 * refers to is only archived with --force. "file purge" removes archived
 * files for good.
 */
final class FileDeleteCommand implements CommandHandler
{
    public function signature(): Signature
    {
        return new Signature(
            name: 'file delete',
            arguments: ['file'],
            options: ['force' => false],
            examples: ['file delete 12', 'file delete 12 --force'],
        );
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $file = Lookup::file($workspace, $invocation->requiredArgument(0));

        if ($file->isArchived()) {
            throw CommandFailed::because('file.already_archived', ['file' => $file->id]);
        }

        $references = FileReferences::usersOf($workspace->project(), $file->id);
        Lookup::unlessReferenced($invocation, 'file.in_use', (string) $file->id, $references);

        $workspace->putFile($file->with(archivedAt: (new \DateTimeImmutable())->format('c')));

        return CommandResult::ok(
            $references === [] ? 'ok.file_archived' : 'ok.file_archived_in_use',
            ['file' => $file->id, 'filename' => $file->filename, 'count' => count($references)],
        );
    }
}
