<?php

declare(strict_types=1);

namespace Campanella\Lite\Model;

/**
 * A navigation menu. Stored in project/menus/{name}.yaml.
 */
final class Menu
{
    /**
     * @param list<MenuItem> $items
     */
    public function __construct(
        public readonly string $name,
        public readonly array $items = [],
    ) {
    }

    /**
     * @param list<MenuItem> $items
     */
    public function withItems(array $items): self
    {
        return new self($this->name, $items);
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data, string $source): self
    {
        $fields = new Fields($data, $source);
        $fields->allowOnly(['name', 'items']);

        $items = [];

        foreach ($fields->mappingList('items') as $index => $item) {
            $items[] = MenuItem::fromFields($fields->nested($item, 'items.' . ($index + 1)));
        }

        return new self($fields->string('name'), $items);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'items' => array_map(static fn (MenuItem $item): array => $item->toArray(), $this->items),
        ];
    }
}
