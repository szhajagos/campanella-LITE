<?php

declare(strict_types=1);

namespace Campanella\Lite\Files;

use Campanella\Lite\Model\File;
use Campanella\Lite\Model\Project;
use Campanella\Lite\Render\PageCache;

/**
 * The public side of the file repository: the addresses of files on the
 * website and the files behind them.
 *
 *     /files/12-conference-hall-960.jpg    an image, 960 pixels wide
 *     /files/12-conference-hall-960.webp   the same as WebP
 *     /files/13-price-list.pdf             any other file, as uploaded
 *
 * Nothing is published in advance. The first request of an address reaches
 * the front controller, which makes the file, stores it in the web root and
 * lists it in var/files-manifest.json; later requests are served by the web
 * server directly. Archived, purged and renamed files lose their addresses
 * when the change is written (see keeper()).
 *
 * Images are only published as re-encoded copies, so the location and
 * camera data in the original never reach visitors. Without GD, or for GIF
 * animations, the original is published instead.
 */
final class PublicFiles
{
    public const PREFIX = '/files/';
    public const DIRECTORY = 'files';

    /** Widths of the published image sizes; images are never enlarged. */
    public const WIDTHS = [480, 960, 1600];

    public function __construct(
        private readonly FileRepository $repository,
        private readonly PageCache $cache,
        private readonly string $temporaryDirectory,
        private readonly ImageResizer $resizer = new ImageResizer(),
    ) {
    }

    /**
     * The widths an image is published in, smallest first; empty when the
     * file is published as uploaded.
     *
     * @return list<int>
     */
    public function widths(File $file): array
    {
        if ($file->kind() !== FileTypes::IMAGE || $file->extension === 'gif' || $file->width === null || $file->height === null
            || !ImageResizer::canResize($file->width, $file->height, $file->size)) {
            return [];
        }

        $largest = min($file->width, max(self::WIDTHS));
        $widths = array_filter(self::WIDTHS, static fn (int $width): bool => $width < $largest);
        $widths[] = $largest;

        return array_values(array_unique($widths));
    }

    /** Whether WebP versions are published next to the image's own format. */
    public function hasWebp(File $file): bool
    {
        return $this->widths($file) !== [] && $file->extension !== 'webp' && ImageResizer::webpAvailable();
    }

    public function url(File $file, ?int $width = null, bool $webp = false): string
    {
        return self::PREFIX . $file->id . '-' . Slug::of($file->filename)
            . ($width === null ? '' : '-' . $width)
            . '.' . ($webp ? 'webp' : $file->extension);
    }

    /** The address a link to the file points to: the largest image size, or the file itself. */
    public function linkUrl(File $file): string
    {
        $widths = $this->widths($file);

        return $this->url($file, $widths === [] ? null : max($widths));
    }

    /**
     * Every address the file is published at.
     *
     * @return array<string, array{width: int|null, webp: bool}> address => what it holds
     */
    public function addresses(File $file): array
    {
        $widths = $this->widths($file);

        if ($widths === []) {
            return [$this->url($file) => ['width' => null, 'webp' => false]];
        }

        $addresses = [];

        foreach ($widths as $width) {
            $addresses[$this->url($file, $width)] = ['width' => $width, 'webp' => false];

            if ($this->hasWebp($file)) {
                $addresses[$this->url($file, $width, true)] = ['width' => $width, 'webp' => true];
            }
        }

        return $addresses;
    }

    /**
     * The file whose address this could be, if it is published.
     */
    public function fileOf(Project $project, string $path): ?File
    {
        if (preg_match('#^' . self::PREFIX . '([1-9][0-9]{0,9})-#', $path, $match) !== 1) {
            return null;
        }

        $file = $project->file((int) $match[1]);

        return $file === null || $file->isArchived() ? null : $file;
    }

    /**
     * Makes the file behind a published address and stores it in the web root.
     *
     * @return string|null the stored file, or null if the address is not one of the file's addresses
     *
     * @throws FileRejected if the image cannot be converted
     */
    public function publish(File $file, string $path): ?string
    {
        $address = $this->addresses($file)[$path] ?? null;
        $source = $this->repository->pathOf($file);

        if ($address === null || $source === null) {
            return null;
        }

        $target = self::DIRECTORY . '/' . basename($path);

        if ($address['width'] === null) {
            return $this->cache->storeFile($target, $source);
        }

        if (!is_dir($this->temporaryDirectory)) {
            @mkdir($this->temporaryDirectory, 0775, true);
        }

        $temporary = $this->temporaryDirectory . '/' . bin2hex(random_bytes(8)) . '.' . ($address['webp'] ? 'webp' : $file->extension);

        try {
            $this->resizer->resize($source, $temporary, $address['width'], $address['webp'] ? 'webp' : $file->extension);

            return $this->cache->storeFile($target, $temporary);
        } finally {
            @unlink($temporary);
        }
    }

    /**
     * Tells which published files are still current: the file exists, is
     * not archived, and the address is one of its addresses.
     *
     * @return callable(string): bool for PageCache::prune(), with paths relative to the web root
     */
    public function keeper(Project $project): callable
    {
        return function (string $published) use ($project): bool {
            $path = '/' . $published;
            $file = $this->fileOf($project, $path);

            return $file !== null && isset($this->addresses($file)[$path]);
        };
    }

    public function mimeTypeOf(File $file, string $path): string
    {
        return str_ends_with($path, '.webp') ? 'image/webp' : $file->mimeType;
    }
}
