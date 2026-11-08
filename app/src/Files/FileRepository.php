<?php

declare(strict_types=1);

namespace Campanella\Lite\Files;

use Campanella\Lite\Command\Log\CommandLog;
use Campanella\Lite\Command\Log\LogEntry;
use Campanella\Lite\Model\File;
use Campanella\Lite\Project\ProjectRepository;
use Campanella\Lite\Storage\FileStore;
use Campanella\Lite\Storage\WriteLock;

/**
 * The file repository: where uploaded files are kept.
 *
 * Every way a file comes in (the admin upload, the command line, later the
 * AI module) goes through add(). It checks the file, stores it under a
 * random name in project/files/store/, writes its manifest and gives it the
 * next id. Adding a file changes nothing on the website, because nothing
 * refers to it yet; describing, renaming, using and archiving it are
 * commands. Only purge() removes a file for good.
 *
 *     project/files/next-id              the next id to give out
 *     project/files/manifest/{id}.yaml   the manifest (see Model\File)
 *     project/files/store/{xx}/{random}.{extension}
 */
final class FileRepository
{
    public const STORE_DIR = 'files/store';
    public const COUNTER_FILE = 'files/next-id';

    /** The stored name: a random directory and file name, independent of the id and the shown name. */
    public const STORED_NAME_PATTERN = '#^[0-9a-f]{2}/[0-9a-f]{32}\.[a-z0-9]{2,5}$#';

    public function __construct(
        private readonly FileStore $project,
        private readonly WriteLock $lock,
        private readonly ?CommandLog $log = null,
        private readonly FileInspector $inspector = new FileInspector(),
    ) {
    }

    /**
     * Adds a file to the repository.
     *
     * @param string      $path         the file to add (an upload's temporary file)
     * @param string      $originalName the name it had on the uploader's computer
     * @param string|null $user         the admin who adds it; null on the command line
     *
     * @throws FileRejected
     */
    public function add(string $path, string $originalName, ?string $user): File
    {
        $info = $this->inspector->inspect($path);
        $extension = $info['extension'];
        $filename = self::filename($originalName, $extension);
        $size = (int) filesize($path);

        return $this->lock->synchronized(function () use ($path, $filename, $extension, $size, $info, $user): File {
            $repository = new ProjectRepository($this->project);
            $id = $this->nextId();
            $stored = bin2hex(random_bytes(1)) . '/' . bin2hex(random_bytes(16)) . '.' . $extension;
            [$logId, $time] = LogEntry::stamp();

            $file = new File(
                id: $id,
                filename: $filename,
                storedFilename: $stored,
                mimeType: FileTypes::mimeTypeOf($extension),
                extension: $extension,
                size: $size,
                uploadedAt: $time,
                uploadedBy: $user ?? 'cli',
                width: $info['width'],
                height: $info['height'],
            );

            $this->project->import(self::STORE_DIR . '/' . $stored, $path);

            try {
                $repository->saveFile($file);
            } catch (\Throwable $error) {
                $this->project->delete(self::STORE_DIR . '/' . $stored);

                throw $error;
            }

            $this->project->write(self::COUNTER_FILE, ($id + 1) . "\n");
            $this->log?->append(new LogEntry($logId, $time, $user, LogEntry::UPLOADED, $this->pathsOf($file), 'upload ' . $filename));

            return $file;
        });
    }

    /**
     * Removes a file and its manifest for good. Cannot be undone.
     */
    public function purge(File $file, ?string $user): void
    {
        $this->lock->synchronized(function () use ($file, $user): void {
            (new ProjectRepository($this->project))->deleteFile($file->id);

            if (preg_match(self::STORED_NAME_PATTERN, $file->storedFilename) === 1) {
                $this->project->delete(self::STORE_DIR . '/' . $file->storedFilename);
                $this->project->deleteDirectoryIfEmpty(self::STORE_DIR . '/' . dirname($file->storedFilename));
            }

            [$logId, $time] = LogEntry::stamp();
            $this->log?->append(new LogEntry($logId, $time, $user, LogEntry::PURGED, $this->pathsOf($file), 'file purge ' . $file->id));
        });
    }

    /** The stored file on disk, or null if it is missing. */
    public function pathOf(File $file): ?string
    {
        if (preg_match(self::STORED_NAME_PATTERN, $file->storedFilename) !== 1) {
            return null;
        }

        $relative = self::STORE_DIR . '/' . $file->storedFilename;

        return $this->project->exists($relative) ? $this->project->path($relative) : null;
    }

    /**
     * The name shown for an uploaded file: the uploader's file name without
     * its folder, control characters and extension, with the extension of
     * the detected type ("Photo 1.JPEG" becomes "Photo 1.jpg").
     */
    public static function filename(string $originalName, string $extension): string
    {
        $name = str_replace('\\', '/', $originalName);
        $name = basename($name);
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', mb_scrub($name, 'UTF-8'));
        $name = (string) preg_replace('/\s+/u', ' ', $name);
        $dot = strrpos($name, '.');

        if ($dot !== false && $dot > 0) {
            $name = substr($name, 0, $dot);
        }

        $name = trim($name, " .\t");
        $name = mb_substr($name, 0, File::MAX_FILENAME_LENGTH - strlen($extension) - 1);

        return ($name === '' ? 'file' : $name) . '.' . $extension;
    }

    /** The next free id: never one that was given out before, even if that file has been purged. */
    private function nextId(): int
    {
        $counter = $this->project->exists(self::COUNTER_FILE) ? (int) trim($this->project->read(self::COUNTER_FILE)) : 1;
        $ids = array_map('intval', $this->project->listFiles(ProjectRepository::FILE_MANIFEST_DIR, 'yaml'));

        return max($counter, 1, $ids === [] ? 1 : max($ids) + 1);
    }

    /**
     * @return list<string>
     */
    private function pathsOf(File $file): array
    {
        return [ProjectRepository::FILE_MANIFEST_DIR . '/' . $file->id . '.yaml', self::STORE_DIR . '/' . $file->storedFilename];
    }
}
