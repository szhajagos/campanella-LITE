<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Handler\Language;

use Campanella\Lite\Command\CommandFailed;
use Campanella\Lite\Command\CommandHandler;
use Campanella\Lite\Command\CommandResult;
use Campanella\Lite\Command\Handler\Support\Lookup;
use Campanella\Lite\Command\Handler\Support\References;
use Campanella\Lite\Command\Invocation;
use Campanella\Lite\Command\Signature;
use Campanella\Lite\Command\Workspace;
use Campanella\Lite\Validation\ProjectValidator;

/**
 * "language add", "language remove" and "language default": the website's languages.
 */
final class LanguageCommand implements CommandHandler
{
    public const ADD = 'add';
    public const REMOVE = 'remove';
    public const DEFAULT = 'default';

    /**
     * @param self::ADD|self::REMOVE|self::DEFAULT $action
     */
    public function __construct(private readonly string $action)
    {
    }

    public function signature(): Signature
    {
        return match ($this->action) {
            self::ADD => new Signature(name: 'language add', arguments: ['language'], options: ['default' => false], examples: ['language add hu', 'language add de --default']),
            self::REMOVE => new Signature(name: 'language remove', arguments: ['language'], options: ['force' => false], examples: ['language remove de', 'language remove de --force']),
            self::DEFAULT => new Signature(name: 'language default', arguments: ['language'], examples: ['language default hu']),
        };
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $language = $invocation->requiredArgument(0);
        $site = $workspace->project()->site;
        $enabled = in_array($language, $site->languages, true);

        if ($this->action === self::ADD) {
            if (preg_match(ProjectValidator::LANGUAGE_PATTERN, $language) !== 1) {
                throw CommandFailed::because('value.invalid_language', ['language' => $language]);
            }

            if ($enabled) {
                throw CommandFailed::because('language.exists', ['language' => $language]);
            }

            $workspace->putSite($site->with(
                languages: [...$site->languages, $language],
                defaultLanguage: $invocation->flag('default') ? $language : null,
            ));

            return CommandResult::ok('ok.language_added', ['language' => $language]);
        }

        if (!$enabled) {
            throw CommandFailed::because('language.not_enabled', ['language' => $language, 'languages' => implode(', ', $site->languages)]);
        }

        if ($this->action === self::DEFAULT) {
            $workspace->putSite($site->with(defaultLanguage: $language));

            return CommandResult::ok('ok.language_default', ['language' => $language]);
        }

        if ($language === $site->defaultLanguage) {
            throw CommandFailed::because('language.is_default', ['language' => $language]);
        }

        $references = References::toLanguage($workspace->project(), $language);
        Lookup::unlessReferenced($invocation, 'language.in_use', $language, $references);

        References::removeLanguage($workspace, $language);
        $site = $workspace->project()->site;
        $workspace->putSite($site->with(languages: array_values(array_diff($site->languages, [$language]))));

        return CommandResult::ok('ok.language_removed', ['language' => $language]);
    }
}
