<?php

declare(strict_types=1);

namespace Campanella\Lite\Storage;

use Campanella\Lite\Exception\InvalidDataException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Converts between YAML text and plain PHP arrays.
 *
 * Only plain data is accepted: objects, PHP constants and custom tags
 * are rejected, so a project file can never instantiate anything.
 */
final class YamlCodec
{
    private const INLINE_LEVEL = 4;
    private const INDENT = 2;

    /**
     * Parses a YAML document whose top level must be a mapping.
     * An empty document is an empty mapping.
     *
     * @return array<mixed>
     */
    public static function decode(string $yaml, string $source): array
    {
        try {
            $data = Yaml::parse($yaml, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $exception) {
            throw new InvalidDataException($source, $exception->getMessage());
        }

        if ($data === null) {
            return [];
        }

        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new InvalidDataException($source, 'the document must be a mapping of keys to values');
        }

        return $data;
    }

    /**
     * @param array<mixed> $data
     */
    public static function encode(array $data): string
    {
        return Yaml::dump(
            $data,
            self::INLINE_LEVEL,
            self::INDENT,
            Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_COMPACT_NESTED_MAPPING,
        );
    }
}
