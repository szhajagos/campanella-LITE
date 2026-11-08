<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Handler;

use Campanella\Lite\Admin\UserRepository;
use Campanella\Lite\Command\CommandFailed;
use Campanella\Lite\Command\CommandHandler;
use Campanella\Lite\Command\CommandResult;
use Campanella\Lite\Command\Invocation;
use Campanella\Lite\Command\Signature;
use Campanella\Lite\Command\Workspace;
use Campanella\Lite\I18n\Translator;

/**
 * Sets the language of the admin's own messages. It changes the admin's
 * settings, not the website, so it takes effect after the batch is written.
 */
final class UserLocaleCommand implements CommandHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly Translator $translator,
    ) {
    }

    public function signature(): Signature
    {
        return new Signature(name: 'user set locale', arguments: ['locale'], examples: ['user set locale hu', 'user set locale en']);
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $locale = $invocation->requiredArgument(0);
        $available = $this->translator->locales();

        if (!in_array($locale, $available, true)) {
            throw CommandFailed::because('user.unknown_locale', ['locale' => $locale, 'locales' => implode(', ', $available)]);
        }

        $name = $workspace->user ?? throw CommandFailed::because('user.not_logged_in');
        $user = $this->users->find($name) ?? throw CommandFailed::because('user.not_logged_in');

        return CommandResult::deferred('ok.user_locale', ['locale' => $locale], function () use ($user, $locale): CommandResult {
            $this->users->save($user->withLocale($locale));

            return CommandResult::ok('ok.user_locale', ['locale' => $locale]);
        });
    }
}
