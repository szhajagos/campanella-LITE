<?php

declare(strict_types=1);

namespace Campanella\Lite\Admin\Session;

/**
 * A session kept in memory, for tests and the CLI.
 */
final class ArraySession implements Session
{
    /** @var array<string, mixed> */
    private array $data = [];

    public int $regenerations = 0;

    public bool $destroyed = false;

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function regenerate(): void
    {
        $this->regenerations++;
    }

    public function destroy(): void
    {
        $this->data = [];
        $this->destroyed = true;
    }
}
