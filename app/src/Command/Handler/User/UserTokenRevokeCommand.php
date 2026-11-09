<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Handler\User;

use Campanella\Lite\Admin\ApiToken;
use Campanella\Lite\Command\CommandFailed;
use Campanella\Lite\Command\CommandHandler;
use Campanella\Lite\Command\CommandResult;
use Campanella\Lite\Command\Invocation;
use Campanella\Lite\Command\Signature;
use Campanella\Lite\Command\Workspace;

/**
 * Revokes a token of an AI user: from now on it no longer signs in.
 */
final class UserTokenRevokeCommand extends UserCommand implements CommandHandler
{
    public function signature(): Signature
    {
        return new Signature(name: 'user token revoke', arguments: ['name', 'token'], examples: ['user token revoke assistant 1a2b3c4d']);
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $user = $this->ai($invocation->requiredArgument(0));
        $id = $invocation->requiredArgument(1);
        $tokens = array_values(array_filter($user->tokens, static fn (ApiToken $token): bool => $token->id !== $id));

        if (count($tokens) === count($user->tokens)) {
            throw CommandFailed::because('user.token_not_found', ['user' => $user->name, 'token' => $id]);
        }

        $params = ['user' => $user->name, 'token' => $id];

        return CommandResult::deferred('ok.token_revoked', $params, function () use ($user, $id, $params): CommandResult {
            $current = $this->users->find($user->name) ?? $user;
            $this->users->save($current->with(tokens: array_values(array_filter($current->tokens, static fn (ApiToken $token): bool => $token->id !== $id))));

            return CommandResult::ok('ok.token_revoked', $params);
        });
    }
}
