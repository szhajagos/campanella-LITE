<?php

declare(strict_types=1);

namespace Campanella\Lite\Command;

use Campanella\Lite\Command\Parser\BatchParser;
use Campanella\Lite\Command\Parser\ParsedBatch;
use Campanella\Lite\Command\Result\BatchResult;
use Campanella\Lite\Command\Result\LineResult;
use Campanella\Lite\Command\Result\LineStatus;
use Campanella\Lite\Command\Result\Outcome;
use Campanella\Lite\Exception\InvalidDataException;
use Campanella\Lite\I18n\Message;
use Campanella\Lite\Project\EntityRef;
use Campanella\Lite\Project\ProjectRepository;
use Campanella\Lite\Storage\WriteLock;
use Campanella\Lite\Validation\ProjectValidator;
use Campanella\Lite\Validation\Violation;

/**
 * Runs a batch of commands, all or nothing.
 *
 * 1. Parse the whole batch; a syntax error rejects it.
 * 2. Under the write lock, load the project and run every command on an
 *    in-memory workspace. The first failing command rejects the batch.
 * 3. Validate the result. Only violations the batch would introduce count,
 *    so a problem that already existed never blocks unrelated work.
 * 4. Write the changed entities, unless this is a dry run.
 */
final class Interpreter
{
    public function __construct(
        private readonly ProjectRepository $repository,
        private readonly WriteLock $lock,
        private readonly CommandRegistry $registry,
        private readonly ProjectValidator $validator = new ProjectValidator(),
        private readonly BatchParser $parser = new BatchParser(),
    ) {
    }

    public function run(string $input, bool $dryRun = false): BatchResult
    {
        $batch = $this->parser->parse($input);
        $dryRun = $dryRun || $batch->dryRun;

        if (!$batch->isValid()) {
            return $this->rejectedBySyntax($batch, $dryRun);
        }

        if ($batch->commands === []) {
            return new BatchResult(Outcome::Unchanged, [], new Message('batch.empty'), $dryRun);
        }

        return $this->lock->synchronized(fn (): BatchResult => $this->execute($batch, $dryRun));
    }

    private function execute(ParsedBatch $batch, bool $dryRun): BatchResult
    {
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

        $workspace = new Workspace($project);
        $lines = [];
        $failed = false;

        foreach ($batch->commands as $command) {
            if ($failed) {
                $lines[] = new LineResult($command->line, $command->source, LineStatus::Skipped, new Message('line.skipped'));
                continue;
            }

            try {
                [$handler, $invocation] = $this->registry->resolve($command);
                $result = $handler->execute($invocation, $workspace);
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

        $changes = $workspace->changes();

        if ($changes === []) {
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

        $this->repository->commit($workspace->project(), $changes);

        return new BatchResult(Outcome::Applied, $lines, new Message('batch.applied', $summary), false, [], $files);
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
