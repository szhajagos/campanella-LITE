<?php

declare(strict_types=1);

namespace Campanella\Lite\Admin;

/**
 * Appends login events to var/log/auth.log, one line each:
 *
 *     2026-11-08T09:15:00+01:00 login_ok user=admin ip=203.0.113.7
 */
final class AuthLog
{
    public function __construct(private readonly string $file)
    {
    }

    public function record(string $event, string $user, string $address, int $time): void
    {
        $directory = dirname($this->file);

        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $line = sprintf(
            "%s %s user=%s ip=%s\n",
            date('c', $time),
            $event,
            preg_replace('/[^A-Za-z0-9._@-]/', '?', mb_substr($user, 0, 64)) ?? '?',
            preg_replace('/[^0-9A-Fa-f.:]/', '?', $address) ?? '?',
        );

        @file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);
    }
}
