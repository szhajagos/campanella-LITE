<?php

declare(strict_types=1);

namespace Campanella\Lite\Storage;

use Campanella\Lite\Exception\InvalidDataException;

/**
 * Converts between a text document with a YAML front matter block and its parts.
 *
 *     ---
 *     title: Introduction
 *     format: markdown
 *     ---
 *     The body, as written.
 */
final class FrontMatterCodec
{
    private const DELIMITER = '---';

    /**
     * @return array{0: array<mixed>, 1: string} the front matter and the body
     */
    public static function decode(string $text, string $source): array
    {
        $text = str_replace("\r\n", "\n", $text);

        if (str_starts_with($text, "\u{FEFF}")) {
            $text = substr($text, 3);
        }

        $opening = self::DELIMITER . "\n";

        if (!str_starts_with($text, $opening)) {
            throw new InvalidDataException($source, 'the file must start with a "---" line that opens the front matter');
        }

        $start = strlen($opening);
        $closing = "\n" . self::DELIMITER . "\n";

        if (str_starts_with(substr($text, $start), $opening)) {
            return [[], substr($text, $start + strlen($opening))];
        }

        $end = strpos($text, $closing, $start - 1);

        if ($end !== false) {
            $yaml = substr($text, $start, $end - $start + 1);
            $body = substr($text, $end + strlen($closing));
        } elseif (str_ends_with($text, "\n" . self::DELIMITER)) {
            $yaml = substr($text, $start, strlen($text) - $start - strlen(self::DELIMITER));
            $body = '';
        } else {
            throw new InvalidDataException($source, 'the front matter is not closed by a "---" line');
        }

        return [YamlCodec::decode($yaml, $source), $body];
    }

    /**
     * @param array<mixed> $frontMatter
     */
    public static function encode(array $frontMatter, string $body): string
    {
        $yaml = $frontMatter === [] ? '' : YamlCodec::encode($frontMatter);

        return self::DELIMITER . "\n" . $yaml . self::DELIMITER . "\n" . $body;
    }
}
