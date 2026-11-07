<?php

declare(strict_types=1);

namespace Campanella\Lite\Model;

/**
 * A page of the website. Stored in project/pages/{id}.yaml.
 *
 * The id is assigned by the system and never reused. Without an alias the
 * page is available at /node/{id}; an alias gives it a readable address.
 */
final class Page
{
    public readonly LocalizedText $alias;
    public readonly LocalizedText $description;

    /**
     * @param array<string, list<string>> $placements "region.column" => content names, overriding the layout defaults
     */
    public function __construct(
        public readonly int $id,
        public readonly LocalizedText $title,
        public readonly string $layout,
        public readonly PageStatus $status = PageStatus::Draft,
        ?LocalizedText $alias = null,
        ?LocalizedText $description = null,
        public readonly array $placements = [],
    ) {
        $this->alias = $alias ?? LocalizedText::empty();
        $this->description = $description ?? LocalizedText::empty();
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data, string $source): self
    {
        $fields = new Fields($data, $source);
        $fields->allowOnly(['id', 'alias', 'title', 'description', 'layout', 'status', 'placements']);

        $status = PageStatus::Draft;

        if ($fields->has('status')) {
            $status = PageStatus::tryFrom($fields->string('status'))
                ?? $fields->fail('status', 'expected "draft" or "published"');
        }

        $placements = [];
        $placementFields = $fields->nested($fields->mapping('placements'), 'placements');

        foreach (array_keys($fields->mapping('placements')) as $column) {
            $placements[$column] = $placementFields->stringList($column);
        }

        return new self(
            id: $fields->int('id'),
            title: $fields->localized('title'),
            layout: $fields->string('layout'),
            status: $status,
            alias: $fields->localized('alias'),
            description: $fields->localized('description'),
            placements: $placements,
        );
    }

    /**
     * Returns a copy with the given fields replaced.
     *
     * @param array<string, list<string>>|null $placements
     */
    public function with(
        ?LocalizedText $title = null,
        ?string $layout = null,
        ?PageStatus $status = null,
        ?LocalizedText $alias = null,
        ?LocalizedText $description = null,
        ?array $placements = null,
    ): self {
        return new self(
            $this->id,
            $title ?? $this->title,
            $layout ?? $this->layout,
            $status ?? $this->status,
            $alias ?? $this->alias,
            $description ?? $this->description,
            $placements ?? $this->placements,
        );
    }

    /**
     * The address of the page in a language: its alias, or /node/{id} without one.
     */
    public function path(string $language): string
    {
        return $this->alias->get($language) ?? $this->systemPath();
    }

    public function systemPath(): string
    {
        return '/node/' . $this->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = ['id' => $this->id];

        if (!$this->alias->isEmpty()) {
            $data['alias'] = $this->alias->toArray();
        }

        $data['title'] = $this->title->toArray();

        if (!$this->description->isEmpty()) {
            $data['description'] = $this->description->toArray();
        }

        $data['layout'] = $this->layout;
        $data['status'] = $this->status->value;

        if ($this->placements !== []) {
            $data['placements'] = $this->placements;
        }

        return $data;
    }
}
