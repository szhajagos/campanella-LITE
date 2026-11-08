<?php

declare(strict_types=1);

namespace Campanella\Lite\Admin;

use Campanella\Lite\Storage\FileStore;

/**
 * The batches an admin ran, newest first, so they can be recalled in the
 * command box. Kept per user in var/admin/history/{user}.json.
 */
final class History
{
    public const LIMIT = 50;

    public function __construct(private readonly FileStore $var)
    {
    }

    /**
     * @return list<string>
     */
    public function list(string $user): array
    {
        $file = self::file($user);

        if (!$this->var->exists($file)) {
            return [];
        }

        $data = json_decode($this->var->read($file), true);

        return is_array($data) ? array_values(array_filter($data, 'is_string')) : [];
    }

    public function add(string $user, string $batch): void
    {
        $batch = rtrim($batch);

        if (trim($batch) === '') {
            return;
        }

        $entries = array_values(array_filter($this->list($user), static fn (string $entry): bool => $entry !== $batch));
        array_unshift($entries, $batch);

        $this->var->write(self::file($user), json_encode(array_slice($entries, 0, self::LIMIT), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private static function file(string $user): string
    {
        return 'admin/history/' . $user . '.json';
    }
}
