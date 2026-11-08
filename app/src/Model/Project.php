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

    public readonly Redirects $redirects;

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
        ?Redirects $redirects = null,
    ) {
        $this->redirects = $redirects ?? Redirects::none();
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

    public function withSite(Site $site): self
    {
        return $this->rebuild(site: $site);
    }

    public function withLayout(Layout $layout): self
    {
        $layouts = $this->layouts;
        $layouts[$layout->name] = $layout;

        return $this->rebuild(layouts: $layouts);
    }

    public function withoutLayout(string $name): self
    {
        $layouts = $this->layouts;
        unset($layouts[$name]);

        return $this->rebuild(layouts: $layouts);
    }

    public function withPage(Page $page): self
    {
        $pages = $this->pages;
        $pages[$page->id] = $page;

        return $this->rebuild(pages: $pages);
    }

    public function withoutPage(int $id): self
    {
        $pages = $this->pages;
        unset($pages[$id]);

        return $this->rebuild(pages: $pages);
    }

    public function withContent(Content $content): self
    {
        $contents = $this->contents;
        $contents[$content->name] = $content;

        return $this->rebuild(contents: $contents);
    }

    public function withoutContent(string $name): self
    {
        $contents = $this->contents;
        unset($contents[$name]);

        return $this->rebuild(contents: $contents);
    }

    public function withMenu(Menu $menu): self
    {
        $menus = $this->menus;
        $menus[$menu->name] = $menu;

        return $this->rebuild(menus: $menus);
    }

    public function withoutMenu(string $name): self
    {
        $menus = $this->menus;
        unset($menus[$name]);

        return $this->rebuild(menus: $menus);
    }

    public function withRedirects(Redirects $redirects): self
    {
        return $this->rebuild(redirects: $redirects);
    }

    /**
     * Finds a page by its id ("2") or by an alias in any language ("/about").
     */
    public function findPage(string $reference): ?Page
    {
        if (preg_match('/^[1-9][0-9]*$/', $reference) === 1) {
            return $this->page((int) $reference);
        }

        foreach ($this->pages as $page) {
            if (in_array($reference, $page->alias->toArray(), true)) {
                return $page;
            }
        }

        return null;
    }

    /**
     * @param array<string, Layout>|null  $layouts
     * @param array<int, Page>|null       $pages
     * @param array<string, Content>|null $contents
     * @param array<string, Menu>|null    $menus
     */
    private function rebuild(
        ?Site $site = null,
        ?array $layouts = null,
        ?array $pages = null,
        ?array $contents = null,
        ?array $menus = null,
        ?Redirects $redirects = null,
    ): self {
        return new self(
            $site ?? $this->site,
            array_values($layouts ?? $this->layouts),
            array_values($pages ?? $this->pages),
            array_values($contents ?? $this->contents),
            array_values($menus ?? $this->menus),
            $redirects ?? $this->redirects,
        );
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
