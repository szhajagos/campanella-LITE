<?php

declare(strict_types=1);

namespace Campanella\Lite\Model;

/**
 * A text that can have a value per language, such as a page title.
 *
 * It is stored as a mapping of language codes to texts even on a
 * single-language website ({ en: About us }), so that adding a language
 * later never changes the file format.
 */
final class LocalizedText
{
    /**
     * @param array<string, string> $values
     */
    private function __construct(private readonly array $values)
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param array<string, string> $values language code => text
     */
    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    public static function of(string $language, string $text): self
    {
        return new self([$language => $text]);
    }

    public function get(string $language): ?string
    {
        return $this->values[$language] ?? null;
    }

    public function has(string $language): bool
    {
        return isset($this->values[$language]);
    }

    public function with(string $language, string $text): self
    {
        $values = $this->values;
        $values[$language] = $text;

        return new self($values);
    }

    public function without(string $language): self
    {
        $values = $this->values;
        unset($values[$language]);

        return new self($values);
    }

    /**
     * @return list<string>
     */
    public function languages(): array
    {
        return array_keys($this->values);
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->values;
    }
}
