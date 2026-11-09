<?php

declare(strict_types=1);

namespace Campanella\Lite\Command;

use Campanella\Lite\Command\Log\CommandLog;
use Campanella\Lite\Command\Log\LogEntry;
use Campanella\Lite\Command\Parser\BatchParser;
use Campanella\Lite\Command\Parser\ParsedBatch;
use Campanella\Lite\Command\Parser\ParsedCommand;
use Campanella\Lite\Command\Result\BatchResult;
use Campanella\Lite\Command\Result\LineResult;
use Campanella\Lite\Command\Result\LineStatus;
use Campanella\Lite\Command\Result\Outcome;
use Campanella\Lite\Exception\InvalidDataException;
use Campanella\Lite\I18n\Message;
use Campanella\Lite\Project\EntityRef;
use Campanella\Lite\Project\ProjectRepository;
use Campanella\Lite\Project\UndoHistory;
use Campanella\Lite\Routing\RedirectMaintainer;
use Campanella\Lite\Storage\WriteLock;
use Campanella\Lite\Validation\ProjectValidator;
use Campanella\Lite\Validation\Violation;

/**
 * Runs a batch of commands, all or nothing.
 *
 * 1. Parse the whole batch; a syntax error rejects it.
 * 2. Under the write lock, load the project and run every command on an
 *    in-memory workspace. The first failing command rejects the batch.
 * 3. Turn changed aliases into redirects, then validate the result. Only
 *    violations the batch would introduce count, so a problem that already
 *    existed never blocks unrelated work.
 * 4. Write the changed entities, unless this is a dry run, and tell the
 *    listener (which clears the cache of generated pages).
 * 5. Run the deferred work of commands such as "build" and "cache clear".
 *
 * Before writing, the interpreter keeps the files as they were (for undo)
 * and, after writing, adds the batch to the command log. "undo" itself is
 * handled here: it restores files instead of running on the workspace, so
 * it must be the only command of its batch.
 */
final class Interpreter
{
    public function __construct(
        private readonly ProjectRepository $repository,
        private readonly WriteLock $lock,
        private readonly CommandRegistry $registry,
        private readonly ProjectValidator $validator = new ProjectValidator(),
        private readonly BatchParser $parser = new BatchParser(),
        private readonly RedirectMaintainer $redirects = new RedirectMaintainer(),
        private readonly ?\Closure $onCommit = null,
        private readonly ?UndoHistory $undo = null,
        private readonly ?CommandLog $log = null,
    ) {
    }

    /**
     * @param string|null $user the admin running the batch, for commands about the user's own settings
     */
    /**
     * @param string|null                    $user       who runs the batch; null on the command line
     * @param (\Closure(Signature): bool)|null $authorize decides which commands the user may run; null allows all
     * @param string|null                    $approvedBy the admin who approved the batch, when it was proposed by an AI
     */
    public function run(string $input, bool $dryRun = false, ?string $user = null, ?\Closure $authorize = null, ?string $approvedBy = null): BatchResult
    {
        $batch = $this->parser->parse($input);
        $dryRun = $dryRun || $batch->dryRun;

        if (!$batch->isValid()) {
            return $this->rejectedBySyntax($batch, $dryRun);
        }

        if ($batch->commands === []) {
            return new BatchResult(Outcome::Unchanged, [], new Message('batch.empty'), $dryRun);
        }

        return $this->lock->synchronized(fn (): BatchResult => $this->execute($batch, $input, $dryRun, $user, $authorize, $approvedBy));
    }

    /**
     * The commands a batch consists of, without running it; null if the batch
     * has a syntax error or an unknown command (running it reports which).
     *
     * @return list<Signature>|null
     */
    public function signatures(string $input): ?array
    {
        $batch = $this->parser->parse($input);

        if (!$batch->isValid()) {
            return null;
        }

        $signatures = [];

        foreach ($batch->commands as $command) {
            try {
                [$handler] = $this->registry->resolve($command);
            } catch (CommandFailed) {
                return null;
            }

            $signatures[] = $handler->signature();
        }

        return $signatures;
    }

    /**
     * @param (\Closure(Signature): bool)|null $authorize
     */
    private function execute(ParsedBatch $batch, string $input, bool $dryRun, ?string $user, ?\Closure $authorize, ?string $approvedBy): BatchResult
    {
        $standalone = $this->standalone($batch);

        if ($standalone instanceof BatchResult) {
            return $standalone;
        }

        if ($standalone !== null) {
            return $this->undo($standalone, $input, $dryRun, $user, $authorize, $approvedBy);
        }

        try {
            $project = $this->repository->load();
        } catch (InvalidDataException $exception) {
            return new BatchResult(
                Outcome::Failed,
                [],
                new Message('batch.project_unreadable', ['detail' => $exception->getMessage()]),
                $dryRun,
            );
        }

        $workspace = new Workspace($project, $user);
        $lines = [];
        $deferred = [];
        $failed = false;

        foreach ($batch->commands as $command) {
            if ($failed) {
                $lines[] = new LineResult($command->line, $command->source, LineStatus::Skipped, new Message('line.skipped'));
                continue;
            }

            try {
                [$handler, $invocation] = $this->registry->resolve($command);

                if ($authorize !== null && !$authorize($invocation->signature)) {
                    throw CommandFailed::because('command.not_allowed', ['command' => $invocation->signature->name, 'user' => $user ?? 'cli']);
                }

                $result = $handler->execute($invocation, $workspace);

                if ($result->afterCommit !== null) {
                    $deferred[count($lines)] = $result->afterCommit;
                }

                $lines[] = new LineResult(
                    $command->line,
                    $command->source,
                    LineStatus::Ok,
                    $result->message,
                    $result->output,
                    $invocation->signature->name,
                );
            } catch (CommandFailed $failure) {
                $lines[] = new LineResult($command->line, $command->source, LineStatus::Error, $failure->reason);
                $failed = true;
            }
        }

        if ($failed) {
            return new BatchResult(Outcome::Rejected, $lines, new Message('batch.rejected'), $dryRun);
        }

        if ($workspace->changes() !== []) {
            $workspace->putRedirects($this->redirects->update($workspace->original(), $workspace->project())->redirects);
        }

        $changes = $workspace->changes();

        if ($changes === []) {
            $lines = $dryRun ? $lines : $this->runDeferred($lines, $deferred);

            return new BatchResult(Outcome::Unchanged, $lines, new Message('batch.unchanged'), $dryRun);
        }

        $files = array_map(static fn (EntityRef $ref): string => ProjectRepository::pathOf($ref), $changes);
        $violations = $this->newViolations($workspace);

        if ($violations !== []) {
            return new BatchResult(Outcome::Invalid, $lines, new Message('batch.invalid'), $dryRun, $violations, $files);
        }

        $summary = ['count' => count($files), 'files' => implode(', ', $files)];

        if ($dryRun) {
            return new BatchResult(Outcome::DryRun, $lines, new Message('batch.dry_run', $summary), true, [], $files);
        }

        $before = $this->undo?->capture($changes) ?? [];
        $this->repository->commit($workspace->project(), $changes);
        [$id, $time] = LogEntry::stamp();
        $this->undo?->record($id, $before, $changes, $time, $user, $input);
        $this->log?->append(new LogEntry($id, $time, $user, LogEntry::APPLIED, $files, $input, approvedBy: $approvedBy));

        if ($this->onCommit !== null) {
            ($this->onCommit)($changes);
        }

        $lines = $this->runDeferred($lines, $deferred);

        return new BatchResult(Outcome::Applied, $lines, new Message('batch.applied', $summary), false, [], $files);
    }

    /**
     * Finds an "undo" command. Alone in its batch it is returned to be run;
     * together with other commands it rejects the batch.
     */
    private function standalone(ParsedBatch $batch): ParsedCommand|BatchResult|null
    {
        $found = null;

        foreach ($batch->commands as $command) {
            try {
                [$handler] = $this->registry->resolve($command);
            } catch (CommandFailed) {
                continue; // reported when the batch runs
            }

            if ($handler->signature()->standalone) {
                $found = $command;
                break;
            }
        }

        if ($found === null || count($batch->commands) === 1) {
            return $found;
        }

        $lines = [];

        foreach ($batch->commands as $command) {
            $lines[] = $command === $found
                ? new LineResult($command->line, $command->source, LineStatus::Error, new Message('line.standalone', ['command' => implode(' ', $command->words)]))
                : new LineResult($command->line, $command->source, LineStatus::Skipped, new Message('line.skipped'));
        }

        return new BatchResult(Outcome::Rejected, $lines, new Message('batch.rejected'), $batch->dryRun);
    }

    /**
     * Puts back the files of the most recent applied batch.
     *
     * @param (\Closure(Signature): bool)|null $authorize
     */
    private function undo(ParsedCommand $command, string $input, bool $dryRun, ?string $user, ?\Closure $authorize, ?string $approvedBy): BatchResult
    {
        $fail = fn (string $key, array $params = []): BatchResult => new BatchResult(
            Outcome::Rejected,
            [new LineResult($command->line, $command->source, LineStatus::Error, new Message($key, $params))],
            new Message('batch.rejected'),
            $dryRun,
        );

        try {
            [, $invocation] = $this->registry->resolve($command);
        } catch (CommandFailed $failure) {
            return $fail($failure->reason->key, $failure->reason->params);
        }

        if ($authorize !== null && !$authorize($invocation->signature)) {
            return $fail('command.not_allowed', ['command' => 'undo', 'user' => $user ?? 'cli']);
        }

        if ($this->undo === null) {
            return $fail('undo.unavailable');
        }

        try {
            $snapshot = $this->undo->latest();
        } catch (InvalidDataException $exception) {
            return $fail('undo.unreadable', ['detail' => $exception->getMessage()]);
        }

        if ($snapshot === null) {
            return $fail('undo.nothing');
        }

        $conflicts = $this->undo->conflicts($snapshot);

        if ($conflicts !== [] && !$invocation->flag('force')) {
            return $fail('undo.conflict', ['files' => implode(', ', $conflicts)]);
        }

        $files = array_keys($snapshot->files);
        $summary = ['count' => count($files), 'files' => implode(', ', $files)];
        $params = ['id' => $snapshot->id, 'time' => $snapshot->time, 'user' => $snapshot->user ?? 'cli', 'count' => count($files)];

        if ($dryRun) {
            return new BatchResult(
                Outcome::DryRun,
                [new LineResult($command->line, $command->source, LineStatus::Ok, new Message('ok.undo_planned', $params), null, 'undo')],
                new Message('batch.dry_run', $summary),
                true,
                [],
                $files,
            );
        }

        $this->undo->restore($snapshot);
        [$id, $time] = LogEntry::stamp();
        $this->log?->append(new LogEntry($id, $time, $user, LogEntry::UNDONE, $files, $input, $snapshot->id, $approvedBy));

        if ($this->onCommit !== null) {
            ($this->onCommit)([]);
        }

        return new BatchResult(
            Outcome::Applied,
            [new LineResult($command->line, $command->source, LineStatus::Ok, new Message('ok.undone', $params), null, 'undo')],
            new Message('batch.applied', $summary),
            false,
            [],
            $files,
        );
    }

    /**
     * Runs the work commands deferred until after the commit, and puts their results in place.
     *
     * @param list<LineResult>                      $lines
     * @param array<int, \Closure(): CommandResult> $deferred line index => work
     *
     * @return list<LineResult>
     */
    private function runDeferred(array $lines, array $deferred): array
    {
        foreach ($deferred as $index => $work) {
            $line = $lines[$index];

            try {
                $result = $work();
                $lines[$index] = new LineResult($line->line, $line->source, LineStatus::Ok, $result->message, $result->output, $line->command);
            } catch (CommandFailed $failure) {
                $lines[$index] = new LineResult($line->line, $line->source, LineStatus::Error, $failure->reason, null, $line->command);
            }
        }

        return array_values($lines);
    }

    /**
     * @return list<Violation>
     */
    private function newViolations(Workspace $workspace): array
    {
        $before = array_map('strval', $this->validator->validate($workspace->original()));

        return array_values(array_filter(
            $this->validator->validate($workspace->project()),
            static fn (Violation $violation): bool => !in_array((string) $violation, $before, true),
        ));
    }

    private function rejectedBySyntax(ParsedBatch $batch, bool $dryRun): BatchResult
    {
        $lines = [];

        foreach ($batch->errors as $error) {
            $lines[$error->line] = new LineResult($error->line, $error->source, LineStatus::Error, $error->message);
        }

        foreach ($batch->commands as $command) {
            $lines[$command->line] ??= new LineResult($command->line, $command->source, LineStatus::Skipped, new Message('line.skipped'));
        }

        ksort($lines);

        return new BatchResult(Outcome::Rejected, array_values($lines), new Message('batch.rejected'), $dryRun);
    }
}
