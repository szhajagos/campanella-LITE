<?php

declare(strict_types=1);

namespace Campanella\Lite\Model;

/**
 * Website-wide settings, stored in project/site.yaml.
 */
final class Site
{
    public const DEFAULT_THEME = 'default';

    /**
     * @param list<string> $languages language codes, the default language included
     * @param int|null     $frontPage id of the page served at "/"
     */
    public function __construct(
        public readonly string $name,
        public readonly string $baseUrl,
        public readonly array $languages,
        public readonly string $defaultLanguage,
        public readonly string $defaultLayout,
        public readonly string $theme = self::DEFAULT_THEME,
        public readonly int $nextPageId = 1,
        public readonly ?int $frontPage = null,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data, string $source): self
    {
        $fields = new Fields($data, $source);
        $fields->allowOnly(['name', 'base_url', 'languages', 'default_language', 'default_layout', 'theme', 'next_page_id', 'front_page']);

        return new self(
            name: $fields->string('name'),
            baseUrl: $fields->optionalString('base_url') ?? '',
            languages: $fields->stringList('languages'),
            defaultLanguage: $fields->string('default_language'),
            defaultLayout: $fields->string('default_layout'),
            theme: $fields->optionalString('theme') ?? self::DEFAULT_THEME,
            nextPageId: $fields->optionalInt('next_page_id') ?? 1,
            frontPage: $fields->optionalInt('front_page'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'base_url' => $this->baseUrl,
            'languages' => $this->languages,
            'default_language' => $this->defaultLanguage,
            'default_layout' => $this->defaultLayout,
            'theme' => $this->theme,
            'next_page_id' => $this->nextPageId,
        ];

        if ($this->frontPage !== null) {
            $data['front_page'] = $this->frontPage;
        }

        return $data;
    }

    /**
     * @param list<string>|null $languages
     */
    public function with(
        ?string $name = null,
        ?array $languages = null,
        ?string $defaultLanguage = null,
        ?string $defaultLayout = null,
        ?string $baseUrl = null,
    ): self {
        return new self(
            $name ?? $this->name,
            $baseUrl ?? $this->baseUrl,
            $languages ?? $this->languages,
            $defaultLanguage ?? $this->defaultLanguage,
            $defaultLayout ?? $this->defaultLayout,
            $this->theme,
            $this->nextPageId,
            $this->frontPage,
        );
    }

    public function withFrontPage(?int $frontPage): self
    {
        return new self(
            $this->name,
            $this->baseUrl,
            $this->languages,
            $this->defaultLanguage,
            $this->defaultLayout,
            $this->theme,
            $this->nextPageId,
            $frontPage,
        );
    }

    /**
     * Returns a copy with the next page id taken; the caller gets the id to use.
     *
     * @return array{0: self, 1: int} the updated site and the allocated id
     */
    public function allocatePageId(): array
    {
        $copy = new self(
            $this->name,
            $this->baseUrl,
            $this->languages,
            $this->defaultLanguage,
            $this->defaultLayout,
            $this->theme,
            $this->nextPageId + 1,
            $this->frontPage,
        );

        return [$copy, $this->nextPageId];
    }
}
