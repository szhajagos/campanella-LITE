<?php

declare(strict_types=1);

namespace Campanella\Lite\Command;

use Campanella\Lite\Model\Content;
use Campanella\Lite\Model\Layout;
use Campanella\Lite\Model\Menu;
use Campanella\Lite\Model\Page;
use Campanella\Lite\Model\Project;
use Campanella\Lite\Model\Redirects;
use Campanella\Lite\Model\Site;
use Campanella\Lite\Project\EntityRef;

/**
 * The in-memory copy of the project that the commands of one batch work on.
 *
 * Every change replaces the current Project snapshot and remembers which
 * entity was touched. Nothing is written here: the interpreter compares
 * the result with the original and writes only what really changed.
 */
final class Workspace
{
    private Project $project;

    /** @var array<string, EntityRef> */
    private array $touched = [];

    /**
     * @param string|null $user the admin running the batch; null on the command line
     */
    public function __construct(private readonly Project $original, public readonly ?string $user = null)
    {
        $this->project = $original;
    }

    public function project(): Project
    {
        return $this->project;
    }

    public function original(): Project
    {
        return $this->original;
    }

    public function defaultLanguage(): string
    {
        return $this->project->site->defaultLanguage;
    }

    public function putSite(Site $site): void
    {
        $this->project = $this->project->withSite($site);
        $this->touch(EntityRef::site());
    }

    public function putLayout(Layout $layout): void
    {
        $this->project = $this->project->withLayout($layout);
        $this->touch(EntityRef::layout($layout->name));
    }

    public function removeLayout(string $name): void
    {
        $this->project = $this->project->withoutLayout($name);
        $this->touch(EntityRef::layout($name));
    }

    public function putPage(Page $page): void
    {
        $this->project = $this->project->withPage($page);
        $this->touch(EntityRef::page($page->id));
    }

    public function removePage(int $id): void
    {
        $this->project = $this->project->withoutPage($id);
        $this->touch(EntityRef::page($id));
    }

    public function putContent(Content $content): void
    {
        $this->project = $this->project->withContent($content);
        $this->touch(EntityRef::content($content->name));
    }

    public function removeContent(string $name): void
    {
        $this->project = $this->project->withoutContent($name);
        $this->touch(EntityRef::content($name));
    }

    public function putMenu(Menu $menu): void
    {
        $this->project = $this->project->withMenu($menu);
        $this->touch(EntityRef::menu($menu->name));
    }

    public function removeMenu(string $name): void
    {
        $this->project = $this->project->withoutMenu($name);
        $this->touch(EntityRef::menu($name));
    }

    public function putRedirects(Redirects $redirects): void
    {
        $this->project = $this->project->withRedirects($redirects);
        $this->touch(EntityRef::redirects());
    }

    /**
     * The touched entities that really differ from the original, in the order they were first touched.
     *
     * @return list<EntityRef>
     */
    public function changes(): array
    {
        return array_values(array_filter(
            $this->touched,
            fn (EntityRef $ref): bool => self::snapshot($this->original, $ref) !== self::snapshot($this->project, $ref),
        ));
    }

    private function touch(EntityRef $ref): void
    {
        $this->touched[$ref->key()] ??= $ref;
    }

    /**
     * @return array<mixed>|null the stored form of the entity, null if it does not exist
     */
    private static function snapshot(Project $project, EntityRef $ref): ?array
    {
        return match ($ref->type) {
            EntityRef::SITE => $project->site->toArray(),
            EntityRef::LAYOUT => $project->layout($ref->id)?->toArray(),
            EntityRef::PAGE => $project->page((int) $ref->id)?->toArray(),
            EntityRef::CONTENT => $project->content($ref->id)?->toArray(),
            EntityRef::MENU => $project->menu($ref->id)?->toArray(),
            EntityRef::REDIRECTS => $project->redirects->toArray(),
        };
    }
}
