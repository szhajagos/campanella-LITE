<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Handler\Menu;

use Campanella\Lite\Command\CommandFailed;
use Campanella\Lite\Command\Handler\Support\Lookup;
use Campanella\Lite\Model\Menu;
use Campanella\Lite\Model\MenuItem;

/**
 * Edits the item tree of a menu by position: "2" is the second item,
 * "2.1" the first child of the second item.
 */
final class MenuItems
{
    /**
     * Inserts an item into the children of the item at $parent ("" = top level), at a 0-based index.
     *
     * @param list<int> $parent 0-based positions down the tree
     */
    public static function insert(Menu $menu, array $parent, ?string $at, MenuItem $item): Menu
    {
        return $menu->withItems(self::insertInto($menu->items, $parent, $at, $item));
    }

    /**
     * @param list<int> $position
     */
    public static function remove(Menu $menu, array $position): Menu
    {
        return $menu->withItems(self::removeFrom($menu->items, $position));
    }

    /**
     * Parses "2" or "2.1" into 0-based positions, checking that the item exists.
     *
     * @return list<int>
     */
    public static function position(Menu $menu, string $position): array
    {
        $indexes = [];
        $items = $menu->items;

        foreach (explode('.', $position) as $part) {
            $index = preg_match('/^[1-9][0-9]*$/', $part) === 1 ? (int) $part - 1 : -1;

            if (!isset($items[$index])) {
                throw CommandFailed::because('menu.item_not_found', ['menu' => $menu->name, 'item' => $position]);
            }

            $indexes[] = $index;
            $items = $items[$index]->children;
        }

        return $indexes;
    }

    /**
     * Children of the item at a position (the top-level items for an empty position).
     *
     * @param list<int> $position
     *
     * @return list<MenuItem>
     */
    public static function childrenAt(Menu $menu, array $position): array
    {
        $items = $menu->items;

        foreach ($position as $index) {
            $items = $items[$index]->children;
        }

        return $items;
    }

    /**
     * @param list<MenuItem> $items
     * @param list<int>      $parent
     *
     * @return list<MenuItem>
     */
    private static function insertInto(array $items, array $parent, ?string $at, MenuItem $item): array
    {
        if ($parent === []) {
            return Lookup::insert($items, Lookup::position($at, count($items)), [$item]);
        }

        return self::changeChild($items, $parent[0], static fn (MenuItem $child): MenuItem => $child->withChildren(
            self::insertInto($child->children, array_slice($parent, 1), $at, $item),
        ));
    }

    /**
     * @param list<MenuItem> $items
     * @param list<int>      $position
     *
     * @return list<MenuItem>
     */
    private static function removeFrom(array $items, array $position): array
    {
        $first = $position[0] ?? 0;

        if (count($position) <= 1) {
            return array_values(array_filter($items, static fn (int $index): bool => $index !== $first, ARRAY_FILTER_USE_KEY));
        }

        return self::changeChild($items, $first, static fn (MenuItem $child): MenuItem => $child->withChildren(
            self::removeFrom($child->children, array_slice($position, 1)),
        ));
    }

    /**
     * @param list<MenuItem>              $items
     * @param callable(MenuItem): MenuItem $change
     *
     * @return list<MenuItem>
     */
    private static function changeChild(array $items, int $index, callable $change): array
    {
        return array_map(
            static fn (MenuItem $item, int $i): MenuItem => $i === $index ? $change($item) : $item,
            $items,
            array_keys($items),
        );
    }
}
