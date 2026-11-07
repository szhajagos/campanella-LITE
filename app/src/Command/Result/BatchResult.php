<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Result;

use Campanella\Lite\I18n\Message;
use Campanella\Lite\Validation\Violation;

/**
 * The outcome of a whole batch: one result per line, the violations that
 * blocked it (if any), the files it changed or would change, and a summary.
 */
final class BatchResult
{
    /**
     * @param list<LineResult> $lines
     * @param list<Violation>  $violations new violations the changes would cause
     * @param list<string>     $changedFiles project-relative paths
     */
    public function __construct(
        public readonly Outcome $outcome,
        public readonly array $lines,
        public readonly Message $summary,
        public readonly bool $dryRun = false,
        public readonly array $violations = [],
        public readonly array $changedFiles = [],
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->outcome->isSuccess();
    }
}
