<?php

declare(strict_types=1);

namespace Campanella\Lite\Exception;

use RuntimeException;

/**
 * A file could not be read, written or located.
 */
class StorageException extends RuntimeException
{
    public static function notFound(string $path): self
    {
        return new self(sprintf('File not found: "%s".', $path));
    }

    public static function unsafePath(string $path, string $reason): self
    {
        return new self(sprintf('Unsafe path "%s": %s.', $path, $reason));
    }

    public static function operationFailed(string $operation, string $path): self
    {
        $error = error_get_last();
        $detail = $error !== null ? ' ' . $error['message'] : '';

        return new self(sprintf('Could not %s "%s".%s', $operation, $path, $detail));
    }
}
