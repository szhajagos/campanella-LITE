<?php

declare(strict_types=1);

namespace Campanella\Lite\Model;

/**
 * A column of a region: a Bootstrap "col-md-{width}".
 *
 * A column can hold default content (shown on every page that uses the
 * layout, unless the page overrides the column) or a menu.
 */
final class Column
{
    public const GRID_COLUMNS = 12;

    /**
     * @param list<string> $content names of content items
     */
    public function __construct(
        public readonly string $name,
        public readonly int $width,
        public readonly array $content = [],
        public readonly ?string $menu = null,
    ) {
    }

    public static function fromFields(Fields $fields): self
    {
        $fields->allowOnly(['name', 'width', 'content', 'menu']);

        return new self(
            name: $fields->string('name'),
            width: $fields->int('width'),
            content: $fields->stringList('content'),
            menu: $fields->optionalString('menu'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = ['name' => $this->name, 'width' => $this->width];

        if ($this->content !== []) {
            $data['content'] = $this->content;
        }

        if ($this->menu !== null) {
            $data['menu'] = $this->menu;
        }

        return $data;
    }
}
