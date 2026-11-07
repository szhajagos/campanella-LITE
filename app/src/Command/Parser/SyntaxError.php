<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Parser;

use Campanella\Lite\I18n\Message;

/**
 * A line of the batch that could not be parsed.
 */
final class SyntaxError
{
    public function __construct(
        public readonly int $line,
        public readonly Message $message,
        public readonly string $source,
    ) {
    }
}
