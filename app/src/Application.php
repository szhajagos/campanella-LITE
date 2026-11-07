<?php

declare(strict_types=1);

namespace Campanella\Lite;

use Campanella\Lite\Command\CommandRegistry;
use Campanella\Lite\Command\Format\JsonFormatter;
use Campanella\Lite\Command\Format\TextFormatter;
use Campanella\Lite\Command\Handler\GetCommand;
use Campanella\Lite\Command\Handler\HelpCommand;
use Campanella\Lite\Command\Handler\ListCommand;
use Campanella\Lite\Command\Handler\SetCommand;
use Campanella\Lite\Command\Interpreter;
use Campanella\Lite\I18n\Translator;
use Campanella\Lite\Project\ProjectRepository;
use Campanella\Lite\Storage\FileStore;
use Campanella\Lite\Storage\WriteLock;

/**
 * Wires the services of one installation together.
 *
 * Kept deliberately small and explicit instead of using a container:
 * every dependency of the system can be read here.
 */
final class Application
{
    private ?CommandRegistry $registry = null;
    private ?Translator $translator = null;

    public function __construct(public readonly Paths $paths)
    {
    }

    public function interpreter(): Interpreter
    {
        return new Interpreter(
            new ProjectRepository(new FileStore($this->paths->project())),
            new WriteLock($this->paths->writeLock()),
            $this->registry(),
        );
    }

    public function registry(): CommandRegistry
    {
        if ($this->registry === null) {
            $this->registry = new CommandRegistry([
                new GetCommand(),
                new SetCommand(),
                new ListCommand(),
            ]);
            $this->registry->register(new HelpCommand($this->registry));
        }

        return $this->registry;
    }

    public function translator(): Translator
    {
        return $this->translator ??= new Translator(self::translationsDirectory());
    }

    public function textFormatter(): TextFormatter
    {
        return new TextFormatter($this->translator());
    }

    public function jsonFormatter(): JsonFormatter
    {
        return new JsonFormatter($this->translator());
    }

    /** The message catalogues ship with the code, not with the project. */
    public static function translationsDirectory(): string
    {
        return dirname(__DIR__) . '/translations';
    }
}
