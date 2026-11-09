<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Handler\User;

use Campanella\Lite\Admin\User;
use Campanella\Lite\Admin\UserRepository;
use Campanella\Lite\Command\CommandFailed;
use Campanella\Lite\Command\CommandHandler;
use Campanella\Lite\Command\CommandResult;
use Campanella\Lite\Command\Invocation;
use Campanella\Lite\Command\Signature;
use Campanella\Lite\Command\Workspace;
use Campanella\Lite\I18n\Translator;

/**
 * Creates an AI user with the default rights and approval switched on.
 * It can sign in once it has a token ("user token create").
 */
final class UserAiCreateCommand extends UserCommand implements CommandHandler
{
    public function __construct(UserRepository $users, private readonly Translator $translator)
    {
        parent::__construct($users);
    }

    public function signature(): Signature
    {
        return new Signature(name: 'user ai create', arguments: ['name'], options: ['locale' => true], examples: ['user ai create assistant', 'user ai create claude --locale=hu']);
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $name = $invocation->requiredArgument(0);
        $locale = $invocation->option('locale') ?? User::DEFAULT_LOCALE;

        if (preg_match(UserRepository::NAME_PATTERN, $name) !== 1) {
            throw CommandFailed::because('user.invalid_name', ['user' => $name]);
        }

        if ($this->users->find($name) !== null) {
            throw CommandFailed::because('user.exists', ['user' => $name]);
        }

        if (!in_array($locale, $this->translator->locales(), true)) {
            throw CommandFailed::because('user.unknown_locale', ['locale' => $locale, 'locales' => implode(', ', $this->translator->locales())]);
        }

        $params = ['user' => $name, 'rights' => implode(', ', User::DEFAULT_AI_RIGHTS)];

        return CommandResult::deferred('ok.user_ai_created', $params, function () use ($name, $locale, $params): CommandResult {
            $this->users->save(User::createAi($name, $locale));

            return CommandResult::ok('ok.user_ai_created', $params);
        });
    }
}
