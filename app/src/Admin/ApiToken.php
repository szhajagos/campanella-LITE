<?php

declare(strict_types=1);

namespace Campanella\Lite\Admin;

/**
 * A token with which an AI user signs in to the API: "cl_{id}_{secret}".
 *
 * Only the id and a SHA-256 hash of the secret are stored. The secret is
 * 32 random bytes, so a fast hash is enough (unlike passwords, it cannot be
 * guessed); the id finds the token without trying every hash.
 */
final class ApiToken
{
    public const PREFIX = 'cl_';
    private const PATTERN = '/^cl_([0-9a-f]{8})_([0-9a-f]{64})$/';

    public function __construct(
        public readonly string $id,
        public readonly string $hash,
        public readonly string $label,
        public readonly string $createdAt,
        public readonly ?string $expiresAt = null,
    ) {
    }

    /**
     * A new token and the text to give to the AI client; the text is shown once and never stored.
     *
     * @return array{0: self, 1: string}
     */
    public static function create(string $label, ?\DateTimeImmutable $expires, \DateTimeImmutable $now): array
    {
        $id = bin2hex(random_bytes(4));
        $secret = bin2hex(random_bytes(32));

        return [
            new self($id, hash('sha256', $secret), $label, $now->format('c'), $expires?->format('c')),
            self::PREFIX . $id . '_' . $secret,
        ];
    }

    /**
     * @return array{0: string, 1: string}|null the id and the secret of a well-formed token text
     */
    public static function parse(string $text): ?array
    {
        return preg_match(self::PATTERN, trim($text), $match) === 1 ? [$match[1], $match[2]] : null;
    }

    public function matches(string $secret): bool
    {
        return hash_equals($this->hash, hash('sha256', $secret));
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && new \DateTimeImmutable($this->expiresAt) <= $now;
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'hash' => $this->hash,
            'label' => $this->label,
            'created_at' => $this->createdAt,
            'expires_at' => $this->expiresAt,
        ], static fn (?string $value): bool => $value !== null);
    }
}
