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

/**
 * Gives an AI user the right to run a command ("user allow"), or takes it
 * away ("user deny"). A right names a command or its first words:
 * "content" covers every content command, "page publish" only that one.
 * Read-only commands are always allowed, "user" commands never.
 */
final class UserRightsCommand extends UserCommand implements CommandHandler
{
    public function __construct(UserRepository $users, private readonly bool $allow)
    {
        parent::__construct($users);
    }

    public function signature(): Signature
    {
        return $this->allow
            ? new Signature(name: 'user allow', arguments: ['name', 'command'], examples: ['user allow assistant layout', 'user allow assistant "file purge"'])
            : new Signature(name: 'user deny', arguments: ['name', 'command'], examples: ['user deny assistant page', 'user deny assistant "page delete"']);
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $user = $this->ai($invocation->requiredArgument(0));
        $command = self::commandName($invocation->requiredArgument(1));

        if ($this->allow && User::covers(['user'], $command)) {
            throw CommandFailed::because('user.never_for_ai', ['command' => $command]);
        }

        // Taking away "page" also takes away "page create"; taking away "page delete" from "page" is not possible this way.
        if (!$this->allow && !in_array($command, $user->rights, true) && User::covers($user->rights, $command)) {
            throw CommandFailed::because('user.deny_part', ['command' => $command, 'rights' => implode(', ', $user->rights)]);
        }

        $rights = $this->change($user->rights, $command);

        return CommandResult::deferred('ok.user_rights', self::params($user->name, $rights), function () use ($user, $command): CommandResult {
            // Applied to the stored rights, so several rights commands in one batch add up.
            $current = $this->users->find($user->name) ?? $user;
            $rights = $this->change($current->rights, $command);
            $this->users->save($current->with(rights: $rights));

            return CommandResult::ok('ok.user_rights', self::params($user->name, $rights));
        });
    }

    /**
     * @param list<string> $rights
     *
     * @return list<string>
     */
    private function change(array $rights, string $command): array
    {
        $changed = $this->allow
            ? (User::covers($rights, $command) ? $rights : [...$rights, $command])
            : array_values(array_filter($rights, static fn (string $right): bool => !User::covers([$command], $right)));
        sort($changed);

        return $changed;
    }

    /**
     * @param list<string> $rights
     *
     * @return array<string, string>
     */
    private static function params(string $user, array $rights): array
    {
        return ['user' => $user, 'rights' => $rights === [] ? '-' : implode(', ', $rights)];
    }
}
