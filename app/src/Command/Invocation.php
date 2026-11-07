<?php

declare(strict_types=1);

namespace Campanella\Lite\Command;

use Campanella\Lite\Command\Parser\ParsedCommand;

/**
 * A parsed command matched to its handler, with the arguments split off
 * from the command words. Already checked against the signature.
 */
final class Invocation
{
    /**
     * @param list<string> $arguments
     */
    public function __construct(
        public readonly ParsedCommand $command,
        public readonly Signature $signature,
        public readonly array $arguments,
    ) {
    }

    public function argument(int $index): ?string
    {
        return $this->arguments[$index] ?? null;
    }

    /** A required argument; the signature check guarantees it is present. */
    public function requiredArgument(int $index): string
    {
        return $this->arguments[$index]
            ?? throw CommandFailed::because('command.missing_argument', [
                'argument' => $this->signature->argumentName($index),
                'usage' => $this->signature->usage(),
            ]);
    }

    /**
     * @return list<string> the arguments from a position to the end
     */
    public function rest(int $from): array
    {
        return array_slice($this->arguments, $from);
    }

    public function option(string $name): ?string
    {
        $value = $this->command->options[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    public function flag(string $name): bool
    {
        return array_key_exists($name, $this->command->options);
    }

    public function text(): ?string
    {
        return $this->command->text;
    }
}
