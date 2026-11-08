<?php

declare(strict_types=1);

namespace Campanella\Lite\Project;

use Campanella\Lite\Exception\InvalidDataException;
use Campanella\Lite\Model\Content;
use Campanella\Lite\Model\ContentTranslation;
use Campanella\Lite\Model\Layout;
use Campanella\Lite\Model\Menu;
use Campanella\Lite\Model\Page;
use Campanella\Lite\Model\Project;
use Campanella\Lite\Model\Redirects;
use Campanella\Lite\Model\Site;
use Campanella\Lite\Storage\FileStore;
use Campanella\Lite\Storage\FrontMatterCodec;
use Campanella\Lite\Storage\YamlCodec;

/**
 * Loads the project directory into a Project and writes entities back, one file each.
 *
 *     site.yaml
 *     layouts/{name}.yaml
 *     pages/{id}.yaml
 *     content/{name}/{language}.md
 *     menus/{name}.yaml
 *     redirects.yaml                (optional)
 *
 * The repository checks structure (file names, field types). It does not
 * check references between entities; that is the validator's job, and it
 * runs before anything is saved.
 */
final class ProjectRepository
{
    public const SITE_FILE = 'site.yaml';
    public const LAYOUT_DIR = 'layouts';
    public const PAGE_DIR = 'pages';
    public const CONTENT_DIR = 'content';
    public const MENU_DIR = 'menus';
    public const REDIRECTS_FILE = 'redirects.yaml';

    public function __construct(private readonly FileStore $store)
    {
    }

    /** Whether there is a project at all (an installation that has not been set up has none). */
    public function exists(): bool
    {
        return $this->store->exists(self::SITE_FILE);
    }

    public function load(): Project
    {
        return new Project(
            $this->loadSite(),
            $this->loadLayouts(),
            $this->loadPages(),
            $this->loadContents(),
            $this->loadMenus(),
            $this->loadRedirects(),
        );
    }

    public function saveSite(Site $site): void
    {
        $this->writeYaml(self::SITE_FILE, $site->toArray());
    }

    public function saveLayout(Layout $layout): void
    {
        $this->writeYaml(self::LAYOUT_DIR . '/' . $layout->name . '.yaml', $layout->toArray());
    }

    public function deleteLayout(string $name): bool
    {
        return $this->store->delete(self::LAYOUT_DIR . '/' . $name . '.yaml');
    }

    public function savePage(Page $page): void
    {
        $this->writeYaml(self::PAGE_DIR . '/' . $page->id . '.yaml', $page->toArray());
    }

    public function deletePage(int $id): bool
    {
        return $this->store->delete(self::PAGE_DIR . '/' . $id . '.yaml');
    }

    /**
     * Writes every translation and removes language files that are no longer part of the content.
     */
    public function saveContent(Content $content): void
    {
        $directory = self::CONTENT_DIR . '/' . $content->name;

        foreach ($content->translations as $language => $translation) {
            $this->store->write(
                $directory . '/' . $language . '.md',
                FrontMatterCodec::encode($translation->frontMatter(), $translation->body),
            );
        }

        foreach ($this->store->listFiles($directory, 'md') as $language) {
            if ($content->translation($language) === null) {
                $this->store->delete($directory . '/' . $language . '.md');
            }
        }
    }

    public function deleteContent(string $name): bool
    {
        $directory = self::CONTENT_DIR . '/' . $name;
        $deleted = false;

        foreach ($this->store->listFiles($directory, 'md') as $language) {
            $deleted = $this->store->delete($directory . '/' . $language . '.md') || $deleted;
        }

        $this->store->deleteDirectoryIfEmpty($directory);

        return $deleted;
    }

    public function saveMenu(Menu $menu): void
    {
        $this->writeYaml(self::MENU_DIR . '/' . $menu->name . '.yaml', $menu->toArray());
    }

    public function deleteMenu(string $name): bool
    {
        return $this->store->delete(self::MENU_DIR . '/' . $name . '.yaml');
    }

    /**
     * Writes redirects.yaml, or removes it when there are no redirects.
     */
    public function saveRedirects(Redirects $redirects): void
    {
        if ($redirects->isEmpty()) {
            $this->store->delete(self::REDIRECTS_FILE);

            return;
        }

        $this->writeYaml(self::REDIRECTS_FILE, $redirects->toArray());
    }

    /**
     * Writes the given entities as they are in the project, and deletes those
     * that no longer exist in it.
     *
     * @param list<EntityRef> $refs
     */
    public function commit(Project $project, array $refs): void
    {
        foreach ($refs as $ref) {
            match ($ref->type) {
                EntityRef::SITE => $this->saveSite($project->site),
                EntityRef::LAYOUT => ($layout = $project->layout($ref->id)) !== null ? $this->saveLayout($layout) : $this->deleteLayout($ref->id),
                EntityRef::PAGE => ($page = $project->page((int) $ref->id)) !== null ? $this->savePage($page) : $this->deletePage((int) $ref->id),
                EntityRef::CONTENT => ($content = $project->content($ref->id)) !== null ? $this->saveContent($content) : $this->deleteContent($ref->id),
                EntityRef::MENU => ($menu = $project->menu($ref->id)) !== null ? $this->saveMenu($menu) : $this->deleteMenu($ref->id),
                EntityRef::REDIRECTS => $this->saveRedirects($project->redirects),
            };
        }
    }

    /**
     * The file (or, for content, the directory) that stores an entity, relative to the project directory.
     */
    public static function pathOf(EntityRef $ref): string
    {
        return match ($ref->type) {
            EntityRef::SITE => self::SITE_FILE,
            EntityRef::LAYOUT => self::LAYOUT_DIR . '/' . $ref->id . '.yaml',
            EntityRef::PAGE => self::PAGE_DIR . '/' . $ref->id . '.yaml',
            EntityRef::CONTENT => self::CONTENT_DIR . '/' . $ref->id . '/',
            EntityRef::MENU => self::MENU_DIR . '/' . $ref->id . '.yaml',
            EntityRef::REDIRECTS => self::REDIRECTS_FILE,
        };
    }

    private function loadSite(): Site
    {
        return Site::fromArray($this->readYaml(self::SITE_FILE), self::SITE_FILE);
    }

    /**
     * @return list<Layout>
     */
    private function loadLayouts(): array
    {
        $layouts = [];

        foreach ($this->store->listFiles(self::LAYOUT_DIR, 'yaml') as $name) {
            $source = self::LAYOUT_DIR . '/' . $name . '.yaml';
            $layout = Layout::fromArray($this->readYaml($source), $source);
            $this->assertMatchesFileName($layout->name, $name, $source, 'name');
            $layouts[] = $layout;
        }

        return $layouts;
    }

    /**
     * @return list<Page>
     */
    private function loadPages(): array
    {
        $pages = [];

        foreach ($this->store->listFiles(self::PAGE_DIR, 'yaml') as $name) {
            $source = self::PAGE_DIR . '/' . $name . '.yaml';

            if (preg_match('/^[1-9][0-9]*$/', $name) !== 1) {
                throw new InvalidDataException($source, 'page files must be named after the page id, e.g. "12.yaml"');
            }

            $page = Page::fromArray($this->readYaml($source), $source);
            $this->assertMatchesFileName((string) $page->id, $name, $source, 'id');
            $pages[] = $page;
        }

        return $pages;
    }

    /**
     * @return list<Content>
     */
    private function loadContents(): array
    {
        $contents = [];

        foreach ($this->store->listDirectories(self::CONTENT_DIR) as $name) {
            $translations = [];

            foreach ($this->store->listFiles(self::CONTENT_DIR . '/' . $name, 'md') as $language) {
                $source = self::CONTENT_DIR . '/' . $name . '/' . $language . '.md';
                [$frontMatter, $body] = FrontMatterCodec::decode($this->store->read($source), $source);
                $translations[] = ContentTranslation::fromDocument($language, $frontMatter, $body, $source);
            }

            $contents[] = new Content($name, $translations);
        }

        return $contents;
    }

    /**
     * @return list<Menu>
     */
    private function loadMenus(): array
    {
        $menus = [];

        foreach ($this->store->listFiles(self::MENU_DIR, 'yaml') as $name) {
            $source = self::MENU_DIR . '/' . $name . '.yaml';
            $menu = Menu::fromArray($this->readYaml($source), $source);
            $this->assertMatchesFileName($menu->name, $name, $source, 'name');
            $menus[] = $menu;
        }

        return $menus;
    }

    private function loadRedirects(): Redirects
    {
        if (!$this->store->exists(self::REDIRECTS_FILE)) {
            return Redirects::none();
        }

        return Redirects::fromArray($this->readYaml(self::REDIRECTS_FILE), self::REDIRECTS_FILE);
    }

    /**
     * @return array<mixed>
     */
    private function readYaml(string $relative): array
    {
        return YamlCodec::decode($this->store->read($relative), $relative);
    }

    /**
     * @param array<mixed> $data
     */
    private function writeYaml(string $relative, array $data): void
    {
        $this->store->write($relative, YamlCodec::encode($data));
    }

    private function assertMatchesFileName(string $value, string $fileName, string $source, string $field): void
    {
        if ($value !== $fileName) {
            throw new InvalidDataException(
                $source,
                sprintf('"%s" does not match the file name "%s"', $value, $fileName),
                $field,
            );
        }
    }
}
