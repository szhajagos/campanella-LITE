<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Handler\Content;

use Campanella\Lite\Command\CommandFailed;
use Campanella\Lite\Command\CommandHandler;
use Campanella\Lite\Command\CommandResult;
use Campanella\Lite\Command\Handler\Support\Lookup;
use Campanella\Lite\Command\Handler\Support\References;
use Campanella\Lite\Command\Invocation;
use Campanella\Lite\Command\Signature;
use Campanella\Lite\Command\Workspace;

/**
 * Deletes a content item, or with --lang only one of its language versions.
 */
final class ContentDeleteCommand implements CommandHandler
{
    public function signature(): Signature
    {
        return new Signature(
            name: 'content delete',
            arguments: ['name'],
            options: ['lang' => true, 'force' => false],
            examples: ['content delete old-news', 'content delete intro --lang=hu', 'content delete banner --force'],
        );
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $content = Lookup::content($workspace, $invocation->requiredArgument(0));
        $language = $invocation->option('lang');

        if ($language !== null) {
            if ($content->translation($language) === null) {
                throw CommandFailed::because('content.translation_not_found', ['content' => $content->name, 'language' => $language]);
            }

            if (count($content->translations) === 1) {
                throw CommandFailed::because('content.last_translation', ['content' => $content->name, 'language' => $language]);
            }

            $workspace->putContent($content->withoutTranslation($language));

            return CommandResult::ok('ok.translation_deleted', ['content' => $content->name, 'language' => $language]);
        }

        $references = References::toContent($workspace->project(), $content->name);
        Lookup::unlessReferenced($invocation, 'content.in_use', $content->name, $references);

        References::removeContent($workspace, $content->name);
        $workspace->removeContent($content->name);

        return CommandResult::ok(
            $references === [] ? 'ok.content_deleted' : 'ok.content_deleted_with_references',
            ['content' => $content->name, 'count' => count($references)],
        );
    }
}
