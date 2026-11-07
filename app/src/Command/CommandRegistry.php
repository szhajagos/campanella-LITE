<?php

declare(strict_types=1);

namespace Campanella\Lite\Command;

use Campanella\Lite\Command\Parser\ParsedCommand;
use InvalidArgumentException;

/**
 * Knows every command and matches parsed commands to their handlers.
 *
 * The longest run of leading words that names a command wins, so
 * "layout region add default hero" runs "layout region add" with the
 * arguments "default" and "hero".
 */
final class CommandRegistry
{
    /** @var array<string, CommandHandler> */
    private array $handlers = [];

    /**
     * @param iterable<CommandHandler> $handlers
     */
    public function __construct(iterable $handlers = [])
    {
        foreach ($handlers as $handler) {
            $this->register($handler);
        }
    }

    public function register(CommandHandler $handler): void
    {
        $name = $handler->signature()->name;

        if (isset($this->handlers[$name])) {
            throw new InvalidArgumentException(sprintf('The command "%s" is already registered.', $name));
        }

        $this->handlers[$name] = $handler;
        ksort($this->handlers);
    }

    /**
     * @return array<string, CommandHandler> command name => handler, sorted by name
     */
    public function all(): array
    {
        return $this->handlers;
    }

    public function find(string $name): ?CommandHandler
    {
        return $this->handlers[$name] ?? null;
    }

    /**
     * Finds the handler for a parsed command and checks the command against its signature.
     *
     * @return array{0: CommandHandler, 1: Invocation}
     *
     * @throws CommandFailed
     */
    public function resolve(ParsedCommand $command): array
    {
        for ($length = count($command->words); $length > 0; $length--) {
            $name = implode(' ', array_slice($command->words, 0, $length));
            $handler = $this->handlers[$name] ?? null;

            if ($handler !== null) {
                $invocation = new Invocation($command, $handler->signature(), array_slice($command->words, $length));
                $this->check($invocation);

                return [$handler, $invocation];
            }
        }

        throw $this->unknown($command);
    }

    private function check(Invocation $invocation): void
    {
        $signature = $invocation->signature;
        $usage = ['usage' => $signature->usage()];
        $count = count($invocation->arguments);

        if ($count < $signature->requiredArgumentCount()) {
            throw CommandFailed::because('command.missing_argument', ['argument' => $signature->argumentName($count)] + $usage);
        }

        $max = $signature->maxArgumentCount();

        if ($max !== null && $count > $max) {
            throw CommandFailed::because('command.too_many_arguments', ['argument' => $invocation->arguments[$max]] + $usage);
        }

        foreach ($invocation->command->options as $option => $value) {
            if (!array_key_exists($option, $signature->options)) {
                throw CommandFailed::because('command.unknown_option', ['option' => '--' . $option] + $usage);
            }

            if ($signature->options[$option] && $value === true) {
                throw CommandFailed::because('command.option_needs_value', ['option' => '--' . $option] + $usage);
            }

            if (!$signature->options[$option] && $value !== true) {
                throw CommandFailed::because('command.option_takes_no_value', ['option' => '--' . $option] + $usage);
            }
        }

        $text = $invocation->command->text;

        if ($text !== null && $signature->text === TextInput::None) {
            throw CommandFailed::because('command.text_not_accepted', $usage);
        }

        if ($text === null && $signature->text === TextInput::Required) {
            throw CommandFailed::because('command.text_required', $usage);
        }
    }

    private function unknown(ParsedCommand $command): CommandFailed
    {
        $typed = implode(' ', array_slice($command->words, 0, 3));
        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach (array_keys($this->handlers) as $name) {
            $candidate = implode(' ', array_slice($command->words, 0, substr_count($name, ' ') + 1));
            $distance = levenshtein($candidate, $name);

            if ($distance < $bestDistance) {
                $best = $name;
                $bestDistance = $distance;
            }
        }

        if ($best !== null && $bestDistance <= 2) {
            return CommandFailed::because('command.unknown_with_suggestion', ['command' => $typed, 'suggestion' => $best]);
        }

        return CommandFailed::because('command.unknown', ['command' => $typed]);
    }
}
