<?php

declare(strict_types=1);

namespace Campanella\Lite\Model;

/**
 * A piece of content that can be placed into layout columns, with one
 * version per language. Stored in the directory project/content/{name}/.
 */
final class Content
{
    /** @var array<string, ContentTranslation> */
    public readonly array $translations;

    /**
     * @param list<ContentTranslation> $translations
     */
    public function __construct(
        public readonly string $name,
        array $translations,
    ) {
        $byLanguage = [];

        foreach ($translations as $translation) {
            $byLanguage[$translation->language] = $translation;
        }

        ksort($byLanguage);
        $this->translations = $byLanguage;
    }

    public function withTranslation(ContentTranslation $translation): self
    {
        $translations = $this->translations;
        $translations[$translation->language] = $translation;

        return new self($this->name, array_values($translations));
    }

    public function withoutTranslation(string $language): self
    {
        $translations = $this->translations;
        unset($translations[$language]);

        return new self($this->name, array_values($translations));
    }

    public function translation(string $language): ?ContentTranslation
    {
        return $this->translations[$language] ?? null;
    }

    /**
     * @return array<string, array{title: string, format: string, body: string}> language => translation data
     */
    public function toArray(): array
    {
        $data = [];

        foreach ($this->translations as $language => $translation) {
            $data[$language] = $translation->frontMatter() + ['body' => $translation->body];
        }

        return $data;
    }

    /**
     * @return list<string>
     */
    public function languages(): array
    {
        return array_keys($this->translations);
    }
}
