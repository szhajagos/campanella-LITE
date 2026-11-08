<?php

declare(strict_types=1);

namespace Campanella\Lite\Admin;

use Campanella\Lite\Model\Fields;
use Campanella\Lite\Storage\FileStore;
use Campanella\Lite\Storage\YamlCodec;
use InvalidArgumentException;

/**
 * Admin users in config/users.yaml, outside the web root and outside the project:
 *
 *     users:
 *       admin: { password_hash: '$2y$12$…', locale: en }
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
            $user = $fields->nested(is_array($data) ? $data : [], 'users.' . $name);
            $user->allowOnly(['password_hash', 'locale']);
            $users[$name] = new User($name, $user->string('password_hash'), $user->optionalString('locale') ?? User::DEFAULT_LOCALE);
        }

        return $users;
    }

    public function save(User $user): void
    {
        if (preg_match(self::NAME_PATTERN, $user->name) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid user name "%s": use 2–32 lowercase letters, digits, ".", "_" or "-".', $user->name));
        }

        $users = $this->all();
        $users[$user->name] = $user;
        ksort($users);

        $data = ['users' => array_map(
            static fn (User $u): array => ['password_hash' => $u->passwordHash, 'locale' => $u->locale],
            $users,
        )];

        $this->store->write(self::FILE, YamlCodec::encode($data));
        @chmod($this->store->path(self::FILE), 0600);
    }
}
