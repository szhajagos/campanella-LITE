<?php

declare(strict_types=1);

namespace Campanella\Lite\Storage;

use Campanella\Lite\Exception\StorageException;

/**
 * Reads and writes files below one base directory.
 *
 * Every path is relative to the base directory and checked segment by
 * segment, so a caller can never reach outside it (no "..", no absolute
 * paths, no hidden files). Writes are atomic: the data goes to a temporary
 * file first, which is then renamed over the target.
 */
final class FileStore
{
    /** A path segment: starts with a letter, digit or underscore; no hidden files. */
    private const SEGMENT = '/^[A-Za-z0-9_][A-Za-z0-9._-]*$/';

    private readonly string $baseDir;

    public function __construct(string $baseDir)
    {
        $this->baseDir = rtrim($baseDir, '/\\');
    }

    public function baseDir(): string
    {
        return $this->baseDir;
    }

    /** The absolute path of a file or directory below the base directory. */
    public function path(string $relative): string
    {
        $normalized = $this->normalize($relative);

        return $normalized === '' ? $this->baseDir : $this->baseDir . '/' . $normalized;
    }

    public function exists(string $relative): bool
    {
        return is_file($this->path($relative));
    }

    public function read(string $relative): string
    {
        $path = $this->path($relative);

        if (!is_file($path)) {
            throw StorageException::notFound($relative);
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw StorageException::operationFailed('read', $relative);
        }

        return $contents;
    }

    public function write(string $relative, string $contents): void
    {
        $path = $this->path($relative);

        if ($path === $this->baseDir) {
            throw StorageException::unsafePath($relative, 'a file name is required');
        }

        $directory = dirname($path);
        $this->ensureDirectory($directory);

        $temporary = sprintf('%s/.%s.%s.tmp', $directory, basename($path), bin2hex(random_bytes(6)));

        if (@file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw StorageException::operationFailed('write', $relative);
        }

        if (!@rename($temporary, $path)) {
            @unlink($temporary);

            throw StorageException::operationFailed('replace', $relative);
        }
    }

    /** Deletes a file. Returns false if there was nothing to delete. */
    public function delete(string $relative): bool
    {
        $path = $this->path($relative);

        if (!is_file($path)) {
            return false;
        }

        if (!@unlink($path)) {
            throw StorageException::operationFailed('delete', $relative);
        }

        return true;
    }

    /** Removes a directory if it is empty. Returns false if it does not exist or is not empty. */
    public function deleteDirectoryIfEmpty(string $relative): bool
    {
        $path = $this->path($relative);

        if ($path === $this->baseDir || !is_dir($path)) {
            return false;
        }

        $entries = scandir($path);

        if ($entries === false || count($entries) > 2) {
            return false;
        }

        if (!@rmdir($path)) {
            throw StorageException::operationFailed('remove directory', $relative);
        }

        return true;
    }

    /**
     * Names of the files in a directory with the given extension, without
     * the extension, sorted naturally. Hidden and temporary files are skipped.
     *
     * @return list<string>
     */
    public function listFiles(string $directory, string $extension): array
    {
        $suffix = '.' . ltrim($extension, '.');
        $names = [];

        foreach ($this->entries($directory) as $entry => $path) {
            if (is_file($path) && str_ends_with($entry, $suffix)) {
                $names[] = substr($entry, 0, -strlen($suffix));
            }
        }

        natsort($names);

        return array_values($names);
    }

    /**
     * Names of the subdirectories of a directory, sorted naturally.
     *
     * @return list<string>
     */
    public function listDirectories(string $directory): array
    {
        $names = [];

        foreach ($this->entries($directory) as $entry => $path) {
            if (is_dir($path)) {
                $names[] = $entry;
            }
        }

        natsort($names);

        return array_values($names);
    }

    /**
     * @return array<string, string> entry name => absolute path, without hidden entries
     */
    private function entries(string $directory): array
    {
        $path = $this->path($directory);

        if (!is_dir($path)) {
            return [];
        }

        $entries = scandir($path);

        if ($entries === false) {
            throw StorageException::operationFailed('list', $directory);
        }

        $result = [];

        foreach ($entries as $entry) {
            if (preg_match(self::SEGMENT, $entry) === 1) {
                $result[$entry] = $path . '/' . $entry;
            }
        }

        return $result;
    }

    private function normalize(string $relative): string
    {
        if ($relative === '') {
            return '';
        }

        if (str_contains($relative, '\\') || str_contains($relative, "\0")) {
            throw StorageException::unsafePath($relative, 'only "/" may separate directories');
        }

        if (str_starts_with($relative, '/')) {
            throw StorageException::unsafePath($relative, 'the path must be relative');
        }

        foreach (explode('/', $relative) as $segment) {
            if (preg_match(self::SEGMENT, $segment) !== 1) {
                throw StorageException::unsafePath($relative, sprintf('invalid segment "%s"', $segment));
            }
        }

        return $relative;
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw StorageException::operationFailed('create directory', $directory);
        }
    }
}
