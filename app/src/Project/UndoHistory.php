<?php

declare(strict_types=1);

namespace Campanella\Lite\Project;

use Campanella\Lite\Exception\InvalidDataException;
use Campanella\Lite\Storage\FileStore;

/**
 * Keeps what the project files looked like before each applied batch, so
 * the batch can be undone.
 *
 * One snapshot per batch, in var/undo/{id}.json: the earlier contents of
 * every file the batch changed (null for a file that did not exist) and
 * a hash of each file as the batch left it. Undo restores the earlier
 * contents, but only while the files are still as the batch left them,
 * so it never overwrites changes made later or by hand.
 *
 * Only the most recent snapshots are kept (50 by default).
 */
final class UndoHistory
{
    private const DIRECTORY = 'undo';

    public function __construct(
        private readonly FileStore $project,
        private readonly FileStore $var,
        private readonly int $limit = 50,
    ) {
    }

    /**
     * The current contents of the files that store the given entities.
     *
     * Call before the batch is written, then pass the result to record().
     *
     * @param list<EntityRef> $refs
     *
     * @return array<string, string|null> project-relative path => contents, null if absent
     */
    public function capture(array $refs): array
    {
        $files = [];

        foreach ($refs as $ref) {
            foreach ($this->filesOf($ref) as $path) {
                $files[$path] = $this->project->exists($path) ? $this->project->read($path) : null;
            }
        }

        return $files;
    }

    /**
     * Stores the snapshot of a batch that has just been written.
     *
     * @param array<string, string|null> $before what capture() returned before the batch was written
     * @param list<EntityRef>            $refs   the entities the batch changed
     */
    public function record(string $id, array $before, array $refs, string $time, ?string $user, string $batch): void
    {
        $after = $this->capture($refs);
        $files = [];

        foreach (array_keys($before + $after) as $path) {
            $old = $before[$path] ?? null;
            $new = $after[$path] ?? null;

            if ($old !== $new) {
                $files[$path] = ['before' => $old, 'after' => $new === null ? null : sha1($new)];
            }
        }

        ksort($files);

        $this->var->write(self::DIRECTORY . '/' . $id . '.json', json_encode([
            'id' => $id,
            'time' => $time,
            'user' => $user,
            'batch' => $batch,
            'files' => $files,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");

        $this->prune();
    }

    /**
     * The most recent snapshot, or null if there is nothing to undo.
     */
    public function latest(): ?UndoSnapshot
    {
        $ids = $this->ids();
        $id = end($ids);

        return $id === false ? null : $this->load($id);
    }

    public function count(): int
    {
        return count($this->ids());
    }

    /**
     * The files of a snapshot that changed after its batch was written.
     *
     * @return list<string>
     */
    public function conflicts(UndoSnapshot $snapshot): array
    {
        $conflicts = [];

        foreach ($snapshot->files as $path => $file) {
            $current = $this->project->exists($path) ? sha1($this->project->read($path)) : null;

            if ($current !== $file['after']) {
                $conflicts[] = $path;
            }
        }

        return $conflicts;
    }

    /**
     * Puts the files back as they were before the batch, then forgets the snapshot.
     *
     * @return list<string> the restored files
     */
    public function restore(UndoSnapshot $snapshot): array
    {
        foreach ($snapshot->files as $path => $file) {
            if ($file['before'] === null) {
                $this->project->delete($path);
                $directory = dirname($path);

                if ($directory !== '.' && str_starts_with($path, ProjectRepository::CONTENT_DIR . '/')) {
                    $this->project->deleteDirectoryIfEmpty($directory);
                }
            } else {
                $this->project->write($path, $file['before']);
            }
        }

        $this->var->delete(self::DIRECTORY . '/' . $snapshot->id . '.json');

        return array_keys($snapshot->files);
    }

    /**
     * @return list<string> the files that store an entity: its file, or every file of a content directory
     */
    private function filesOf(EntityRef $ref): array
    {
        $path = ProjectRepository::pathOf($ref);

        if (!str_ends_with($path, '/')) {
            return [$path];
        }

        $directory = rtrim($path, '/');

        return array_map(
            static fn (string $name): string => $directory . '/' . $name . '.md',
            $this->project->listFiles($directory, 'md'),
        );
    }

    /**
     * @return list<string> snapshot ids, oldest first
     */
    private function ids(): array
    {
        $ids = $this->var->listFiles(self::DIRECTORY, 'json');
        sort($ids, SORT_STRING);

        return $ids;
    }

    private function prune(): void
    {
        $ids = $this->ids();

        foreach (array_slice($ids, 0, max(0, count($ids) - $this->limit)) as $id) {
            $this->var->delete(self::DIRECTORY . '/' . $id . '.json');
        }
    }

    private function load(string $id): UndoSnapshot
    {
        $source = self::DIRECTORY . '/' . $id . '.json';

        try {
            $data = json_decode($this->var->read($source), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidDataException('var/' . $source, $exception->getMessage());
        }

        if (!is_array($data) || !is_array($data['files'] ?? null)) {
            throw new InvalidDataException('var/' . $source, 'not an undo snapshot');
        }

        $files = [];

        foreach ($data['files'] as $path => $file) {
            if (!is_string($path) || !is_array($file)
                || !(is_string($file['before'] ?? null) || ($file['before'] ?? null) === null)
                || !(is_string($file['after'] ?? null) || ($file['after'] ?? null) === null)) {
                throw new InvalidDataException('var/' . $source, 'invalid file entry');
            }

            $files[$path] = ['before' => $file['before'] ?? null, 'after' => $file['after'] ?? null];
        }

        return new UndoSnapshot(
            $id,
            is_string($data['time'] ?? null) ? $data['time'] : '',
            is_string($data['user'] ?? null) ? $data['user'] : null,
            is_string($data['batch'] ?? null) ? $data['batch'] : '',
            $files,
        );
    }
}
