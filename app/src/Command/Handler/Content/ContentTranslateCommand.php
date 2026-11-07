<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Handler\Content;

use Campanella\Lite\Command\CommandHandler;
use Campanella\Lite\Command\CommandResult;
use Campanella\Lite\Command\Handler\Support\Lookup;
use Campanella\Lite\Command\Invocation;
use Campanella\Lite\Command\Signature;
use Campanella\Lite\Command\TextInput;
use Campanella\Lite\Command\Workspace;
use Campanella\Lite\Model\ContentTranslation;

/**
 * Adds a language version, or replaces the title and body of an existing one.
 */
final class ContentTranslateCommand implements CommandHandler
{
    public function signature(): Signature
    {
        return new Signature(
            name: 'content translate',
            arguments: ['name', 'language'],
            options: ['title' => true],
            text: TextInput::Optional,
            examples: ["content translate intro hu --title=\"Bemutatkozás\" <<<\nKis csapatunk egyedi weboldalakat készít.\n>>>"],
        );
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $content = Lookup::content($workspace, $invocation->requiredArgument(0));
        $language = Lookup::language($workspace, $invocation->requiredArgument(1));
        $existing = $content->translation($language);
        $title = $existing === null ? Lookup::requiredOption($invocation, 'title') : ($invocation->option('title') ?? $existing->title);
        $body = $invocation->text() ?? $existing->body ?? '';

        $workspace->putContent($content->withTranslation(
            new ContentTranslation($language, $title, $body, $existing->format ?? ContentTranslation::DEFAULT_FORMAT),
        ));

        return CommandResult::ok('ok.content_translated', ['content' => $content->name, 'language' => $language]);
    }
}
