<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Handler\User;

use Campanella\Lite\Admin\User;
use Campanella\Lite\Command\CommandFailed;
use Campanella\Lite\Command\CommandHandler;
use Campanella\Lite\Command\CommandResult;
use Campanella\Lite\Command\Invocation;
use Campanella\Lite\Command\Signature;
use Campanella\Lite\Command\Workspace;

/**
 * Deletes a user, with its tokens. Nobody can delete themselves or the last administrator.
 */
final class UserDeleteCommand extends UserCommand implements CommandHandler
{
    public function signature(): Signature
    {
        return new Signature(name: 'user delete', arguments: ['name'], examples: ['user delete assistant']);
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $name = $invocation->requiredArgument(0);
        $user = $this->users->find($name) ?? throw CommandFailed::because('user.not_found', ['user' => $name]);

        if ($name === $workspace->user) {
            throw CommandFailed::because('user.delete_self');
        }

        $admins = array_filter($this->users->all(), static fn (User $other): bool => $other->isAdmin());

        if ($user->isAdmin() && count($admins) < 2) {
            throw CommandFailed::because('user.last_admin');
        }

        return CommandResult::deferred('ok.user_deleted', ['user' => $name], function () use ($name): CommandResult {
            $this->users->delete($name);

            return CommandResult::ok('ok.user_deleted', ['user' => $name]);
        });
    }
}
