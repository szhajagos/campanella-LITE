<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Parser;

/**
 * One command as written: its words, options and optional multi-line text.
 *
 * The parser does not know which words name the command and which are its
 * arguments ("layout region add default hero"); the registry decides that.
 */
final class ParsedCommand
{
    /**
     * @param list<string>               $words   command words and positional arguments, in order
     * @param array<string, string|true> $options "--key=value" as key => value, "--flag" as key => true
     */
    public function __construct(
        public readonly int $line,
        public readonly array $words,
        public readonly array $options,
        public readonly ?string $text,
        public readonly string $source,
    ) {
    }
}
