<?php

declare(strict_types=1);

namespace Campanella\Lite\Files;

/**
 * Finds out what a file really is, from its content rather than its name,
 * and refuses anything that is not on the list of FileTypes or could carry
 * scripts.
 *
 * Detection reads the file's signature ("magic bytes") itself, so it gives
 * the same answer on every server, with or without PHP's fileinfo extension.
 */
final class FileInspector
{
    /** MP4 brands of video files; QuickTime, audio-only (M4A) and HEIF/AVIF images are not accepted. */
    private const MP4_BRANDS = ['isom', 'iso2', 'iso3', 'iso4', 'iso5', 'iso6', 'mp41', 'mp42', 'avc1', 'dash', 'M4V ', 'mmp4'];

    /** Main content type of an Office Open XML package => extension. */
    private const OOXML_TYPES = [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml' => 'xlsx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml' => 'pptx',
    ];

    /** PDF features that run code or carry other files. */
    private const PDF_ACTIVE = ['/JavaScript', '/JS', '/Launch', '/EmbeddedFile', '/EmbeddedFiles', '/RichMedia', '/XFA'];

    /** Larger images are refused: decoding them could exhaust the server's memory. */
    public const MAX_IMAGE_SIDE = 10000;
    public const MAX_IMAGE_PIXELS = 50_000_000;

    /**
     * @return array{extension: string, width: int|null, height: int|null}
     *
     * @throws FileRejected
     */
    public function inspect(string $path): array
    {
        $size = @filesize($path);

        if ($size === false || $size === 0) {
            throw new FileRejected('file.empty');
        }

        $head = (string) @file_get_contents($path, false, null, 0, 4096);
        $extension = $this->detect($head, $path);

        if ($size > FileTypes::maxSizeOf($extension)) {
            throw new FileRejected('file.too_large', ['max' => (int) (FileTypes::maxSizeOf($extension) / 1024 / 1024)]);
        }

        $width = $height = null;

        if (FileTypes::kindOf($extension) === FileTypes::IMAGE) {
            [$width, $height] = $this->imageSize($path, $extension);
        } elseif ($extension === 'pdf') {
            $this->checkPdf($path);
        } elseif (in_array($extension, ['docx', 'xlsx', 'pptx'], true)) {
            $this->checkOfficeMacros($path);
        }

        return ['extension' => $extension, 'width' => $width, 'height' => $height];
    }

    /**
     * @throws FileRejected
     */
    private function detect(string $head, string $path): string
    {
        $at = static fn (int $offset, string $bytes): bool => substr($head, $offset, strlen($bytes)) === $bytes;

        return match (true) {
            $at(0, "\xFF\xD8\xFF") => 'jpg',
            $at(0, "\x89PNG\r\n\x1A\n") => 'png',
            $at(0, 'GIF87a'), $at(0, 'GIF89a') => 'gif',
            $at(0, 'RIFF') && $at(8, 'WEBP') => 'webp',
            $at(0, 'RIFF') && $at(8, 'WAVE') => 'wav',
            $at(0, 'FORM') && ($at(8, 'AIFF') || $at(8, 'AIFC')) => 'aiff',
            $at(0, 'fLaC') => 'flac',
            $at(0, 'ID3'), $this->isMp3Frame($head) => 'mp3',
            $at(0, 'OggS') => $this->oggType($head),
            $at(4, 'ftyp') => $this->mp4Type($head),
            $at(0, "\x1A\x45\xDF\xA3") => $this->matroskaType($head),
            $at(0, '%PDF-') => 'pdf',
            $at(0, "PK\x03\x04") => $this->officeType($path),
            default => throw new FileRejected('file.unsupported_type', ['types' => implode(', ', FileTypes::extensions())]),
        };
    }

    private function isMp3Frame(string $head): bool
    {
        return strlen($head) >= 2 && ord($head[0]) === 0xFF && (ord($head[1]) & 0xE6) === 0xE2;
    }

    private function oggType(string $head): string
    {
        return match (true) {
            str_contains($head, 'OpusHead') => 'opus',
            str_contains($head, "\x01vorbis"), str_contains($head, "\x7FFLAC") => 'ogg',
            default => throw new FileRejected('file.unsupported_type', ['types' => implode(', ', FileTypes::extensions())]),
        };
    }

    private function mp4Type(string $head): string
    {
        if (!in_array(substr($head, 8, 4), self::MP4_BRANDS, true)) {
            throw new FileRejected('file.unsupported_type', ['types' => implode(', ', FileTypes::extensions())]);
        }

        return 'mp4';
    }

    private function matroskaType(string $head): string
    {
        if (!str_contains(substr($head, 0, 64), 'webm')) {
            throw new FileRejected('file.unsupported_type', ['types' => implode(', ', FileTypes::extensions())]);
        }

        return 'webm';
    }

    /**
     * A ZIP file is accepted only as a Word, Excel or PowerPoint document without macros.
     */
    private function officeType(string $path): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new FileRejected('file.office_unsupported');
        }

        $zip = new \ZipArchive();

        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw new FileRejected('file.damaged');
        }

        try {
            $types = $zip->getFromName('[Content_Types].xml');

            if (!is_string($types)) {
                throw new FileRejected('file.unsupported_type', ['types' => implode(', ', FileTypes::extensions())]);
            }

            if (stripos($types, 'macroEnabled') !== false || stripos($types, 'vbaProject') !== false) {
                throw new FileRejected('file.macros');
            }

            foreach (self::OOXML_TYPES as $contentType => $extension) {
                if (str_contains($types, $contentType)) {
                    return $extension;
                }
            }

            throw new FileRejected('file.unsupported_type', ['types' => implode(', ', FileTypes::extensions())]);
        } finally {
            $zip->close();
        }
    }

    /**
     * Refuses Office documents that contain a VBA project or ActiveX controls, whatever their content types say.
     */
    private function checkOfficeMacros(string $path): void
    {
        $zip = new \ZipArchive();

        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw new FileRejected('file.damaged');
        }

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = strtolower((string) $zip->getNameIndex($index));

                if (str_ends_with($name, 'vbaproject.bin') || str_contains($name, '/activex/')) {
                    throw new FileRejected('file.macros');
                }
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function imageSize(string $path, string $extension): array
    {
        $info = @getimagesize($path);
        $expected = ['jpg' => IMAGETYPE_JPEG, 'png' => IMAGETYPE_PNG, 'gif' => IMAGETYPE_GIF, 'webp' => IMAGETYPE_WEBP][$extension];

        if ($info === false || $info[2] !== $expected || $info[0] < 1 || $info[1] < 1) {
            throw new FileRejected('file.damaged');
        }

        [$width, $height] = $info;

        if ($width > self::MAX_IMAGE_SIDE || $height > self::MAX_IMAGE_SIDE || $width * $height > self::MAX_IMAGE_PIXELS) {
            throw new FileRejected('file.image_too_large', ['max' => self::MAX_IMAGE_SIDE]);
        }

        // With GD, decode the image once: only a real image survives that.
        // Skipped when decoding would not fit into PHP's memory limit.
        if (function_exists('imagecreatefromstring') && self::fitsInMemory($width * $height * 5 + 2 * (int) filesize($path))) {
            $image = @imagecreatefromstring((string) file_get_contents($path));

            if ($image === false) {
                throw new FileRejected('file.damaged');
            }

            unset($image); // GdImage objects are freed when released; imagedestroy() is deprecated since PHP 8.5
        }

        return [$width, $height];
    }

    private static function fitsInMemory(int $bytes): bool
    {
        $limit = (string) ini_get('memory_limit');

        if ($limit === '' || $limit === '-1') {
            return true;
        }

        $value = (int) $limit;
        $value *= match (strtolower(substr($limit, -1))) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        return memory_get_usage() + $bytes < $value;
    }

    /**
     * Refuses PDF files with scripts, launch actions, embedded files or XFA forms.
     *
     * Names may hide letters as "#xx" escapes (/J#61vaScript), so those are
     * decoded first. Content inside compressed object streams cannot be seen
     * this way; that check would need a full PDF parser.
     */
    private function checkPdf(string $path): void
    {
        $content = (string) @file_get_contents($path);
        $decoded = (string) preg_replace_callback('/#([0-9A-Fa-f]{2})/', static fn (array $m): string => chr((int) hexdec($m[1])), $content);

        foreach (self::PDF_ACTIVE as $name) {
            if (preg_match('#' . preg_quote($name, '#') . '(?![A-Za-z0-9])#', $decoded) === 1) {
                throw new FileRejected('file.pdf_active', ['feature' => ltrim($name, '/')]);
            }
        }
    }
}
