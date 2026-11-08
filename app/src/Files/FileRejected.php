<?php

declare(strict_types=1);

namespace Campanella\Lite\Files;

use Campanella\Lite\I18n\Message;
use RuntimeException;

/**
 * A file was not accepted; the message says why.
 */
final class FileRejected extends RuntimeException
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
