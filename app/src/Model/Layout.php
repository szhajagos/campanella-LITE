<?php

declare(strict_types=1);

namespace Campanella\Lite\Model;

/**
 * The arrangement of a page: regions stacked top to bottom, each split into columns.
 * Stored in project/layouts/{name}.yaml.
 */
final class Layout
{
    public readonly LocalizedText $title;

    /**
     * @param list<Region> $regions
     */
    public function __construct(
        public readonly string $name,
        public readonly array $regions,
        ?LocalizedText $title = null,
    ) {
        $this->title = $title ?? LocalizedText::empty();
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data, string $source): self
    {
        $fields = new Fields($data, $source);
        $fields->allowOnly(['name', 'title', 'regions']);

        $regions = [];

        foreach ($fields->mappingList('regions') as $index => $region) {
            $regions[] = Region::fromFields($fields->nested($region, 'regions.' . ($index + 1)));
        }

        return new self($fields->string('name'), $regions, $fields->localized('title'));
    }

    public function region(string $name): ?Region
    {
        foreach ($this->regions as $region) {
            if ($region->name === $name) {
                return $region;
            }
        }

        return null;
    }

    /**
     * Finds a column by its "region.column" path, e.g. "body.main".
     */
    public function column(string $path): ?Column
    {
        $parts = explode('.', $path);

        if (count($parts) !== 2) {
            return null;
        }

        return $this->region($parts[0])?->column($parts[1]);
    }

    /**
     * @return list<string> every column as a "region.column" path, top to bottom
     */
    public function columnPaths(): array
    {
        $paths = [];

        foreach ($this->regions as $region) {
            foreach ($region->columns as $column) {
                $paths[] = $region->name . '.' . $column->name;
            }
        }

        return $paths;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = ['name' => $this->name];

        if (!$this->title->isEmpty()) {
            $data['title'] = $this->title->toArray();
        }

        $data['regions'] = array_map(static fn (Region $region): array => $region->toArray(), $this->regions);

        return $data;
    }
}
