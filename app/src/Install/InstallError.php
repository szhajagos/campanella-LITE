<?php

declare(strict_types=1);

namespace Campanella\Lite\Install;

use Campanella\Lite\I18n\Message;
use RuntimeException;

/**
 * The installation could not go on; the message tells the user why.
 */
final class InstallError extends RuntimeException
{
    public readonly Message $reason;

    /**
     * @param array<string, string|int> $params
     */
    public function __construct(string $key, array $params = [])
    {
        $this->reason = new Message($key, $params);
        parent::__construct($key);
    }
}
