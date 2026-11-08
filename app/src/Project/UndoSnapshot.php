<?php

declare(strict_types=1);

namespace Campanella\Lite\Project;

/**
 * The project files as they were before one applied batch.
 */
final class UndoSnapshot
{
    /**
     * @param array<string, array{before: string|null, after: string|null}> $files
     *        project-relative path => earlier contents (null: the file did not exist)
     *        and the sha1 of the contents the batch wrote (null: the batch deleted it)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $time,
        public readonly ?string $user,
        public readonly string $batch,
        public readonly array $files,
    ) {
    }
}
