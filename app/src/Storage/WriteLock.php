<?php

declare(strict_types=1);

namespace Campanella\Lite\Storage;

use Campanella\Lite\Exception\StorageException;

/**
 * An exclusive, process-wide lock around writes to the project.
 *
 * Two admin requests (or an admin request and a page generation) can run at
 * the same time; the lock makes sure their writes never interleave. Nested
 * calls in the same process are allowed and do not block.
 */
final class WriteLock
{
    /** @var array<string, int> lock file => nesting depth in this process */
    private static array $held = [];

    public function __construct(private readonly string $lockFile)
    {
    }

    /**
     * Runs the callback while holding the lock and returns its result.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function synchronized(callable $callback): mixed
    {
        if ((self::$held[$this->lockFile] ?? 0) > 0) {
            return $this->nested($callback);
        }

        $handle = $this->open();

        try {
            if (!flock($handle, LOCK_EX)) {
                throw StorageException::operationFailed('lock', $this->lockFile);
            }

            try {
                return $this->nested($callback);
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Whether this process currently holds the lock.
     *
     * @phpstan-impure
     */
    public function isHeld(): bool
    {
        return (self::$held[$this->lockFile] ?? 0) > 0;
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function nested(callable $callback): mixed
    {
        self::$held[$this->lockFile] = (self::$held[$this->lockFile] ?? 0) + 1;

        try {
            return $callback();
        } finally {
            self::$held[$this->lockFile]--;

            if (self::$held[$this->lockFile] === 0) {
                unset(self::$held[$this->lockFile]);
            }
        }
    }

    /**
     * @return resource
     */
    private function open()
    {
        $directory = dirname($this->lockFile);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw StorageException::operationFailed('create directory', $directory);
        }

        $handle = @fopen($this->lockFile, 'c');

        if ($handle === false) {
            throw StorageException::operationFailed('open', $this->lockFile);
        }

        return $handle;
    }
}
