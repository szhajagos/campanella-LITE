<?php

declare(strict_types=1);

namespace Campanella\Lite\Command;

/**
 * What a command accepts. The interpreter checks every invocation against
 * the signature before the command runs, so commands never see an unknown
 * option or a missing argument.
 */
final class Signature
{
    /**
     * @param string                $name      command words, e.g. "layout region add"
     * @param list<string>          $arguments argument names in order; "?" suffix = optional, "..." suffix = rest
     * @param array<string, bool>   $options   option name => whether it takes a value
     * @param list<string>          $examples  example command lines
     * @param bool                  $readOnly  true if the command never changes the project
     */
    public function __construct(
        public readonly string $name,
        public readonly array $arguments = [],
        public readonly array $options = [],
        public readonly TextInput $text = TextInput::None,
        public readonly array $examples = [],
        public readonly bool $readOnly = false,
    ) {
    }

    /** The help key prefix of this command, e.g. "help.layout_region_add". */
    public function helpKey(): string
    {
        return 'help.' . str_replace(' ', '_', $this->name);
    }

    public function usage(): string
    {
        $parts = [$this->name];

        foreach ($this->arguments as $argument) {
            $parts[] = match (true) {
                str_ends_with($argument, '...') => '[<' . substr($argument, 0, -3) . '> ...]',
                str_ends_with($argument, '?') => '[<' . substr($argument, 0, -1) . '>]',
                default => '<' . $argument . '>',
            };
        }

        foreach ($this->options as $option => $takesValue) {
            $parts[] = '[--' . $option . ($takesValue ? '=<' . $option . '>' : '') . ']';
        }

        if ($this->text !== TextInput::None) {
            $parts[] = $this->text === TextInput::Required ? '<<< text >>>' : '[<<< text >>>]';
        }

        return implode(' ', $parts);
    }

    public function requiredArgumentCount(): int
    {
        return count(array_filter($this->arguments, static fn (string $a): bool => !str_ends_with($a, '?') && !str_ends_with($a, '...')));
    }

    public function maxArgumentCount(): ?int
    {
        foreach ($this->arguments as $argument) {
            if (str_ends_with($argument, '...')) {
                return null;
            }
        }

        return count($this->arguments);
    }

    /** The plain name of the argument at a position, without "?" or "...". */
    public function argumentName(int $index): string
    {
        return rtrim($this->arguments[$index] ?? 'argument', '?.');
    }
}
