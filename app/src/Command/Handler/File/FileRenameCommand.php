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
use Campanella\Lite\Files\FileTypes;
use Campanella\Lite\Model\File;

/**
 * Changes the file name shown to people. The stored file, the id and the
 * references in content stay the same. The extension is added when it is
 * missing and cannot be changed.
 */
final class FileRenameCommand implements CommandHandler
{
    public function signature(): Signature
    {
        return new Signature(
            name: 'file rename',
            arguments: ['file', 'filename'],
            examples: ['file rename 12 "conference-hall.jpg"', 'file rename 13 "Price list 2027"'],
        );
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $file = Lookup::file($workspace, $invocation->requiredArgument(0));
        $filename = trim($invocation->requiredArgument(1));
        $dot = strrpos($filename, '.');
        $extension = $dot === false ? '' : strtolower(substr($filename, $dot + 1));

        if ($extension !== $file->extension) {
            if (FileTypes::isKnown($extension) || in_array($extension, ['jpeg', 'aif', 'htm', 'html', 'svg', 'php'], true)) {
                throw CommandFailed::because('file.extension_change', ['file' => $file->id, 'extension' => $file->extension]);
            }

            $filename .= '.' . $file->extension;
        } else {
            $filename = substr($filename, 0, (int) $dot) . '.' . $file->extension;
        }

        if (!File::isValidFilename($filename, $file->extension)) {
            throw CommandFailed::because('file.invalid_filename', ['filename' => $filename, 'max' => File::MAX_FILENAME_LENGTH]);
        }

        $workspace->putFile($file->with(filename: $filename));

        return CommandResult::ok('ok.file_renamed', ['file' => $file->id, 'filename' => $filename]);
    }
}
