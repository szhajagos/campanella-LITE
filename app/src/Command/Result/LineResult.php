<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Result;

use Campanella\Lite\Command\Output;
use Campanella\Lite\I18n\Message;

/**
 * The outcome of one line of a batch.
 */
final class LineResult
{
    public function __construct(
        public readonly int $line,
        public readonly string $source,
        public readonly LineStatus $status,
        public readonly Message $message,
        public readonly ?Output $output = null,
        public readonly ?string $command = null,
    ) {
    }
}
