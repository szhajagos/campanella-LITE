<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Handler;

use Campanella\Lite\Command\CommandFailed;
use Campanella\Lite\Command\CommandHandler;
use Campanella\Lite\Command\CommandResult;
use Campanella\Lite\Command\Invocation;
use Campanella\Lite\Command\Log\CommandLog;
use Campanella\Lite\Command\Log\LogEntry;
use Campanella\Lite\Command\Output;
use Campanella\Lite\Command\Signature;
use Campanella\Lite\Command\Workspace;
use Campanella\Lite\I18n\Message;

/**
 * Shows the most recent batches that changed the project, newest first.
 */
final class LogCommand implements CommandHandler
{
    private const DEFAULT_COUNT = 10;
    private const MAX_COUNT = 200;

    public function __construct(private readonly CommandLog $log)
    {
    }

    public function signature(): Signature
    {
        return new Signature(
            name: 'log',
            options: ['last' => true],
            examples: ['log', 'log --last=30'],
            readOnly: true,
        );
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $last = $invocation->option('last');
        $count = self::DEFAULT_COUNT;

        if ($last !== null) {
            if (preg_match('/^[1-9][0-9]{0,3}$/', $last) !== 1 || (int) $last > self::MAX_COUNT) {
                throw CommandFailed::because('log.invalid_count', ['value' => $last, 'max' => self::MAX_COUNT]);
            }

            $count = (int) $last;
        }

        $rows = array_map(static fn (LogEntry $entry): array => [
            $entry->id,
            self::shortTime($entry->time),
            $entry->user ?? 'cli',
            $entry->action === LogEntry::UNDONE
                ? new Message('log.action_undone', ['id' => $entry->undoes ?? '?'])
                : new Message('log.action_applied'),
            (string) count($entry->files),
            $entry->summary(),
        ], $this->log->recent($count));

        return CommandResult::ok(
            'ok.log',
            ['count' => count($rows)],
            Output::table(['id', 'time', 'user', 'action', 'files', 'batch'], $rows),
        );
    }

    /** "2026-11-08T09:15:00+01:00" as "2026-11-08 09:15:00". */
    private static function shortTime(string $time): string
    {
        return preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})/', $time, $match) === 1 ? $match[1] . ' ' . $match[2] : $time;
    }
}
