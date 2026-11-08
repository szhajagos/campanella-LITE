<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Log;

/**
 * One line of the command log.
 */
final class LogEntry
{
    public const APPLIED = 'applied';
    public const UNDONE = 'undone';
    public const UPLOADED = 'uploaded';
    public const PURGED = 'purged';

    /**
     * A new entry id, sortable by time, and the time itself.
     *
     * @return array{0: string, 1: string}
     */
    public static function stamp(): array
    {
        $now = new \DateTimeImmutable();

        return [$now->format('Ymd-His-u'), $now->format('c')];
    }

    /**
     * @param list<string> $files project-relative paths
     */
    public function __construct(
        public readonly string $id,
        public readonly string $time,
        public readonly ?string $user,
        public readonly string $action,
        public readonly array $files,
        public readonly string $batch,
        public readonly ?string $undoes = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'time' => $this->time,
            'user' => $this->user,
            'action' => $this->action,
            'files' => $this->files,
            'batch' => $this->batch,
        ];

        if ($this->undoes !== null) {
            $data['undoes'] = $this->undoes;
        }

        return $data;
    }

    public static function fromJson(string $line): ?self
    {
        try {
            $data = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($data) || !is_string($data['id'] ?? null) || !is_string($data['action'] ?? null)) {
            return null;
        }

        $files = is_array($data['files'] ?? null) ? array_values(array_filter($data['files'], 'is_string')) : [];

        return new self(
            $data['id'],
            is_string($data['time'] ?? null) ? $data['time'] : '',
            is_string($data['user'] ?? null) ? $data['user'] : null,
            $data['action'],
            $files,
            is_string($data['batch'] ?? null) ? $data['batch'] : '',
            is_string($data['undoes'] ?? null) ? $data['undoes'] : null,
        );
    }

    /** The first command of the batch, with a count of the others. */
    public function summary(): string
    {
        $commands = array_values(array_filter(
            array_map('trim', explode("\n", $this->batch)),
            static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#') && !str_starts_with($line, '@'),
        ));

        $first = $commands[0] ?? '';

        if (mb_strlen($first) > 60) {
            $first = mb_substr($first, 0, 59) . '…';
        }

        return $first;
    }
}
