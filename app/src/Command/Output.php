<?php

declare(strict_types=1);

namespace Campanella\Lite\Command;

use Campanella\Lite\I18n\Message;

/**
 * Data a command returns besides its message: a value (from "get"),
 * a table (from "list") or lines of text (from "help").
 *
 * Table cells and lines may be Messages; formatters translate them into
 * the reader's language.
 */
final class Output
{
    public const VALUE = 'value';
    public const TABLE = 'table';
    public const LINES = 'lines';

    /**
     * @param self::VALUE|self::TABLE|self::LINES $type
     */
    private function __construct(
        public readonly string $type,
        public readonly mixed $value,
    ) {
    }

    public static function value(mixed $value): self
    {
        return new self(self::VALUE, $value);
    }

    /**
     * @param list<string>               $columns
     * @param list<list<string|Message>> $rows
     */
    public static function table(array $columns, array $rows): self
    {
        return new self(self::TABLE, ['columns' => $columns, 'rows' => $rows]);
    }

    /**
     * @param list<string|Message> $lines
     */
    public static function lines(array $lines): self
    {
        return new self(self::LINES, $lines);
    }
}
