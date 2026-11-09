<?php

declare(strict_types=1);

namespace Campanella\Lite\Admin;

use Campanella\Lite\Exception\InvalidDataException;
use Campanella\Lite\Model\Fields;
use Campanella\Lite\Storage\FileStore;
use Campanella\Lite\Storage\YamlCodec;
use InvalidArgumentException;

/**
 * Users in config/users.yaml, outside the web root and outside the project:
 *
 *     users:
 *       admin: { password_hash: '$2y$12$…', locale: en }
 *       assistant:
 *         role: ai
 *         locale: en
 *         rights: [content, page, menu, 'file describe']
 *         approval: true
 *         auto_approve: ['content edit']
 *         tokens:
 *           - { id: 1a2b3c4d, hash: '…', label: Claude, created_at: '…', expires_at: '…' }
 */
final class UserRepository
{
    public const FILE = 'users.yaml';
    public const NAME_PATTERN = '/^[a-z0-9][a-z0-9._-]{1,31}$/';

    public function __construct(private readonly FileStore $store)
    {
    }

    public function find(string $name): ?User
    {
        return $this->all()[$name] ?? null;
    }

    public function exists(): bool
    {
        return $this->all() !== [];
    }

    /**
     * @return array<string, User>
     */
    public function all(): array
    {
        if (!$this->store->exists(self::FILE)) {
            return [];
        }

        $fields = new Fields(YamlCodec::decode($this->store->read(self::FILE), self::FILE), self::FILE);
        $fields->allowOnly(['users']);
        $users = [];

        foreach ($fields->mapping('users') as $name => $data) {
            $users[(string) $name] = self::user((string) $name, $fields->nested(is_array($data) ? $data : [], 'users.' . $name));
        }

        return $users;
    }

    public function delete(string $name): void
    {
        $users = $this->all();

        if (!isset($users[$name])) {
            return;
        }

        unset($users[$name]);
        $this->store->write(self::FILE, YamlCodec::encode(['users' => array_map(self::toArray(...), $users)]));
        @chmod($this->store->path(self::FILE), 0600);
    }

    /**
     * The AI user and the token a token text belongs to, if the token is valid and current.
     *
     * @return array{0: User, 1: ApiToken}|null
     */
    public function findByToken(string $text, \DateTimeImmutable $now): ?array
    {
        $parsed = ApiToken::parse($text);

        if ($parsed === null) {
            return null;
        }

        foreach ($this->all() as $user) {
            $token = $user->isAi() ? $user->token($parsed[0], $parsed[1], $now) : null;

            if ($token !== null) {
                return [$user, $token];
            }
        }

        return null;
    }

    private static function user(string $name, Fields $fields): User
    {
        $fields->allowOnly(['password_hash', 'locale', 'role', 'rights', 'approval', 'auto_approve', 'tokens']);
        $role = $fields->optionalString('role') ?? User::ROLE_ADMIN;

        if (!in_array($role, [User::ROLE_ADMIN, User::ROLE_AI], true)) {
            throw new InvalidDataException(self::FILE, sprintf('users.%s.role: "%s" is not a role (admin, ai)', $name, $role));
        }

        $tokens = [];

        foreach ($fields->mappingList('tokens') as $index => $data) {
            $token = $fields->nested($data, 'tokens.' . ($index + 1));
            $token->allowOnly(['id', 'hash', 'label', 'created_at', 'expires_at']);
            $tokens[] = new ApiToken(
                $token->string('id'),
                $token->string('hash'),
                $token->optionalString('label') ?? '',
                $token->string('created_at'),
                $token->optionalString('expires_at'),
            );
        }

        return new User(
            $name,
            $role === User::ROLE_ADMIN ? $fields->string('password_hash') : ($fields->optionalString('password_hash') ?? ''),
            $fields->optionalString('locale') ?? User::DEFAULT_LOCALE,
            $role,
            $tokens,
            $role === User::ROLE_AI ? $fields->stringList('rights') : [],
            $fields->optionalBool('approval') ?? true,
            $role === User::ROLE_AI ? $fields->stringList('auto_approve') : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function toArray(User $user): array
    {
        if ($user->isAdmin()) {
            return ['password_hash' => $user->passwordHash, 'locale' => $user->locale];
        }

        return [
            'role' => User::ROLE_AI,
            'locale' => $user->locale,
            'rights' => $user->rights,
            'approval' => $user->approval,
            'auto_approve' => $user->autoApprove,
            'tokens' => array_map(static fn (ApiToken $token): array => $token->toArray(), $user->tokens),
        ];
    }

    public function save(User $user): void
    {
        if (preg_match(self::NAME_PATTERN, $user->name) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid user name "%s": use 2–32 lowercase letters, digits, ".", "_" or "-".', $user->name));
        }

        $users = $this->all();
        $users[$user->name] = $user;
        ksort($users);

        $data = ['users' => array_map(self::toArray(...), $users)];

        $this->store->write(self::FILE, YamlCodec::encode($data));
        @chmod($this->store->path(self::FILE), 0600);
    }
}
