<?php

declare(strict_types=1);

namespace Campanella\Lite\Admin;

use Campanella\Lite\Command\Signature;

/**
 * A user of the system: an administrator, who signs in to the dashboard
 * with a password, or an AI user, which signs in to the API with a token.
 *
 * Administrators may run every command. An AI user may always run
 * read-only commands, and otherwise only the commands its rights name
 * ("content", "page create", …); "user" commands never. Unless approval is
 * switched off, its batches are proposals that an administrator approves.
 * Only the password hash and token hashes are stored.
 */
final class User
{
    public const DEFAULT_LOCALE = 'en';
    public const MIN_PASSWORD_LENGTH = 10;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_AI = 'ai';

    /** What a new AI user may do: work on content, pages, menus and files, but not on the site's structure. */
    public const DEFAULT_AI_RIGHTS = [
        'content',
        'page',
        'menu',
        'file describe',
        'file rename',
        'file delete',
        'file restore',
        'file upload',
        'build',
    ];

    /** Commands no AI user may ever run. */
    private const NEVER_FOR_AI = ['user'];

    /**
     * @param list<ApiToken> $tokens       AI users only
     * @param list<string>   $rights       AI users only: the commands it may run, by name or first words
     * @param list<string>   $autoApprove  AI users only: commands that run without approval
     */
    public function __construct(
        public readonly string $name,
        public readonly string $passwordHash,
        public readonly string $locale = self::DEFAULT_LOCALE,
        public readonly string $role = self::ROLE_ADMIN,
        public readonly array $tokens = [],
        public readonly array $rights = [],
        public readonly bool $approval = true,
        public readonly array $autoApprove = [],
    ) {
    }

    public static function create(string $name, string $password, string $locale = self::DEFAULT_LOCALE): self
    {
        return new self($name, password_hash($password, PASSWORD_DEFAULT), $locale);
    }

    public static function createAi(string $name, string $locale = self::DEFAULT_LOCALE): self
    {
        return new self($name, '', $locale, self::ROLE_AI, [], self::DEFAULT_AI_RIGHTS);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isAi(): bool
    {
        return $this->role === self::ROLE_AI;
    }

    public function verify(string $password): bool
    {
        return $this->isAdmin() && $this->passwordHash !== '' && password_verify($password, $this->passwordHash);
    }

    public function needsRehash(): bool
    {
        return password_needs_rehash($this->passwordHash, PASSWORD_DEFAULT);
    }

    /**
     * Whether the user may run a command.
     */
    public function allows(Signature $signature): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        if (self::covers(self::NEVER_FOR_AI, $signature->name)) {
            return false;
        }

        return $signature->readOnly || self::covers($this->rights, $signature->name);
    }

    /** Whether the user may run a command, by its name (for actions that are not commands, like "file upload"). */
    public function allowsName(string $command): bool
    {
        return $this->isAdmin() || (!self::covers(self::NEVER_FOR_AI, $command) && self::covers($this->rights, $command));
    }

    /**
     * Whether a batch of these commands has to wait for an administrator.
     *
     * @param list<Signature> $signatures
     */
    public function needsApproval(array $signatures): bool
    {
        if (!$this->isAi() || !$this->approval) {
            return false;
        }

        foreach ($signatures as $signature) {
            if (!$signature->readOnly && !self::covers($this->autoApprove, $signature->name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The user and token a token text belongs to, if it is valid and current.
     */
    public function token(string $id, string $secret, \DateTimeImmutable $now): ?ApiToken
    {
        foreach ($this->tokens as $token) {
            if ($token->id === $id && $token->matches($secret) && !$token->isExpired($now)) {
                return $token;
            }
        }

        return null;
    }

    public function withPassword(string $password): self
    {
        return $this->with(passwordHash: password_hash($password, PASSWORD_DEFAULT));
    }

    public function withLocale(string $locale): self
    {
        return $this->with(locale: $locale);
    }

    /**
     * @param list<ApiToken>|null $tokens
     * @param list<string>|null   $rights
     * @param list<string>|null   $autoApprove
     */
    public function with(
        ?string $passwordHash = null,
        ?string $locale = null,
        ?array $tokens = null,
        ?array $rights = null,
        ?bool $approval = null,
        ?array $autoApprove = null,
    ): self {
        return new self(
            $this->name,
            $passwordHash ?? $this->passwordHash,
            $locale ?? $this->locale,
            $this->role,
            $tokens ?? $this->tokens,
            $rights ?? $this->rights,
            $approval ?? $this->approval,
            $autoApprove ?? $this->autoApprove,
        );
    }

    /**
     * Whether a command name is one of the given commands or starts with one of them as whole words.
     *
     * @param list<string> $commands
     */
    public static function covers(array $commands, string $name): bool
    {
        foreach ($commands as $command) {
            if ($name === $command || str_starts_with($name, $command . ' ')) {
                return true;
            }
        }

        return false;
    }
}
