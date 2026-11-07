<?php

declare(strict_types=1);

namespace Campanella\Lite\Model;

use Campanella\Lite\Exception\InvalidDataException;

/**
 * Typed, checked access to the fields of a decoded YAML mapping.
 *
 * Every accessor either returns a value of the promised type or throws an
 * InvalidDataException that names the file and the field, so a broken file
 * is reported precisely instead of failing somewhere later.
 *
 * @internal used by the model classes while loading
 */
final class Fields
{
    /**
     * @param array<mixed> $data
     */
    public function __construct(
        private readonly array $data,
        private readonly string $source,
        private readonly string $prefix = '',
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public function nested(array $data, string|int $key): self
    {
        return new self($data, $this->source, $this->field($key));
    }

    /**
     * Rejects keys that are not in the list, which catches typos in hand-edited files.
     *
     * @param list<string> $allowed
     */
    public function allowOnly(array $allowed): void
    {
        foreach (array_keys($this->data) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new InvalidDataException(
                    $this->source,
                    sprintf('unknown key; allowed keys are: %s', implode(', ', $allowed)),
                    $this->field($key),
                );
            }
        }
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data) && $this->data[$key] !== null;
    }

    public function string(string $key): string
    {
        $value = $this->required($key);

        if (!is_string($value)) {
            $this->fail($key, 'expected text');
        }

        return $value;
    }

    public function optionalString(string $key): ?string
    {
        return $this->has($key) ? $this->string($key) : null;
    }

    public function int(string $key): int
    {
        $value = $this->required($key);

        if (!is_int($value)) {
            $this->fail($key, 'expected a whole number');
        }

        return $value;
    }

    public function optionalInt(string $key): ?int
    {
        return $this->has($key) ? $this->int($key) : null;
    }

    /**
     * A list of texts; a missing key is an empty list.
     *
     * @return list<string>
     */
    public function stringList(string $key): array
    {
        $list = $this->list($key);

        foreach ($list as $index => $item) {
            if (!is_string($item)) {
                throw new InvalidDataException($this->source, 'expected text', $this->field($key) . '.' . ($index + 1));
            }
        }

        /** @var list<string> $list */
        return $list;
    }

    /**
     * A list of mappings; a missing key is an empty list.
     *
     * @return list<array<mixed>>
     */
    public function mappingList(string $key): array
    {
        $list = $this->list($key);

        foreach ($list as $index => $item) {
            if (!is_array($item) || ($item !== [] && array_is_list($item))) {
                throw new InvalidDataException($this->source, 'expected a mapping of keys to values', $this->field($key) . '.' . ($index + 1));
            }
        }

        /** @var list<array<mixed>> $list */
        return $list;
    }

    /**
     * A mapping with text keys; a missing key is an empty mapping.
     *
     * @return array<string, mixed>
     */
    public function mapping(string $key): array
    {
        if (!$this->has($key)) {
            return [];
        }

        $value = $this->data[$key];

        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            $this->fail($key, 'expected a mapping of keys to values');
        }

        foreach (array_keys($value) as $innerKey) {
            if (!is_string($innerKey)) {
                throw new InvalidDataException($this->source, 'keys must be text', $this->field($key) . '.' . $innerKey);
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * A language-dependent text: a mapping of language codes to texts.
     * A missing key is an empty text.
     */
    public function localized(string $key): LocalizedText
    {
        $values = [];

        foreach ($this->mapping($key) as $language => $text) {
            if (!is_string($text)) {
                throw new InvalidDataException($this->source, 'expected text', $this->field($key) . '.' . $language);
            }

            $values[$language] = $text;
        }

        return LocalizedText::fromArray($values);
    }

    public function fail(string|int $key, string $detail): never
    {
        throw new InvalidDataException($this->source, $detail, $this->field($key));
    }

    public function source(): string
    {
        return $this->source;
    }

    private function required(string $key): mixed
    {
        if (!$this->has($key)) {
            $this->fail($key, 'this field is required');
        }

        return $this->data[$key];
    }

    /**
     * @return list<mixed>
     */
    private function list(string $key): array
    {
        if (!$this->has($key)) {
            return [];
        }

        $value = $this->data[$key];

        if (!is_array($value) || !array_is_list($value)) {
            $this->fail($key, 'expected a list');
        }

        return $value;
    }

    private function field(string|int $key): string
    {
        return $this->prefix === '' ? (string) $key : $this->prefix . '.' . $key;
    }
}
