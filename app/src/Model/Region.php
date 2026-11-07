<?php

declare(strict_types=1);

namespace Campanella\Lite\Model;

use Campanella\Lite\Exception\InvalidDataException;

/**
 * A horizontal band of a layout: a Bootstrap "row" inside a container.
 */
final class Region
{
    /**
     * @param list<Column> $columns
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly ContainerType $container = ContainerType::Fixed,
    ) {
    }

    public static function fromFields(Fields $fields): self
    {
        $fields->allowOnly(['name', 'container', 'columns']);

        $columns = [];

        foreach ($fields->mappingList('columns') as $index => $column) {
            $columns[] = Column::fromFields($fields->nested($column, 'columns.' . ($index + 1)));
        }

        $container = ContainerType::Fixed;

        if ($fields->has('container')) {
            $value = $fields->string('container');
            $container = ContainerType::tryFrom($value) ?? $fields->fail('container', 'expected "fixed" or "fluid"');
        }

        return new self($fields->string('name'), $columns, $container);
    }

    /**
     * @param list<Column> $columns
     */
    public function withColumns(array $columns): self
    {
        return new self($this->name, $columns, $this->container);
    }

    public function column(string $name): ?Column
    {
        foreach ($this->columns as $column) {
            if ($column->name === $name) {
                return $column;
            }
        }

        return null;
    }

    public function totalWidth(): int
    {
        return array_sum(array_map(static fn (Column $column): int => $column->width, $this->columns));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = ['name' => $this->name];

        if ($this->container !== ContainerType::Fixed) {
            $data['container'] = $this->container->value;
        }

        $data['columns'] = array_map(static fn (Column $column): array => $column->toArray(), $this->columns);

        return $data;
    }
}
