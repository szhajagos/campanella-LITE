<?php

declare(strict_types=1);

namespace Campanella\Lite\Admin;

/**
 * An administrator. Only the password hash is stored, never the password.
 */
final class User
{
    public const DEFAULT_LOCALE = 'en';
    public const MIN_PASSWORD_LENGTH = 10;

    public function __construct(
        public readonly string $name,
        public readonly string $passwordHash,
        public readonly string $locale = self::DEFAULT_LOCALE,
    ) {
    }

    public static function create(string $name, string $password, string $locale = self::DEFAULT_LOCALE): self
    {
        return new self($name, password_hash($password, PASSWORD_DEFAULT), $locale);
    }

    public function verify(string $password): bool
    {
        return password_verify($password, $this->passwordHash);
    }

    public function needsRehash(): bool
    {
        return password_needs_rehash($this->passwordHash, PASSWORD_DEFAULT);
    }

    public function withPassword(string $password): self
    {
        return new self($this->name, password_hash($password, PASSWORD_DEFAULT), $this->locale);
    }

    public function withLocale(string $locale): self
    {
        return new self($this->name, $this->passwordHash, $locale);
    }
}
