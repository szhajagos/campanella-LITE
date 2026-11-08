<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Handler\File;

use Campanella\Lite\Command\CommandHandler;
use Campanella\Lite\Command\CommandResult;
use Campanella\Lite\Command\Handler\Support\Lookup;
use Campanella\Lite\Command\Invocation;
use Campanella\Lite\Command\Signature;
use Campanella\Lite\Command\Workspace;

/**
 * Sets the description of a file in one language. For images it is also
 * the alt text. An empty description removes that language.
 */
final class FileDescribeCommand implements CommandHandler
{
    public function signature(): Signature
    {
        return new Signature(
            name: 'file describe',
            arguments: ['file', 'description'],
            options: ['lang' => true],
            examples: ['file describe 12 "The conference hall"', 'file describe 12 --lang=hu "Az előadóterem"', 'file describe 12 --lang=hu ""'],
        );
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $file = Lookup::file($workspace, $invocation->requiredArgument(0));
        $language = Lookup::language($workspace, $invocation->option('lang'));
        $description = trim($invocation->requiredArgument(1));

        $workspace->putFile($file->with(description: $description === ''
            ? $file->description->without($language)
            : $file->description->with($language, $description)));

        return CommandResult::ok(
            $description === '' ? 'ok.file_description_removed' : 'ok.file_described',
            ['file' => $file->id, 'language' => $language],
        );
    }
}
