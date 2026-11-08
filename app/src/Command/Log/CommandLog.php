<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Log;

/**
 * Appends every batch that changed the project to var/log/commands.log,
 * one JSON object per line:
 *
 *     {"id":"20261108-091500-123456","time":"2026-11-08T09:15:00+01:00","user":"admin",
 *      "action":"applied","files":["pages/3.yaml"],"batch":"page publish 3"}
 *
 * "user" is null for the command line. "action" is "applied" or "undone";
 * an undo entry names the batch it took back in "undoes".
 *
 * When the file grows past 2 MB it is renamed to commands.log.1 (replacing
 * the previous one) and a new file is started.
 */
final class CommandLog
{
    private const MAX_SIZE = 2 * 1024 * 1024;

    public function __construct(private readonly string $file)
    {
    }

    public function append(LogEntry $entry): void
    {
        $directory = dirname($this->file);

        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        clearstatcache(true, $this->file);

        if (is_file($this->file) && filesize($this->file) > self::MAX_SIZE) {
            @rename($this->file, $this->file . '.1');
        }

        @file_put_contents(
            $this->file,
            json_encode($entry->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n",
            FILE_APPEND | LOCK_EX,
        );
    }

    /**
     * The most recent entries, newest first. Unreadable lines are skipped.
     *
     * @return list<LogEntry>
     */
    public function recent(int $count): array
    {
        if ($count < 1 || !is_file($this->file)) {
            return [];
        }

        $lines = @file($this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return [];
        }

        $entries = [];

        foreach (array_reverse($lines) as $line) {
            $entry = LogEntry::fromJson($line);

            if ($entry !== null) {
                $entries[] = $entry;
            }

            if (count($entries) === $count) {
                break;
            }
        }

        return $entries;
    }
}
