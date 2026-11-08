<?php

declare(strict_types=1);

namespace Campanella\Lite;

use Campanella\Lite\Admin\AdminController;
use Campanella\Lite\Admin\AuthLog;
use Campanella\Lite\Admin\History;
use Campanella\Lite\Admin\LoginThrottle;
use Campanella\Lite\Admin\Session\Session;
use Campanella\Lite\Admin\UserRepository;
use Campanella\Lite\Command\CommandRegistry;
use Campanella\Lite\Command\Format\JsonFormatter;
use Campanella\Lite\Command\Format\TextFormatter;
use Campanella\Lite\Command\Handler\BuildCommand;
use Campanella\Lite\Command\Handler\CacheClearCommand;
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
use Campanella\Lite\Command\Handler\RedirectRemoveCommand;
use Campanella\Lite\Command\Handler\SetCommand;
use Campanella\Lite\Command\Handler\UserLocaleCommand;
use Campanella\Lite\Command\Interpreter;
use Campanella\Lite\Http\FrontController;
use Campanella\Lite\I18n\Translator;
use Campanella\Lite\Project\EntityRef;
use Campanella\Lite\Project\ProjectRepository;
use Campanella\Lite\Render\PageCache;
use Campanella\Lite\Render\PageRenderer;
use Campanella\Lite\Render\SiteBuilder;
use Campanella\Lite\Routing\Router;
use Campanella\Lite\Storage\FileStore;
use Campanella\Lite\Storage\WriteLock;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

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
    private ?PageRenderer $renderer = null;

    public function __construct(public readonly Paths $paths)
    {
    }

    public function interpreter(): Interpreter
    {
        $cache = $this->pageCache();

        return new Interpreter(
            $this->repository(),
            $this->lock(),
            $this->registry(),
            // Any change may affect any page (menus, layouts, the site name), so all generated pages go.
            onCommit: static function (array $changes) use ($cache): void {
                /** @var list<EntityRef> $changes */
                $cache->clear();
            },
        );
    }

    public function frontController(): FrontController
    {
        return new FrontController($this->repository(), $this->lock(), new Router(), $this->renderer(), $this->pageCache());
    }

    /**
     * @param (\Closure(): int)|null $clock
     */
    public function adminController(Session $session, ?\Closure $clock = null): AdminController
    {
        $var = new FileStore($this->paths->var());

        return new AdminController(
            $this,
            $this->users(),
            $session,
            new LoginThrottle($var),
            new AuthLog($this->paths->var() . '/log/auth.log'),
            new History($var),
            new Environment(new FilesystemLoader(dirname(__DIR__) . '/admin/templates'), [
                'autoescape' => 'html',
                'strict_variables' => true,
                'cache' => $this->paths->var() . '/cache/twig-admin',
                'auto_reload' => true,
            ]),
            $clock,
        );
    }

    public function users(): UserRepository
    {
        return new UserRepository(new FileStore($this->paths->config()));
    }

    public function repository(): ProjectRepository
    {
        return new ProjectRepository(new FileStore($this->paths->project()));
    }

    public function lock(): WriteLock
    {
        return new WriteLock($this->paths->writeLock());
    }

    public function renderer(): PageRenderer
    {
        return $this->renderer ??= new PageRenderer(self::themesDirectory(), $this->translator(), $this->paths->var() . '/cache/twig');
    }

    public function pageCache(): PageCache
    {
        return new PageCache(new FileStore($this->paths->public()), new FileStore($this->paths->var()));
    }

    public function siteBuilder(): SiteBuilder
    {
        return new SiteBuilder($this->renderer(), $this->pageCache());
    }

    public function registry(): CommandRegistry
    {
        if ($this->registry === null) {
            $this->registry = new CommandRegistry([
                new GetCommand(),
                new SetCommand(),
                new ListCommand(),
                new CheckCommand(),
                new BuildCommand($this->siteBuilder()),
                new CacheClearCommand($this->pageCache()),
                new RedirectRemoveCommand(),
                new UserLocaleCommand($this->users(), $this->translator()),
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

    /** Built-in themes: templates in {theme}/templates, assets in public/assets/themes/{theme}. */
    public static function themesDirectory(): string
    {
        return dirname(__DIR__) . '/themes';
    }

    /** The message catalogues ship with the code, not with the project. */
    public static function translationsDirectory(): string
    {
        return dirname(__DIR__) . '/translations';
    }
}
