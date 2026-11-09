<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Handler\User;

use Campanella\Lite\Admin\ApiToken;
use Campanella\Lite\Admin\User;
use Campanella\Lite\Command\CommandHandler;
use Campanella\Lite\Command\CommandResult;
use Campanella\Lite\Command\Invocation;
use Campanella\Lite\Command\Output;
use Campanella\Lite\Command\Signature;
use Campanella\Lite\Command\Workspace;

/**
 * Lists the users with their role, rights, approval settings and tokens.
 */
final class UserListCommand extends UserCommand implements CommandHandler
{
    public function signature(): Signature
    {
        return new Signature(name: 'user list', examples: ['user list'], readOnly: true);
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $rows = array_map(static fn (User $user): array => [
            $user->name,
            $user->role,
            $user->locale,
            $user->isAi() ? implode(', ', $user->rights) : '*',
            $user->isAi() ? ($user->approval ? ($user->autoApprove === [] ? 'on' : 'on, except: ' . implode(', ', $user->autoApprove)) : 'off') : '',
            implode(', ', array_map(
                static fn (ApiToken $token): string => $token->id . ($token->label !== '' ? ' (' . $token->label . ')' : '')
                    . ($token->expiresAt !== null ? ' → ' . substr($token->expiresAt, 0, 10) : ''),
                $user->tokens,
            )),
        ], array_values($this->users->all()));

        return CommandResult::ok(
            'ok.list',
            ['type' => 'users', 'count' => count($rows)],
            Output::table(['name', 'role', 'locale', 'rights', 'approval', 'tokens'], $rows),
        );
    }
}
