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
use Campanella\Lite\Files\FileRepository;
use Campanella\Lite\Model\File;

/**
 * Removes archived files for good: all of them, or one. This cannot be
 * undone. Files that content still refers to are kept.
 *
 * The files are removed after the batch has been written, like "build";
 * nothing happens on a dry run or in a rejected batch.
 */
final class FilePurgeCommand implements CommandHandler
{
    public function __construct(private readonly FileRepository $files)
    {
    }

    public function signature(): Signature
    {
        return new Signature(name: 'file purge', arguments: ['file?'], examples: ['file purge', 'file purge 12']);
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $project = $workspace->project();
        $reference = $invocation->argument(0);

        if ($reference !== null) {
            $file = Lookup::file($workspace, $reference);

            if (!$file->isArchived()) {
                throw CommandFailed::because('file.not_archived', ['file' => $file->id]);
            }

            $candidates = [$file];
        } else {
            $candidates = array_values(array_filter($project->files, static fn (File $file): bool => $file->isArchived()));
        }

        $purge = [];
        $kept = [];

        foreach ($candidates as $file) {
            if (FileReferences::usersOf($project, $file->id) === []) {
                $purge[] = $file;
            } else {
                $kept[] = (string) $file->id;
            }
        }

        if ($reference !== null && $kept !== []) {
            throw CommandFailed::because('file.purge_in_use', ['file' => $reference, 'references' => implode(', ', FileReferences::usersOf($project, (int) $reference))]);
        }

        $user = $workspace->user;
        $repository = $this->files;

        $params = ['count' => count($purge), 'kept' => implode(', ', $kept)];

        return CommandResult::deferred(
            $kept === [] ? 'ok.files_purge_planned' : 'ok.files_purge_planned_some_kept',
            $params,
            static function () use ($purge, $repository, $user, $kept, $params): CommandResult {
                foreach ($purge as $file) {
                    $repository->purge($file, $user);
                }

                return CommandResult::ok($kept === [] ? 'ok.files_purged' : 'ok.files_purged_some_kept', $params);
            },
        );
    }
}
