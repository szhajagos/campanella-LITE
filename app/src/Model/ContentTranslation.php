<?php

declare(strict_types=1);

namespace Campanella\Lite\Model;

/**
 * One language version of a content item. Stored in project/content/{name}/{language}.md
 * as a front matter block (title, format) followed by the body.
 */
final class ContentTranslation
{
    public function __construct(
        public readonly string $language,
        public readonly string $title,
        public readonly string $body,
        public readonly ContentFormat $format = ContentFormat::Markdown,
    ) {
    }

    /**
     * @param array<mixed> $frontMatter
     */
    public static function fromDocument(string $language, array $frontMatter, string $body, string $source): self
    {
        $fields = new Fields($frontMatter, $source);
        $fields->allowOnly(['title', 'format']);

        $format = ContentFormat::Markdown;

        if ($fields->has('format')) {
            $format = ContentFormat::tryFrom($fields->string('format'))
                ?? $fields->fail('format', 'expected "markdown"');
        }

        return new self($language, $fields->optionalString('title') ?? '', $body, $format);
    }

    /**
     * @return array<string, string>
     */
    public function frontMatter(): array
    {
        return ['title' => $this->title, 'format' => $this->format->value];
    }
}
