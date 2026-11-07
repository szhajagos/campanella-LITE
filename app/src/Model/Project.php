<?php

declare(strict_types=1);

namespace Campanella\Lite\Model;

/**
 * The whole website description as loaded from the project directory.
 *
 * A Project is an immutable snapshot. Commands (milestone 2) will produce a
 * changed copy, validate it, and only then write the changed files.
 */
final class Project
{
    /** @var array<string, Layout> */
    public readonly array $layouts;

    /** @var array<int, Page> */
    public readonly array $pages;

    /** @var array<string, Content> */
    public readonly array $contents;

    /** @var array<string, Menu> */
    public readonly array $menus;

    /**
     * @param list<Layout>  $layouts
     * @param list<Page>    $pages
     * @param list<Content> $contents
     * @param list<Menu>    $menus
     */
    public function __construct(
        public readonly Site $site,
        array $layouts = [],
        array $pages = [],
        array $contents = [],
        array $menus = [],
    ) {
        $this->layouts = self::index($layouts, static fn (Layout $layout): string => $layout->name);
        $this->contents = self::index($contents, static fn (Content $content): string => $content->name);
        $this->menus = self::index($menus, static fn (Menu $menu): string => $menu->name);

        $byId = [];

        foreach ($pages as $page) {
            $byId[$page->id] = $page;
        }

        ksort($byId);
        $this->pages = $byId;
    }

    public function layout(string $name): ?Layout
    {
        return $this->layouts[$name] ?? null;
    }

    public function page(int $id): ?Page
    {
        return $this->pages[$id] ?? null;
    }

    public function content(string $name): ?Content
    {
        return $this->contents[$name] ?? null;
    }

    public function menu(string $name): ?Menu
    {
        return $this->menus[$name] ?? null;
    }

    /**
     * @template T of object
     *
     * @param list<T>                $items
     * @param callable(T): string    $key
     *
     * @return array<string, T>
     */
    private static function index(array $items, callable $key): array
    {
        $indexed = [];

        foreach ($items as $item) {
            $indexed[$key($item)] = $item;
        }

        ksort($indexed);

        return $indexed;
    }
}
