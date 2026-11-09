<?php

declare(strict_types=1);

namespace Campanella\Lite\Validation;

use Campanella\Lite\Files\FileReferences;
use Campanella\Lite\Files\FileRepository;
use Campanella\Lite\Files\FileTypes;
use Campanella\Lite\Model\Column;
use Campanella\Lite\Model\Content;
use Campanella\Lite\Model\File;
use Campanella\Lite\Model\Layout;
use Campanella\Lite\Model\LocalizedText;
use Campanella\Lite\Model\Menu;
use Campanella\Lite\Model\MenuItem;
use Campanella\Lite\Model\Page;
use Campanella\Lite\Model\Project;

/**
 * Checks a whole project for problems that make it unpublishable:
 * invalid names, broken references, impossible layouts, URL collisions.
 *
 * It never throws for problems in the data; it returns every violation it
 * finds, so a command batch can report all of them at once.
 */
final class ProjectValidator
{
    /** Lowercase letters and digits, words joined by single hyphens. */
    public const NAME_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
    public const NAME_MAX_LENGTH = 64;

    /** ISO 639 language code, optionally with an ISO 3166 region: "en", "hu", "pt-BR". */
    public const LANGUAGE_PATTERN = '/^[a-z]{2,3}(?:-[A-Z]{2})?$/';

    /** One or more lowercase ASCII segments: "/about", "/services/web-design". */
    public const ALIAS_PATTERN = '#^(?:/[a-z0-9]+(?:-[a-z0-9]+)*)+$#';

    /** Region names that would collide with layout fields in paths like "layout.default.title". */
    public const RESERVED_REGION_NAMES = ['name', 'title'];

    /** Column names that would collide with region fields in paths like "layout.default.body.container". */
    public const RESERVED_COLUMN_NAMES = ['container'];

    /** First path segments owned by the system. Site language codes are reserved as well. */
    public const RESERVED_SEGMENTS = ['admin', 'api', 'assets', 'files', 'mcp', 'node'];

    /** Menu targets: http(s) URLs, mailto: links or site-relative paths. */
    private const URL_PATTERN = '#^(?:https?://[^\s/$.?\#][^\s]*|mailto:[^\s]+|/[^\s]*)$#i';

    /** @var list<Violation> */
    private array $violations = [];

    /**
     * @return list<Violation>
     */
    public function validate(Project $project): array
    {
        $this->violations = [];

        $this->validateSite($project);

        foreach ($project->layouts as $layout) {
            $this->validateLayout($layout, $project);
        }

        $aliases = [];

        foreach ($project->pages as $page) {
            $this->validatePage($page, $project, $aliases);
        }

        foreach ($project->contents as $content) {
            $this->validateContent($content, $project);
        }

        foreach ($project->menus as $menu) {
            $this->validateMenu($menu, $project);
        }

        foreach ($project->files as $file) {
            $this->validateFile($file, $project);
        }

        foreach ($project->redirects->toArray() as $path => $target) {
            if ($project->page($target['page']) === null) {
                $this->add('redirect.unknown_page', 'redirect.' . $path, ['path' => $path, 'page' => $target['page']]);
            }
        }

        return $this->violations;
    }

    public static function isValidName(string $name): bool
    {
        return strlen($name) <= self::NAME_MAX_LENGTH && preg_match(self::NAME_PATTERN, $name) === 1;
    }

    private function validateSite(Project $project): void
    {
        $site = $project->site;

        if ($site->languages === []) {
            $this->add('site.no_languages', 'site.languages');
        }

        $seen = [];

        foreach ($site->languages as $language) {
            if (preg_match(self::LANGUAGE_PATTERN, $language) !== 1) {
                $this->add('site.invalid_language', 'site.languages', ['language' => $language]);
            }

            if (isset($seen[$language])) {
                $this->add('site.duplicate_language', 'site.languages', ['language' => $language]);
            }

            $seen[$language] = true;
        }

        if (!in_array($site->defaultLanguage, $site->languages, true)) {
            $this->add('site.unknown_default_language', 'site.default_language', ['language' => $site->defaultLanguage]);
        }

        if ($project->layout($site->defaultLayout) === null) {
            $this->add('site.unknown_default_layout', 'site.default_layout', ['layout' => $site->defaultLayout]);
        }

        if ($site->frontPage !== null && $project->page($site->frontPage) === null) {
            $this->add('site.unknown_front_page', 'site.front_page', ['page' => $site->frontPage]);
        }

        $highestId = $project->pages === [] ? 0 : max(array_keys($project->pages));

        if ($site->nextPageId <= $highestId) {
            $this->add('site.next_page_id_in_use', 'site.next_page_id', ['next' => $site->nextPageId, 'highest' => $highestId]);
        }
    }

    private function validateLayout(Layout $layout, Project $project): void
    {
        $base = 'layout.' . $layout->name;
        $this->checkName($layout->name, $base);
        $this->checkLanguages($layout->title, $base . '.title', $project);

        $regionNames = [];

        foreach ($layout->regions as $region) {
            $regionPath = $base . '.' . $region->name;
            $this->checkName($region->name, $regionPath);

            if (in_array($region->name, self::RESERVED_REGION_NAMES, true)) {
                $this->add('name.reserved', $regionPath, ['name' => $region->name]);
            }

            if (isset($regionNames[$region->name])) {
                $this->add('layout.duplicate_region', $regionPath, ['region' => $region->name]);
            }

            $regionNames[$region->name] = true;
            $columnNames = [];

            foreach ($region->columns as $column) {
                $columnPath = $regionPath . '.' . $column->name;
                $this->checkName($column->name, $columnPath);

                if (in_array($column->name, self::RESERVED_COLUMN_NAMES, true)) {
                    $this->add('name.reserved', $columnPath, ['name' => $column->name]);
                }

                if (isset($columnNames[$column->name])) {
                    $this->add('layout.duplicate_column', $columnPath, ['column' => $column->name]);
                }

                $columnNames[$column->name] = true;
                $this->validateColumn($column, $columnPath, $project);
            }

            if ($region->totalWidth() > Column::GRID_COLUMNS) {
                $this->add('layout.region_too_wide', $regionPath, ['width' => $region->totalWidth(), 'max' => Column::GRID_COLUMNS]);
            }
        }
    }

    private function validateColumn(Column $column, string $path, Project $project): void
    {
        if ($column->width < 1 || $column->width > Column::GRID_COLUMNS) {
            $this->add('layout.invalid_width', $path . '.width', ['width' => $column->width, 'max' => Column::GRID_COLUMNS]);
        }

        if ($column->content !== [] && $column->menu !== null) {
            $this->add('layout.content_and_menu', $path);
        }

        foreach ($column->content as $name) {
            if ($project->content($name) === null) {
                $this->add('layout.unknown_content', $path . '.content', ['content' => $name]);
            }
        }

        if ($column->menu !== null && $project->menu($column->menu) === null) {
            $this->add('layout.unknown_menu', $path . '.menu', ['menu' => $column->menu]);
        }
    }

    /**
     * @param array<string, array<string, int>> $aliases language => alias => page id, filled as pages are checked
     */
    private function validatePage(Page $page, Project $project, array &$aliases): void
    {
        $base = 'page.' . $page->id;

        if ($page->id < 1) {
            $this->add('page.invalid_id', $base . '.id', ['id' => $page->id]);
        }

        $this->checkLanguages($page->title, $base . '.title', $project);
        $this->checkLanguages($page->description, $base . '.description', $project);
        $this->checkLanguages($page->alias, $base . '.alias', $project);
        $this->checkDefaultLanguage($page->title, $base . '.title', $project);

        foreach ($page->alias->toArray() as $language => $alias) {
            $this->checkAlias($alias, $language, $page, $base . '.alias.' . $language, $project, $aliases);
        }

        $layout = $project->layout($page->layout);

        if ($layout === null) {
            $this->add('page.unknown_layout', $base . '.layout', ['layout' => $page->layout]);
        }

        foreach ($page->placements as $columnPath => $names) {
            $path = $base . '.placements.' . $columnPath;

            if ($layout !== null && $layout->column($columnPath) === null) {
                $this->add('page.unknown_column', $path, ['column' => $columnPath, 'layout' => $layout->name]);
            }

            foreach ($names as $name) {
                if ($project->content($name) === null) {
                    $this->add('page.unknown_content', $path, ['content' => $name]);
                }
            }
        }
    }

    /**
     * @param array<string, array<string, int>> $aliases
     */
    private function checkAlias(string $alias, string $language, Page $page, string $path, Project $project, array &$aliases): void
    {
        if (preg_match(self::ALIAS_PATTERN, $alias) !== 1) {
            $this->add('page.invalid_alias', $path, ['alias' => $alias]);

            return;
        }

        $firstSegment = explode('/', $alias)[1];
        $reserved = [...self::RESERVED_SEGMENTS, ...$project->site->languages];

        if (in_array($firstSegment, $reserved, true)) {
            $this->add('page.reserved_alias', $path, ['alias' => $alias, 'segment' => $firstSegment]);
        }

        $owner = $aliases[$language][$alias] ?? null;

        if ($owner !== null) {
            $this->add('page.duplicate_alias', $path, ['alias' => $alias, 'page' => $owner]);
        } else {
            $aliases[$language][$alias] = $page->id;
        }
    }

    private function validateContent(Content $content, Project $project): void
    {
        $base = 'content.' . $content->name;
        $this->checkName($content->name, $base);

        if ($content->translations === []) {
            $this->add('content.no_translations', $base);
        }

        foreach ($content->translations as $language => $translation) {
            if (!in_array($language, $project->site->languages, true)) {
                $this->add('text.unknown_language', $base . '.' . $language, ['language' => $language]);
            }

            if (trim($translation->title) === '') {
                $this->add('content.missing_title', $base . '.' . $language . '.title');
            }

            foreach (FileReferences::in($translation->body) as $id) {
                if ($project->file($id) === null) {
                    $this->add('content.unknown_file', $base . '.' . $language, ['file' => $id]);
                }
            }
        }
    }

    private function validateFile(File $file, Project $project): void
    {
        $base = 'file.' . $file->id;

        if (!FileTypes::isKnown($file->extension)) {
            $this->add('file.unknown_type', $base . '.extension', ['extension' => $file->extension]);
        } elseif (!File::isValidFilename($file->filename, $file->extension)) {
            $this->add('file.invalid_filename', $base . '.filename', ['filename' => $file->filename, 'extension' => $file->extension, 'max' => File::MAX_FILENAME_LENGTH]);
        }

        if (preg_match(FileRepository::STORED_NAME_PATTERN, $file->storedFilename) !== 1) {
            $this->add('file.invalid_stored_name', $base . '.stored_filename');
        }

        $this->checkLanguages($file->description, $base . '.description', $project);
    }

    private function validateMenu(Menu $menu, Project $project): void
    {
        $base = 'menu.' . $menu->name;
        $this->checkName($menu->name, $base);

        foreach ($menu->items as $index => $item) {
            $this->validateMenuItem($item, $base . '.items.' . ($index + 1), $project);
        }
    }

    private function validateMenuItem(MenuItem $item, string $path, Project $project): void
    {
        $this->checkLanguages($item->label, $path . '.label', $project);
        $this->checkDefaultLanguage($item->label, $path . '.label', $project);

        if (($item->page === null) === ($item->url === null)) {
            $this->add('menu.invalid_target', $path);
        }

        if ($item->page !== null && $project->page($item->page) === null) {
            $this->add('menu.unknown_page', $path . '.page', ['page' => $item->page]);
        }

        if ($item->url !== null && preg_match(self::URL_PATTERN, $item->url) !== 1) {
            $this->add('menu.invalid_url', $path . '.url', ['url' => $item->url]);
        }

        foreach ($item->children as $index => $child) {
            $this->validateMenuItem($child, $path . '.children.' . ($index + 1), $project);
        }
    }

    private function checkName(string $name, string $path): void
    {
        if (!self::isValidName($name)) {
            $this->add('name.invalid', $path, ['name' => $name, 'max' => self::NAME_MAX_LENGTH]);
        }
    }

    private function checkLanguages(LocalizedText $text, string $path, Project $project): void
    {
        foreach ($text->languages() as $language) {
            if (!in_array($language, $project->site->languages, true)) {
                $this->add('text.unknown_language', $path . '.' . $language, ['language' => $language]);
            }
        }
    }

    private function checkDefaultLanguage(LocalizedText $text, string $path, Project $project): void
    {
        $language = $project->site->defaultLanguage;
        $value = $text->get($language);

        if ($value === null || trim($value) === '') {
            $this->add('text.missing_default_language', $path . '.' . $language, ['language' => $language]);
        }
    }

    /**
     * @param array<string, string|int> $params
     */
    private function add(string $code, string $path, array $params = []): void
    {
        $this->violations[] = new Violation($code, $path, $params);
    }
}
