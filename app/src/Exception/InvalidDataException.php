<?php

declare(strict_types=1);

namespace Campanella\Lite\Exception;

use RuntimeException;

/**
 * A project file is unreadable as data: broken YAML, a missing field,
 * a value of the wrong type or an unknown key.
 *
 * This is a structural error found while loading. Semantic problems,
 * such as a reference to a layout that does not exist, are reported
 * by the validator as violations instead.
 */
final class InvalidDataException extends RuntimeException
{
    public function __construct(
        public readonly string $source,
        public readonly string $detail,
        public readonly ?string $field = null,
    ) {
        $location = $field !== null ? sprintf('%s, field "%s"', $source, $field) : $source;

        parent::__construct(sprintf('Invalid data in %s: %s', $location, $detail));
    }
}
