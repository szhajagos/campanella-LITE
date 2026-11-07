<?php

declare(strict_types=1);

namespace Campanella\Lite\Model;

/**
 * A menu entry that points either to a page (by id) or to a URL.
 */
final class MenuItem
{
    /**
     * @param list<MenuItem> $children
     */
    public function __construct(
        public readonly LocalizedText $label,
        public readonly ?int $page = null,
        public readonly ?string $url = null,
        public readonly array $children = [],
    ) {
    }

    /**
     * @param list<MenuItem> $children
     */
    public function withChildren(array $children): self
    {
        return new self($this->label, $this->page, $this->url, $children);
    }

    public function withLabel(LocalizedText $label): self
    {
        return new self($label, $this->page, $this->url, $this->children);
    }

    public static function fromFields(Fields $fields): self
    {
        $fields->allowOnly(['label', 'page', 'url', 'children']);

        $children = [];

        foreach ($fields->mappingList('children') as $index => $child) {
            $children[] = self::fromFields($fields->nested($child, 'children.' . ($index + 1)));
        }

        return new self(
            label: $fields->localized('label'),
            page: $fields->optionalInt('page'),
            url: $fields->optionalString('url'),
            children: $children,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = ['label' => $this->label->toArray()];

        if ($this->page !== null) {
            $data['page'] = $this->page;
        }

        if ($this->url !== null) {
            $data['url'] = $this->url;
        }

        if ($this->children !== []) {
            $data['children'] = array_map(static fn (MenuItem $child): array => $child->toArray(), $this->children);
        }

        return $data;
    }
}
