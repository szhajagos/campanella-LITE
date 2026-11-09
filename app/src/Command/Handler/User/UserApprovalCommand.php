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
 * Switches approval for an AI user's batches on or off:
 *
 *     user approval assistant off                     everything runs without approval
 *     user approval assistant on                      everything needs approval again
 *     user approval assistant off --for="content"     content commands run without approval
 *     user approval assistant on --for="content"      …and need it again
 */
final class UserApprovalCommand extends UserCommand implements CommandHandler
{
    public function signature(): Signature
    {
        return new Signature(
            name: 'user approval',
            arguments: ['name', 'state'],
            options: ['for' => true],
            examples: ['user approval assistant off', 'user approval assistant off --for="content edit"', 'user approval assistant on'],
        );
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $user = $this->ai($invocation->requiredArgument(0));
        $state = $invocation->requiredArgument(1);

        if (!in_array($state, ['on', 'off'], true)) {
            throw CommandFailed::because('value.invalid_choice', ['value' => $state, 'choices' => 'on, off']);
        }

        $for = $invocation->option('for');
        $command = $for === null ? null : self::commandName($for);
        $key = $command === null ? 'ok.user_approval' : 'ok.user_approval_' . $state . '_for';

        return CommandResult::deferred($key, self::params($this->change($user, $state, $command), $state, $command), function () use ($user, $state, $command, $key): CommandResult {
            // Applied to the stored settings, so several approval commands in one batch add up.
            $updated = $this->change($this->users->find($user->name) ?? $user, $state, $command);
            $this->users->save($updated);

            return CommandResult::ok($key, self::params($updated, $state, $command));
        });
    }

    private function change(User $user, string $state, ?string $command): User
    {
        if ($command === null) {
            return $user->with(approval: $state === 'on', autoApprove: []);
        }

        $auto = $state === 'off'
            ? (User::covers($user->autoApprove, $command) ? $user->autoApprove : [...$user->autoApprove, $command])
            : array_values(array_filter($user->autoApprove, static fn (string $right): bool => !User::covers([$command], $right)));
        sort($auto);

        return $user->with(approval: true, autoApprove: $auto);
    }

    /**
     * @return array<string, string>
     */
    private static function params(User $user, string $state, ?string $command): array
    {
        return ['user' => $user->name, 'state' => $state, 'command' => $command ?? '', 'auto' => $user->autoApprove === [] ? '-' : implode(', ', $user->autoApprove)];
    }
}
