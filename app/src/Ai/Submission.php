<?php

declare(strict_types=1);

namespace Campanella\Lite\Ai;

use Campanella\Lite\Command\Result\BatchResult;

/**
 * What became of a batch an AI user submitted: it ran (or was refused),
 * or it waits for approval as a proposal.
 */
final class Submission
{
    private function __construct(
        public readonly BatchResult $result,
        public readonly ?Proposal $proposal = null,
    ) {
    }

    public static function done(BatchResult $result): self
    {
        return new self($result);
    }

    /** $preview is the dry run that was shown with the proposal. */
    public static function pending(Proposal $proposal, BatchResult $preview): self
    {
        return new self($preview, $proposal);
    }

    public function isPending(): bool
    {
        return $this->proposal !== null;
    }
}
