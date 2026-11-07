<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Parser;

/**
 * Everything typed into the command box, parsed.
 */
final class ParsedBatch
{
    /**
     * @param list<ParsedCommand> $commands
     * @param list<SyntaxError>   $errors
     */
    public function __construct(
        public readonly array $commands,
        public readonly array $errors,
        public readonly bool $dryRun,
    ) {
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
