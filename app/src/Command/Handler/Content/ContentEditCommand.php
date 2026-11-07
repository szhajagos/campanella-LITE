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

/**
 * Replaces the body of an existing language version.
 */
final class ContentEditCommand implements CommandHandler
{
    public function signature(): Signature
    {
        return new Signature(
            name: 'content edit',
            arguments: ['name'],
            options: ['lang' => true],
            text: TextInput::Required,
            examples: ["content edit intro <<<\nThe new text.\n>>>", "content edit intro --lang=hu <<<\nAz új szöveg.\n>>>"],
        );
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $content = Lookup::content($workspace, $invocation->requiredArgument(0));
        $language = Lookup::language($workspace, $invocation->option('lang'));
        $translation = $content->translation($language)
            ?? throw CommandFailed::because('content.translation_not_found', ['content' => $content->name, 'language' => $language]);

        $workspace->putContent($content->withTranslation($translation->withBody($invocation->text() ?? '')));

        return CommandResult::ok('ok.content_edited', ['content' => $content->name, 'language' => $language]);
    }
}
