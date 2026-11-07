<?php

declare(strict_types=1);

namespace Campanella\Lite\I18n;

/**
 * A translatable message: a stable key and the values for its placeholders.
 *
 * The text itself comes from the message catalogue of the reader's locale,
 * so the same result can be shown in English to one admin and in Hungarian
 * to another, and machines can rely on the key.
 */
final class Message
{
    /**
     * @param array<string, string|int> $params
     */
    public function __construct(
        public readonly string $key,
        public readonly array $params = [],
    ) {
    }
}
