<?php

declare(strict_types=1);

namespace Campanella\Lite\Command;

use Campanella\Lite\I18n\Message;

/**
 * What a successful command reports.
 */
final class CommandResult
{
    public function __construct(
        public readonly Message $message,
        public readonly ?Output $output = null,
    ) {
    }

    /**
     * @param array<string, string|int> $params
     */
    public static function ok(string $key, array $params = [], ?Output $output = null): self
    {
        return new self(new Message($key, $params), $output);
    }
}
