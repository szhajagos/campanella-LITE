<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Handler\Content;

use Campanella\Lite\Command\CommandFailed;
use Campanella\Lite\Command\CommandHandler;
use Campanella\Lite\Command\CommandResult;
use Campanella\Lite\Command\Handler\Support\Lookup;
use Campanella\Lite\Command\Invocation;
use Campanella\Lite\Command\Signature;
use Campanella\Lite\Command\TextInput;
use Campanella\Lite\Command\Workspace;
use Campanella\Lite\Model\Content;
use Campanella\Lite\Model\ContentTranslation;

final class ContentCreateCommand implements CommandHandler
{
    public function signature(): Signature
    {
        return new Signature(
            name: 'content create',
            arguments: ['name'],
            options: ['title' => true, 'lang' => true],
            text: TextInput::Optional,
            examples: ["content create intro --title=\"Introduction\" <<<\nOur team builds **custom** websites.\n>>>"],
        );
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $name = Lookup::newName($invocation->requiredArgument(0));

        if ($workspace->project()->content($name) !== null) {
            throw CommandFailed::because('content.exists', ['content' => $name]);
        }

        $translation = new ContentTranslation(
            Lookup::language($workspace, $invocation->option('lang')),
            Lookup::requiredOption($invocation, 'title'),
            $invocation->text() ?? '',
        );
        $workspace->putContent(new Content($name, [$translation]));

        return CommandResult::ok('ok.content_created', ['content' => $name, 'language' => $translation->language]);
    }
}
