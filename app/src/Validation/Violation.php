<?php

declare(strict_types=1);

namespace Campanella\Lite\Validation;

/**
 * One problem found by the validator.
 *
 * The code is stable and machine-readable ("page.unknown_layout"); the
 * human-readable, translatable message is produced from the code and the
 * parameters by the message catalogue (milestone 2). The path uses the same
 * dotted notation as the command language ("page.2.layout").
 */
final class Violation
{
    /**
     * @param array<string, string|int> $params
     */
    public function __construct(
        public readonly string $code,
        public readonly string $path,
        public readonly array $params = [],
    ) {
    }

    public function __toString(): string
    {
        $params = [];

        foreach ($this->params as $key => $value) {
            $params[] = $key . '=' . $value;
        }

        return sprintf('%s at %s%s', $this->code, $this->path, $params === [] ? '' : ' (' . implode(', ', $params) . ')');
    }
}
