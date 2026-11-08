<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Handler;

use Campanella\Lite\Command\CommandFailed;
use Campanella\Lite\Command\CommandHandler;
use Campanella\Lite\Command\CommandResult;
use Campanella\Lite\Command\Invocation;
use Campanella\Lite\Command\Output;
use Campanella\Lite\Command\Signature;
use Campanella\Lite\Command\Workspace;
use Campanella\Lite\Files\FileTypes;
use Campanella\Lite\Model\File;

/**
 * Lists and searches the files of the repository. Every option narrows the list.
 */
final class ListFilesCommand implements CommandHandler
{
    public function signature(): Signature
    {
        return new Signature(
            name: 'list files',
            options: ['type' => true, 'name' => true, 'by' => true, 'since' => true, 'search' => true, 'archived' => false],
            examples: ['list files', 'list files --type=image', 'list files --name=hall --since=2026-11-01', 'list files --search=conference', 'list files --archived'],
            readOnly: true,
        );
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $project = $workspace->project();
        $language = $project->site->defaultLanguage;
        $type = $invocation->option('type');
        $name = $invocation->option('name');
        $by = $invocation->option('by');
        $since = $invocation->option('since');
        $search = $invocation->option('search');
        $archived = $invocation->flag('archived');

        if ($type !== null && !in_array($type, FileTypes::KINDS, true)) {
            throw CommandFailed::because('value.invalid_choice', ['value' => $type, 'choices' => implode(', ', FileTypes::KINDS)]);
        }

        if ($since !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $since) !== 1) {
            throw CommandFailed::because('file.invalid_date', ['value' => $since]);
        }

        $matches = static fn (?string $needle, string ...$haystacks): bool => $needle === null
            || array_filter($haystacks, static fn (string $haystack): bool => mb_stripos($haystack, $needle) !== false) !== [];

        $rows = [];

        foreach ($project->files as $file) {
            if ($file->isArchived() !== $archived
                || ($type !== null && $file->kind() !== $type)
                || !$matches($name, $file->filename)
                || ($by !== null && $file->uploadedBy !== $by)
                || ($since !== null && substr($file->uploadedAt, 0, 10) < $since)
                || !$matches($search, $file->filename, ...array_values($file->description->toArray()))) {
                continue;
            }

            $rows[] = [
                (string) $file->id,
                $file->filename,
                $file->kind(),
                self::size($file),
                substr($file->uploadedAt, 0, 10),
                $file->uploadedBy,
                $file->description->get($language) ?? '',
            ];
        }

        return CommandResult::ok(
            'ok.list',
            ['type' => 'files', 'count' => count($rows)],
            Output::table(['id', 'filename', 'type', 'size', 'uploaded', 'by', 'description'], $rows),
        );
    }

    /** "2.4 MB, 1600×1200" */
    public static function size(File $file): string
    {
        $bytes = $file->size;
        $size = match (true) {
            $bytes >= 1024 * 1024 => sprintf('%.1f MB', $bytes / 1024 / 1024),
            $bytes >= 1024 => sprintf('%d KB', (int) round($bytes / 1024)),
            default => $bytes . ' B',
        };

        return $file->width !== null && $file->height !== null ? $size . ', ' . $file->width . '×' . $file->height : $size;
    }
}
