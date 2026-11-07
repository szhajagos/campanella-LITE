<?php

declare(strict_types=1);

namespace Campanella\Lite\Command;

use Campanella\Lite\I18n\Message;
use RuntimeException;

/**
 * Thrown by a command that cannot do what was asked. The batch stops and
 * nothing is written.
 */
final class CommandFailed extends RuntimeException
{
    public function __construct(public readonly Message $reason)
    {
        parent::__construct($reason->key);
    }

    /**
     * @param array<string, string|int> $params
     */
    public static function because(string $key, array $params = []): self
    {
        return new self(new Message($key, $params));
    }
}
