<?php

declare(strict_types=1);

namespace Campanella\Lite;

use Campanella\Lite\Command\CommandRegistry;
use Campanella\Lite\Command\Format\JsonFormatter;
use Campanella\Lite\Command\Format\TextFormatter;
use Campanella\Lite\Command\Handler\CheckCommand;
use Campanella\Lite\Command\Handler\Content\ContentCreateCommand;
use Campanella\Lite\Command\Handler\Content\ContentDeleteCommand;
use Campanella\Lite\Command\Handler\Content\ContentEditCommand;
use Campanella\Lite\Command\Handler\Content\ContentTranslateCommand;
use Campanella\Lite\Command\Handler\GetCommand;
use Campanella\Lite\Command\Handler\HelpCommand;
use Campanella\Lite\Command\Handler\Language\LanguageCommand;
use Campanella\Lite\Command\Handler\Layout\LayoutColumnAddCommand;
use Campanella\Lite\Command\Handler\Layout\LayoutColumnRemoveCommand;
use Campanella\Lite\Command\Handler\Layout\LayoutCreateCommand;
use Campanella\Lite\Command\Handler\Layout\LayoutDeleteCommand;
use Campanella\Lite\Command\Handler\Layout\LayoutPlacementCommand;
use Campanella\Lite\Command\Handler\Layout\LayoutRegionAddCommand;
use Campanella\Lite\Command\Handler\Layout\LayoutRegionRemoveCommand;
use Campanella\Lite\Command\Handler\ListCommand;
use Campanella\Lite\Command\Handler\Menu\MenuCreateCommand;
use Campanella\Lite\Command\Handler\Menu\MenuDeleteCommand;
use Campanella\Lite\Command\Handler\Menu\MenuItemAddCommand;
use Campanella\Lite\Command\Handler\Menu\MenuItemRemoveCommand;
use Campanella\Lite\Command\Handler\Page\PageAliasCommand;
use Campanella\Lite\Command\Handler\Page\PageCreateCommand;
use Campanella\Lite\Command\Handler\Page\PageDeleteCommand;
use Campanella\Lite\Command\Handler\Page\PagePlacementCommand;
use Campanella\Lite\Command\Handler\Page\PageStatusCommand;
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
                new CheckCommand(),
                new PageCreateCommand(),
                new PageDeleteCommand(),
                new PageAliasCommand(),
                PageStatusCommand::publish(),
                PageStatusCommand::unpublish(),
                new PagePlacementCommand(PagePlacementCommand::PLACE),
                new PagePlacementCommand(PagePlacementCommand::UNPLACE),
                new PagePlacementCommand(PagePlacementCommand::RESET),
                new ContentCreateCommand(),
                new ContentEditCommand(),
                new ContentTranslateCommand(),
                new ContentDeleteCommand(),
                new LayoutCreateCommand(),
                new LayoutDeleteCommand(),
                new LayoutRegionAddCommand(),
                new LayoutRegionRemoveCommand(),
                new LayoutColumnAddCommand(),
                new LayoutColumnRemoveCommand(),
                new LayoutPlacementCommand(place: true),
                new LayoutPlacementCommand(place: false),
                new MenuCreateCommand(),
                new MenuDeleteCommand(),
                new MenuItemAddCommand(),
                new MenuItemRemoveCommand(),
                new LanguageCommand(LanguageCommand::ADD),
                new LanguageCommand(LanguageCommand::REMOVE),
                new LanguageCommand(LanguageCommand::DEFAULT),
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
