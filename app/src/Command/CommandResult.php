<?php

declare(strict_types=1);

namespace Campanella\Lite\Command;

use Campanella\Lite\I18n\Message;

/**
 * What a successful command reports.
 */
final class CommandResult
{
    /**
     * @param (\Closure(): CommandResult)|null $afterCommit work that touches files outside the project
     *        (generating pages, clearing the cache). It runs only after the batch has been written,
     *        never on a dry run or a rejected batch, and its result replaces this one.
     */
    public function __construct(
        public readonly Message $message,
        public readonly ?Output $output = null,
        public readonly ?\Closure $afterCommit = null,
    ) {
    }

    /**
     * @param array<string, string|int> $params
     * @param \Closure(): CommandResult  $afterCommit
     */
    public static function deferred(string $key, array $params, \Closure $afterCommit): self
    {
        return new self(new Message($key, $params), null, $afterCommit);
    }

    /**
     * @param array<string, string|int> $params
     */
    public static function ok(string $key, array $params = [], ?Output $output = null): self
    {
        return new self(new Message($key, $params), $output);
    }
}
