<?php

declare(strict_types=1);

namespace Campanella\Lite\Tests\Support;

use PHPUnit\Framework\Attributes\After;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Gives a test case its own temporary directory, removed after each test.
 */
trait TemporaryDirectory
{
    private ?string $temporaryDirectory = null;

    protected function temporaryDirectory(): string
    {
        if ($this->temporaryDirectory === null) {
            $path = sys_get_temp_dir() . '/campanella-test-' . bin2hex(random_bytes(8));

            if (!mkdir($path, 0775, true)) {
                throw new RuntimeException('Could not create ' . $path);
            }

            $this->temporaryDirectory = $path;
        }

        return $this->temporaryDirectory;
    }

    /**
     * Copies a fixture directory into the temporary directory and returns the copy's path.
     */
    protected function copyFixture(string $name): string
    {
        $source = dirname(__DIR__) . '/Fixtures/' . $name;
        $target = $this->temporaryDirectory() . '/' . $name;

        /** @var iterable<string, \SplFileInfo> $items */
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        mkdir($target, 0775, true);

        foreach ($items as $path => $item) {
            $destination = $target . substr($path, strlen($source));

            if ($item->isDir()) {
                mkdir($destination, 0775, true);
            } else {
                copy($path, $destination);
            }
        }

        return $target;
    }

    #[After]
    protected function removeTemporaryDirectory(): void
    {
        if ($this->temporaryDirectory === null) {
            return;
        }

        /** @var iterable<string, \SplFileInfo> $items */
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->temporaryDirectory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $path => $item) {
            $item->isDir() ? rmdir($path) : unlink($path);
        }

        rmdir($this->temporaryDirectory);
        $this->temporaryDirectory = null;
    }
}
