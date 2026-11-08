<?php

declare(strict_types=1);

namespace Campanella\Lite\Admin;

use Campanella\Lite\Storage\FileStore;

/**
 * Slows down password guessing: after MAX_FAILURES failed logins from one
 * address within WINDOW seconds, that address cannot log in until the
 * oldest failure is WINDOW seconds old. Addresses are stored hashed.
 */
final class LoginThrottle
{
    public const FILE = 'admin/login-failures.json';
    public const MAX_FAILURES = 5;
    public const WINDOW = 900;

    public function __construct(private readonly FileStore $var)
    {
    }

    /**
     * Seconds until the address may try again; 0 if it may try now.
     */
    public function retryAfter(string $address, int $now): int
    {
        $failures = $this->failures($address, $now);

        if (count($failures) < self::MAX_FAILURES) {
            return 0;
        }

        return max(1, min($failures) + self::WINDOW - $now);
    }

    public function recordFailure(string $address, int $now): void
    {
        $data = $this->load($now);
        $data[self::key($address)][] = $now;
        $this->store($data);
    }

    public function reset(string $address, int $now): void
    {
        $data = $this->load($now);
        unset($data[self::key($address)]);
        $this->store($data);
    }

    /**
     * @return list<int>
     */
    private function failures(string $address, int $now): array
    {
        return $this->load($now)[self::key($address)] ?? [];
    }

    /**
     * @return array<string, list<int>> recent failures per hashed address
     */
    private function load(int $now): array
    {
        if (!$this->var->exists(self::FILE)) {
            return [];
        }

        $decoded = json_decode($this->var->read(self::FILE), true);
        $data = [];

        foreach (is_array($decoded) ? $decoded : [] as $key => $times) {
            $recent = array_values(array_filter(
                is_array($times) ? $times : [],
                static fn (mixed $time): bool => is_int($time) && $time > $now - self::WINDOW,
            ));

            if ($recent !== [] && is_string($key)) {
                /** @var list<int> $recent */
                $data[$key] = $recent;
            }
        }

        return $data;
    }

    /**
     * @param array<string, list<int>> $data
     */
    private function store(array $data): void
    {
        $this->var->write(self::FILE, json_encode($data, JSON_THROW_ON_ERROR));
    }

    private static function key(string $address): string
    {
        return hash('sha256', $address);
    }
}
