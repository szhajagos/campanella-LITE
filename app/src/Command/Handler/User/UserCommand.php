<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Handler\User;

use Campanella\Lite\Admin\User;
use Campanella\Lite\Admin\UserRepository;
use Campanella\Lite\Command\CommandFailed;

/**
 * Shared steps of the commands that manage users. They change
 * config/users.yaml, not the website, so they write after the batch (and
 * not on a dry run), and they are not part of undo. AI users can never run them.
 */
abstract class UserCommand
{
    public function __construct(protected readonly UserRepository $users)
    {
    }

    protected function ai(string $name): User
    {
        $user = $this->users->find($name) ?? throw CommandFailed::because('user.not_found', ['user' => $name]);

        if (!$user->isAi()) {
            throw CommandFailed::because('user.not_ai', ['user' => $name]);
        }

        return $user;
    }

    /**
     * A command name as rights and approvals use it: one or more lowercase words.
     */
    protected static function commandName(string $value): string
    {
        $name = trim((string) preg_replace('/\s+/', ' ', strtolower($value)));

        if (preg_match('/^[a-z]+( [a-z]+)*$/', $name) !== 1) {
            throw CommandFailed::because('user.invalid_command', ['command' => $value]);
        }

        return $name;
    }
}
