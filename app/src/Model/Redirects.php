<?php

declare(strict_types=1);

namespace Campanella\Lite\Model;

use Campanella\Lite\Exception\InvalidDataException;

/**
 * Old addresses that lead to a page, stored in project/redirects.yaml:
 *
 *     /about-us: { page: 2, language: en }
 *     /hu/regi-rolunk: { page: 2, language: hu }
 *
 * A redirect points to a page, not to another address, so it always leads
 * to the page's current address in that language and never forms a chain.
 */
final class Redirects
{
    /**
     * @param array<string, array{page: int, language: string}> $targets path => target
     */
    private function __construct(private readonly array $targets)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data, string $source): self
    {
        $targets = [];

        foreach ($data as $path => $target) {
            if (!is_string($path) || !is_array($target)) {
                throw new InvalidDataException($source, 'expected "/path: { page: <id>, language: <code> }"', (string) $path);
            }

            $fields = new Fields($target, $source, $path);
            $fields->allowOnly(['page', 'language']);
            $targets[$path] = ['page' => $fields->int('page'), 'language' => $fields->string('language')];
        }

        ksort($targets);

        return new self($targets);
    }

    /**
     * @return array{page: int, language: string}|null
     */
    public function find(string $path): ?array
    {
        return $this->targets[$path] ?? null;
    }

    public function with(string $path, int $page, string $language): self
    {
        $targets = $this->targets;
        $targets[$path] = ['page' => $page, 'language' => $language];
        ksort($targets);

        return new self($targets);
    }

    public function without(string $path): self
    {
        $targets = $this->targets;
        unset($targets[$path]);

        return new self($targets);
    }

    /**
     * Keeps only the redirects the callback accepts.
     *
     * @param callable(string, array{page: int, language: string}): bool $keep
     */
    public function filter(callable $keep): self
    {
        $targets = [];

        foreach ($this->targets as $path => $target) {
            if ($keep($path, $target)) {
                $targets[$path] = $target;
            }
        }

        return new self($targets);
    }

    public function isEmpty(): bool
    {
        return $this->targets === [];
    }

    /**
     * @return array<string, array{page: int, language: string}>
     */
    public function toArray(): array
    {
        return $this->targets;
    }
}
