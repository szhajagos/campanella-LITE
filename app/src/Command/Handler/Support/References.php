<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Handler\Support;

use Campanella\Lite\Command\Workspace;
use Campanella\Lite\Model\Column;
use Campanella\Lite\Model\Content;
use Campanella\Lite\Model\MenuItem;
use Campanella\Lite\Model\Page;
use Campanella\Lite\Model\Project;

/**
 * Finds what refers to an entity, and removes those references for a forced delete.
 *
 * References are described in short, untranslated technical terms
 * ("page 2 (body.main)", "menu main item 1.2") that match command paths.
 *
 * The removal methods may put unchanged entities back into the workspace;
 * the workspace only reports entities that really differ.
 */
final class References
{
    /**
     * @return list<string>
     */
    public static function toContent(Project $project, string $name): array
    {
        $found = [];

        foreach ($project->layouts as $layout) {
            foreach ($layout->columnPaths() as $path) {
                if (in_array($name, $layout->column($path)->content ?? [], true)) {
                    $found[] = sprintf('layout %s (%s)', $layout->name, $path);
                }
            }
        }

        foreach ($project->pages as $page) {
            foreach ($page->placements as $path => $names) {
                if (in_array($name, $names, true)) {
                    $found[] = sprintf('page %d (%s)', $page->id, $path);
                }
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    public static function toPage(Project $project, int $id): array
    {
        $found = $project->site->frontPage === $id ? ['site.front_page'] : [];

        foreach ($project->menus as $menu) {
            foreach (self::itemsLinking($menu->items, $id) as $position) {
                $found[] = sprintf('menu %s item %s', $menu->name, $position);
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    public static function toMenu(Project $project, string $name): array
    {
        $found = [];

        foreach ($project->layouts as $layout) {
            foreach ($layout->columnPaths() as $path) {
                if ($layout->column($path)?->menu === $name) {
                    $found[] = sprintf('layout %s (%s)', $layout->name, $path);
                }
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    public static function toLayout(Project $project, string $name): array
    {
        $found = $project->site->defaultLayout === $name ? ['site.default_layout'] : [];

        foreach ($project->pages as $page) {
            if ($page->layout === $name) {
                $found[] = 'page ' . $page->id;
            }
        }

        return $found;
    }

    /**
     * Pages that place content into any of the given columns of a layout.
     *
     * @param list<string> $columns "region.column" paths
     *
     * @return list<string>
     */
    public static function toColumns(Project $project, string $layout, array $columns): array
    {
        $found = [];

        foreach ($project->pages as $page) {
            if ($page->layout !== $layout) {
                continue;
            }

            foreach (array_keys($page->placements) as $path) {
                if (in_array($path, $columns, true)) {
                    $found[] = sprintf('page %d (%s)', $page->id, $path);
                }
            }
        }

        return $found;
    }

    /**
     * Texts and content versions in a language.
     *
     * @return list<string>
     */
    public static function toLanguage(Project $project, string $language): array
    {
        $found = [];

        foreach ($project->layouts as $layout) {
            if ($layout->title->has($language)) {
                $found[] = 'layout ' . $layout->name;
            }
        }

        foreach ($project->pages as $page) {
            if ($page->title->has($language) || $page->alias->has($language) || $page->description->has($language)) {
                $found[] = 'page ' . $page->id;
            }
        }

        foreach ($project->contents as $content) {
            if ($content->translation($language) !== null) {
                $found[] = 'content ' . $content->name;
            }
        }

        foreach ($project->menus as $menu) {
            if (self::itemsWithLanguage($menu->items, $language)) {
                $found[] = 'menu ' . $menu->name;
            }
        }

        return $found;
    }

    public static function removeContent(Workspace $workspace, string $name): void
    {
        $without = static fn (array $names): array => array_values(array_filter($names, static fn (string $n): bool => $n !== $name));

        foreach ($workspace->project()->layouts as $layout) {
            $changed = $layout;

            foreach ($layout->columnPaths() as $path) {
                $changed = $changed->withColumn($path, static fn (Column $column): Column => $column->withContent($without($column->content)));
            }

            if ($changed->toArray() !== $layout->toArray()) {
                $workspace->putLayout($changed);
            }
        }

        foreach ($workspace->project()->pages as $page) {
            $placements = array_map($without, $page->placements);

            if ($placements !== $page->placements) {
                $workspace->putPage($page->with(placements: $placements));
            }
        }
    }

    public static function removePage(Workspace $workspace, int $id): void
    {
        $site = $workspace->project()->site;

        if ($site->frontPage === $id) {
            $workspace->putSite($site->withFrontPage(null));
        }

        foreach ($workspace->project()->menus as $menu) {
            $workspace->putMenu($menu->withItems(self::withoutItemsLinking($menu->items, $id)));
        }
    }

    public static function removeMenu(Workspace $workspace, string $name): void
    {
        foreach ($workspace->project()->layouts as $layout) {
            $changed = $layout;

            foreach ($layout->columnPaths() as $path) {
                if ($layout->column($path)?->menu === $name) {
                    $changed = $changed->withColumn($path, static fn (Column $column): Column => $column->withMenu(null));
                }
            }

            if ($changed !== $layout) {
                $workspace->putLayout($changed);
            }
        }
    }

    /**
     * Removes page placements into columns of a layout.
     *
     * @param list<string> $columns
     */
    public static function removePlacements(Workspace $workspace, string $layout, array $columns): void
    {
        foreach ($workspace->project()->pages as $page) {
            if ($page->layout !== $layout) {
                continue;
            }

            $placements = array_diff_key($page->placements, array_flip($columns));

            if ($placements !== $page->placements) {
                $workspace->putPage($page->with(placements: $placements));
            }
        }
    }

    /**
     * Removes every text and content version in a language.
     */
    public static function removeLanguage(Workspace $workspace, string $language): void
    {
        $project = $workspace->project();

        foreach ($project->layouts as $layout) {
            if ($layout->title->has($language)) {
                $workspace->putLayout($layout->withTitle($layout->title->without($language)));
            }
        }

        foreach ($project->pages as $page) {
            $workspace->putPage(self::pageWithoutLanguage($page, $language));
        }

        foreach ($project->contents as $content) {
            if ($content->translation($language) !== null) {
                self::putContentWithout($workspace, $content, $language);
            }
        }

        foreach ($project->menus as $menu) {
            $workspace->putMenu($menu->withItems(self::itemsWithoutLanguage($menu->items, $language)));
        }
    }

    private static function pageWithoutLanguage(Page $page, string $language): Page
    {
        return $page->with(
            title: $page->title->without($language),
            alias: $page->alias->without($language),
            description: $page->description->without($language),
        );
    }

    private static function putContentWithout(Workspace $workspace, Content $content, string $language): void
    {
        $remaining = $content->withoutTranslation($language);

        if ($remaining->translations === []) {
            $workspace->removeContent($content->name);
        } else {
            $workspace->putContent($remaining);
        }
    }

    /**
     * @param list<MenuItem> $items
     *
     * @return list<string> 1-based positions such as "2" or "2.1"
     */
    private static function itemsLinking(array $items, int $id, string $prefix = ''): array
    {
        $found = [];

        foreach ($items as $index => $item) {
            $position = $prefix . ($index + 1);

            if ($item->page === $id) {
                $found[] = $position;
            }

            array_push($found, ...self::itemsLinking($item->children, $id, $position . '.'));
        }

        return $found;
    }

    /**
     * @param list<MenuItem> $items
     *
     * @return list<MenuItem>
     */
    private static function withoutItemsLinking(array $items, int $id): array
    {
        $kept = [];

        foreach ($items as $item) {
            if ($item->page !== $id) {
                $kept[] = $item->withChildren(self::withoutItemsLinking($item->children, $id));
            }
        }

        return $kept;
    }

    /**
     * @param list<MenuItem> $items
     */
    private static function itemsWithLanguage(array $items, string $language): bool
    {
        foreach ($items as $item) {
            if ($item->label->has($language) || self::itemsWithLanguage($item->children, $language)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<MenuItem> $items
     *
     * @return list<MenuItem>
     */
    private static function itemsWithoutLanguage(array $items, string $language): array
    {
        return array_map(
            static fn (MenuItem $item): MenuItem => $item
                ->withLabel($item->label->without($language))
                ->withChildren(self::itemsWithoutLanguage($item->children, $language)),
            $items,
        );
    }
}
