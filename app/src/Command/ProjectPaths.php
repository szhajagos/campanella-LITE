<?php

declare(strict_types=1);

namespace Campanella\Lite\Command;

use Campanella\Lite\Exception\InvalidDataException;
use Campanella\Lite\Model\Content;
use Campanella\Lite\Model\ContentTranslation;
use Campanella\Lite\Model\File;
use Campanella\Lite\Model\Layout;
use Campanella\Lite\Model\Menu;
use Campanella\Lite\Model\MenuItem;
use Campanella\Lite\Model\Page;
use Campanella\Lite\Model\Project;
use Campanella\Lite\Model\Site;

/**
 * Reads and writes values of the project by dotted path:
 *
 *     site.name
 *     page.2.title            page./about.title.hu
 *     layout.default.title    layout.default.body.container    layout.default.body.main.width
 *     content.intro.en.title
 *     menu.main.items.2.label menu.main.items.2.children.1.url
 *     file.12.filename        file.12.description.hu
 *
 * A language-dependent field without a language code means the default
 * language. List positions (menu items) count from 1.
 *
 * Reading works for any path. Writing is limited to a fixed set of fields;
 * structural changes (adding a region, placing content) have their own commands.
 */
final class ProjectPaths
{
    public const ROOTS = ['site', 'page', 'layout', 'content', 'menu', 'file'];

    private const LOCALIZED = ['title', 'description', 'alias', 'label'];

    public function get(Project $project, string $path): mixed
    {
        $segments = $this->split($path);
        $root = $segments[0];

        [$value, $rest] = match ($root) {
            'site' => [$project->site->toArray() + ['front_page' => null], array_slice($segments, 1)],
            'page' => [self::pageView($this->page($project, $segments, $path)), array_slice($segments, 2)],
            'layout' => [self::layoutView($this->layout($project, $segments, $path)), array_slice($segments, 2)],
            'content' => [$this->content($project, $segments, $path)->toArray(), array_slice($segments, 2)],
            'menu' => [self::menuView($this->menu($project, $segments, $path)), array_slice($segments, 2)],
            'file' => [self::fileView($this->file($project, $segments, $path)), array_slice($segments, 2)],
            default => throw $this->unknownRoot($root),
        };

        foreach ($rest as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                throw CommandFailed::because('path.unknown', ['path' => $path]);
            }

            $value = $value[$segment];
        }

        $last = $rest === [] ? null : $rest[array_key_last($rest)];

        if ($root !== 'content' && in_array($last, self::LOCALIZED, true) && is_array($value)) {
            return $value[$project->site->defaultLanguage] ?? null;
        }

        return $value;
    }

    /**
     * Sets a value and returns the path it was written to, with the language filled in.
     */
    public function set(Workspace $workspace, string $path, string $value): string
    {
        $segments = $this->split($path);
        $project = $workspace->project();

        try {
            return match ($segments[0]) {
                'site' => $this->setSite($workspace, $segments, $value, $path),
                'page' => $this->setPage($workspace, $this->page($project, $segments, $path), array_slice($segments, 2), $value, $path),
                'layout' => $this->setLayout($workspace, $this->layout($project, $segments, $path), array_slice($segments, 2), $value, $path),
                'content' => $this->setContent($workspace, $this->content($project, $segments, $path), array_slice($segments, 2), $value, $path),
                'menu' => $this->setMenu($workspace, $this->menu($project, $segments, $path), array_slice($segments, 2), $value, $path),
                'file' => $this->setFile($workspace, $this->file($project, $segments, $path), array_slice($segments, 2), $value, $path),
                default => throw $this->unknownRoot($segments[0]),
            };
        } catch (InvalidDataException $exception) {
            throw CommandFailed::because('value.invalid', ['path' => $path, 'detail' => $exception->detail]);
        }
    }

    /**
     * The page with every field present, optional ones empty.
     *
     * @return array<string, mixed>
     */
    public static function pageView(Page $page): array
    {
        return array_replace(
            ['id' => null, 'alias' => [], 'title' => [], 'description' => [], 'layout' => null, 'status' => null, 'placements' => []],
            $page->toArray(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function layoutView(Layout $layout): array
    {
        $view = ['name' => $layout->name, 'title' => $layout->title->toArray()];

        foreach ($layout->regions as $region) {
            $regionView = ['container' => $region->container->value];

            foreach ($region->columns as $column) {
                $columnView = $column->toArray();
                unset($columnView['name']);
                $columnView += ['content' => [], 'menu' => null];
                $regionView[$column->name] = $columnView;
            }

            $view[$region->name] = $regionView;
        }

        return $view;
    }

    /**
     * The manifest of a file with every field present, optional ones empty.
     *
     * @return array<string, mixed>
     */
    public static function fileView(File $file): array
    {
        return array_replace(
            ['id' => null, 'filename' => null, 'stored_filename' => null, 'mime_type' => null, 'extension' => null, 'size' => null,
                'width' => null, 'height' => null, 'description' => [], 'uploaded_at' => null, 'uploaded_by' => null, 'archived_at' => null],
            $file->toArray(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function menuView(Menu $menu): array
    {
        return ['name' => $menu->name, 'items' => self::itemsView($menu->items)];
    }

    /**
     * @param list<MenuItem> $items
     *
     * @return array<int, array<string, mixed>> keyed from 1
     */
    private static function itemsView(array $items): array
    {
        $view = [];

        foreach ($items as $index => $item) {
            $itemView = $item->toArray();

            if ($item->children !== []) {
                $itemView['children'] = self::itemsView($item->children);
            }

            $view[$index + 1] = $itemView;
        }

        return $view;
    }

    /**
     * @param list<string> $segments
     */
    private function setSite(Workspace $workspace, array $segments, string $value, string $path): string
    {
        $field = $segments[1] ?? '';
        $data = $workspace->project()->site->toArray();

        if (count($segments) !== 2) {
            throw $this->notSettable($path);
        }

        match ($field) {
            'name', 'base_url', 'theme', 'default_language', 'default_layout' => $data[$field] = $value,
            'front_page' => $data = $this->setOptionalInt($data, 'front_page', $value),
            default => throw $this->notSettable($path),
        };

        $workspace->putSite(Site::fromArray($data, $path));

        return $path;
    }

    /**
     * @param list<string> $rest
     */
    private function setPage(Workspace $workspace, Page $page, array $rest, string $value, string $path): string
    {
        $data = $page->toArray();
        $field = $rest[0] ?? '';
        $base = 'page.' . $page->id . '.' . $field;

        if (in_array($field, ['title', 'description', 'alias'], true) && count($rest) <= 2) {
            $language = $rest[1] ?? $workspace->defaultLanguage();
            $data[$field] = $this->setLocalized($data[$field] ?? [], $language, $value);
            $written = $base . '.' . $language;
        } elseif ($field === 'layout' && count($rest) === 1) {
            $data['layout'] = $value;
            $written = $base;
        } elseif ($field === 'status' && count($rest) === 1) {
            $data['status'] = $this->choice($value, ['draft', 'published']);
            $written = $base;
        } else {
            throw $this->notSettable($path);
        }

        $workspace->putPage(Page::fromArray($data, $path));

        return $written;
    }

    /**
     * @param list<string> $rest
     */
    private function setLayout(Workspace $workspace, Layout $layout, array $rest, string $value, string $path): string
    {
        $data = $layout->toArray();
        $base = 'layout.' . $layout->name;

        if (($rest[0] ?? '') === 'title' && count($rest) <= 2) {
            $language = $rest[1] ?? $workspace->defaultLanguage();
            $data['title'] = $this->setLocalized($data['title'] ?? [], $language, $value);
            $workspace->putLayout(Layout::fromArray($data, $path));

            return $base . '.title.' . $language;
        }

        $regionIndex = $this->regionIndex($layout, $rest[0] ?? '');
        /** @var list<array<string, mixed>> $regions */
        $regions = $data['regions'];

        if (count($rest) === 2 && $rest[1] === 'container') {
            $regions[$regionIndex]['container'] = $this->choice($value, ['fixed', 'fluid']);
        } elseif (count($rest) === 3 && in_array($rest[2], ['width', 'menu'], true)) {
            $columnIndex = $this->columnIndex($layout, $rest[0], $rest[1]);
            /** @var list<array<string, mixed>> $columns */
            $columns = $regions[$regionIndex]['columns'];

            if ($rest[2] === 'width') {
                $columns[$columnIndex]['width'] = $this->integer($value);
            } elseif ($value === '') {
                unset($columns[$columnIndex]['menu']);
            } else {
                $columns[$columnIndex]['menu'] = $value;
            }

            $regions[$regionIndex]['columns'] = $columns;
        } else {
            throw $this->notSettable($path);
        }

        $data['regions'] = $regions;
        $workspace->putLayout(Layout::fromArray($data, $path));

        return $base . '.' . implode('.', $rest);
    }

    /**
     * @param list<string> $rest
     */
    private function setContent(Workspace $workspace, Content $content, array $rest, string $value, string $path): string
    {
        if (count($rest) !== 2 || $rest[1] !== 'title') {
            throw $this->notSettable($path);
        }

        $translation = $content->translation($rest[0])
            ?? throw CommandFailed::because('content.translation_not_found', ['content' => $content->name, 'language' => $rest[0]]);

        $translations = array_values($content->translations);

        foreach ($translations as $index => $existing) {
            if ($existing->language === $translation->language) {
                $translations[$index] = new ContentTranslation($translation->language, $value, $translation->body, $translation->format);
            }
        }

        $workspace->putContent(new Content($content->name, $translations));

        return 'content.' . $content->name . '.' . $rest[0] . '.title';
    }

    /**
     * @param list<string> $rest
     */
    private function setMenu(Workspace $workspace, Menu $menu, array $rest, string $value, string $path): string
    {
        if (($rest[0] ?? '') !== 'items' || count($rest) < 3) {
            throw $this->notSettable($path);
        }

        $data = $menu->toArray();
        /** @var list<array<string, mixed>> $items */
        $items = $data['items'];
        [$items, $written] = $this->setMenuItem($items, array_slice($rest, 1), $value, $path, $menu->name, $workspace->defaultLanguage());
        $data['items'] = $items;

        $workspace->putMenu(Menu::fromArray($data, $path));

        return 'menu.' . $menu->name . '.items.' . $written;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param list<string>               $rest  starts with a position: ["2", "label"] or ["2", "children", "1", "url"]
     *
     * @return array{0: list<array<string, mixed>>, 1: string} the changed items and the written path below "items"
     */
    private function setMenuItem(array $items, array $rest, string $value, string $path, string $menu, string $language): array
    {
        $position = $rest[0];
        $index = preg_match('/^[1-9][0-9]*$/', $position) === 1 ? (int) $position - 1 : -1;

        if (!isset($items[$index])) {
            throw CommandFailed::because('menu.item_not_found', ['menu' => $menu, 'item' => $position]);
        }

        $item = $items[$index];
        $field = $rest[1] ?? '';

        if ($field === 'children' && count($rest) >= 4) {
            /** @var list<array<string, mixed>> $children */
            $children = $item['children'] ?? [];
            [$item['children'], $written] = $this->setMenuItem($children, array_slice($rest, 2), $value, $path, $menu, $language);
            $items[$index] = $item;

            return [$items, $position . '.children.' . $written];
        }

        if ($field === 'label' && count($rest) <= 3) {
            $language = $rest[2] ?? $language;
            /** @var array<string, string> $label */
            $label = $item['label'] ?? [];
            $item['label'] = $this->setLocalized($label, $language, $value);
            $written = $position . '.label.' . $language;
        } elseif ($field === 'url' && count($rest) === 2) {
            $item = $this->setOptional($item, 'url', $value);
            $written = $position . '.url';
        } elseif ($field === 'page' && count($rest) === 2) {
            $item = $this->setOptionalInt($item, 'page', $value);
            $written = $position . '.page';
        } else {
            throw $this->notSettable($path);
        }

        $items[$index] = $item;

        return [$items, $written];
    }

    /**
     * @param list<string> $rest
     */
    private function setFile(Workspace $workspace, File $file, array $rest, string $value, string $path): string
    {
        $data = $file->toArray();
        $field = $rest[0] ?? '';
        $base = 'file.' . $file->id . '.' . $field;

        if ($field === 'description' && count($rest) <= 2) {
            $language = $rest[1] ?? $workspace->defaultLanguage();
            $data['description'] = $this->setLocalized($data['description'] ?? [], $language, $value);
            $written = $base . '.' . $language;
        } elseif ($field === 'filename' && count($rest) === 1) {
            $data['filename'] = $value;
            $written = $base;
        } else {
            throw $this->notSettable($path);
        }

        $workspace->putFile(File::fromArray($data, $path));

        return $written;
    }

    /**
     * @param list<string> $segments
     */
    private function file(Project $project, array $segments, string $path): File
    {
        $reference = $segments[1] ?? throw CommandFailed::because('path.incomplete', ['path' => $path]);
        $id = preg_match('/^[1-9][0-9]{0,9}$/', $reference) === 1 ? (int) $reference : 0;

        return $project->file($id) ?? throw CommandFailed::because('file.not_found', ['file' => $reference]);
    }

    /**
     * @param list<string> $segments
     */
    private function page(Project $project, array $segments, string $path): Page
    {
        $reference = $segments[1] ?? throw CommandFailed::because('path.incomplete', ['path' => $path]);

        return $project->findPage($reference) ?? throw CommandFailed::because('page.not_found', ['page' => $reference]);
    }

    /**
     * @param list<string> $segments
     */
    private function layout(Project $project, array $segments, string $path): Layout
    {
        $name = $segments[1] ?? throw CommandFailed::because('path.incomplete', ['path' => $path]);

        return $project->layout($name) ?? throw CommandFailed::because('layout.not_found', ['layout' => $name]);
    }

    /**
     * @param list<string> $segments
     */
    private function content(Project $project, array $segments, string $path): Content
    {
        $name = $segments[1] ?? throw CommandFailed::because('path.incomplete', ['path' => $path]);

        return $project->content($name) ?? throw CommandFailed::because('content.not_found', ['content' => $name]);
    }

    /**
     * @param list<string> $segments
     */
    private function menu(Project $project, array $segments, string $path): Menu
    {
        $name = $segments[1] ?? throw CommandFailed::because('path.incomplete', ['path' => $path]);

        return $project->menu($name) ?? throw CommandFailed::because('menu.not_found', ['menu' => $name]);
    }

    private function regionIndex(Layout $layout, string $region): int
    {
        foreach ($layout->regions as $index => $candidate) {
            if ($candidate->name === $region) {
                return $index;
            }
        }

        throw CommandFailed::because('layout.region_not_found', ['layout' => $layout->name, 'region' => $region]);
    }

    private function columnIndex(Layout $layout, string $region, string $column): int
    {
        foreach ($layout->region($region)->columns ?? [] as $index => $candidate) {
            if ($candidate->name === $column) {
                return $index;
            }
        }

        throw CommandFailed::because('layout.column_not_found', ['layout' => $layout->name, 'column' => $region . '.' . $column]);
    }

    /**
     * @param array<string, string>|mixed $values
     *
     * @return array<string, string>
     */
    private function setLocalized(mixed $values, string $language, string $value): array
    {
        /** @var array<string, string> $values */
        $values = is_array($values) ? $values : [];

        if ($value === '') {
            unset($values[$language]);
        } else {
            $values[$language] = $value;
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function setOptional(array $data, string $key, string $value): array
    {
        if ($value === '') {
            unset($data[$key]);
        } else {
            $data[$key] = $value;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function setOptionalInt(array $data, string $key, string $value): array
    {
        if ($value === '') {
            unset($data[$key]);
        } else {
            $data[$key] = $this->integer($value);
        }

        return $data;
    }

    private function integer(string $value): int
    {
        if (preg_match('/^-?[0-9]{1,9}$/', $value) !== 1) {
            throw CommandFailed::because('value.not_a_number', ['value' => $value]);
        }

        return (int) $value;
    }

    /**
     * @param list<string> $choices
     */
    private function choice(string $value, array $choices): string
    {
        if (!in_array($value, $choices, true)) {
            throw CommandFailed::because('value.invalid_choice', ['value' => $value, 'choices' => implode(', ', $choices)]);
        }

        return $value;
    }

    /**
     * @return non-empty-list<string>
     */
    private function split(string $path): array
    {
        $segments = explode('.', $path);

        if (in_array('', $segments, true)) {
            throw CommandFailed::because('path.invalid', ['path' => $path]);
        }

        return $segments;
    }

    private function unknownRoot(string $root): CommandFailed
    {
        return CommandFailed::because('path.unknown_root', ['root' => $root, 'roots' => implode(', ', self::ROOTS)]);
    }

    private function notSettable(string $path): CommandFailed
    {
        return CommandFailed::because('path.not_settable', ['path' => $path]);
    }
}
