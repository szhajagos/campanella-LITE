<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Handler\User;

use Campanella\Lite\Admin\ApiToken;
use Campanella\Lite\Command\CommandFailed;
use Campanella\Lite\Command\CommandHandler;
use Campanella\Lite\Command\CommandResult;
use Campanella\Lite\Command\Invocation;
use Campanella\Lite\Command\Output;
use Campanella\Lite\Command\Signature;
use Campanella\Lite\Command\Workspace;

/**
 * Creates a token for an AI user and shows it once. Only a hash is kept,
 * so a lost token cannot be shown again; revoke it and create a new one.
 */
final class UserTokenCreateCommand extends UserCommand implements CommandHandler
{
    public const DEFAULT_DAYS = 90;
    public const MAX_DAYS = 3650;

    public function signature(): Signature
    {
        return new Signature(
            name: 'user token create',
            arguments: ['name'],
            options: ['label' => true, 'days' => true],
            examples: ['user token create assistant --label=Claude', 'user token create assistant --days=30', 'user token create assistant --days=0'],
        );
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $user = $this->ai($invocation->requiredArgument(0));
        $label = mb_substr(trim($invocation->option('label') ?? ''), 0, 60);
        $days = $invocation->option('days') ?? (string) self::DEFAULT_DAYS;

        if (preg_match('/^[0-9]{1,4}$/', $days) !== 1 || (int) $days > self::MAX_DAYS) {
            throw CommandFailed::because('user.invalid_days', ['value' => $days, 'max' => self::MAX_DAYS]);
        }

        $now = new \DateTimeImmutable();
        $expires = (int) $days === 0 ? null : $now->modify('+' . (int) $days . ' days');
        $params = ['user' => $user->name, 'expires' => $expires?->format('Y-m-d') ?? '-'];

        return CommandResult::deferred('ok.token_planned', $params, function () use ($user, $label, $expires, $now, $params): CommandResult {
            [$token, $text] = ApiToken::create($label, $expires, $now);
            $current = $this->users->find($user->name) ?? $user;
            $this->users->save($current->with(tokens: [...$current->tokens, $token]));

            return CommandResult::ok('ok.token_created', $params + ['id' => $token->id], Output::value($text));
        });
    }
}
