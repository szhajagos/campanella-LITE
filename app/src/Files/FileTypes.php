<?php

declare(strict_types=1);

namespace Campanella\Lite\Files;

/**
 * The kinds of files the repository accepts.
 *
 * Only formats that cannot carry scripts or macros are on the list: no
 * HTML, SVG or PHP, no macro-enabled or legacy Office formats.
 */
final class FileTypes
{
    public const IMAGE = 'image';
    public const AUDIO = 'audio';
    public const VIDEO = 'video';
    public const DOCUMENT = 'document';

    public const KINDS = [self::IMAGE, self::AUDIO, self::VIDEO, self::DOCUMENT];

    public const MAX_SIZE = 10 * 1024 * 1024;
    public const MAX_VIDEO_SIZE = 100 * 1024 * 1024;

    /** extension => [MIME type, kind] */
    private const TYPES = [
        'jpg' => ['image/jpeg', self::IMAGE],
        'png' => ['image/png', self::IMAGE],
        'webp' => ['image/webp', self::IMAGE],
        'gif' => ['image/gif', self::IMAGE],
        'pdf' => ['application/pdf', self::DOCUMENT],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', self::DOCUMENT],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', self::DOCUMENT],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', self::DOCUMENT],
        'wav' => ['audio/wav', self::AUDIO],
        'aiff' => ['audio/aiff', self::AUDIO],
        'flac' => ['audio/flac', self::AUDIO],
        'mp3' => ['audio/mpeg', self::AUDIO],
        'opus' => ['audio/ogg', self::AUDIO],
        'ogg' => ['audio/ogg', self::AUDIO],
        'mp4' => ['video/mp4', self::VIDEO],
        'webm' => ['video/webm', self::VIDEO],
    ];

    /** @return list<string> */
    public static function extensions(): array
    {
        return array_keys(self::TYPES);
    }

    public static function isKnown(string $extension): bool
    {
        return isset(self::TYPES[$extension]);
    }

    public static function mimeTypeOf(string $extension): string
    {
        return self::TYPES[$extension][0] ?? 'application/octet-stream';
    }

    public static function kindOf(string $extension): string
    {
        return self::TYPES[$extension][1] ?? self::DOCUMENT;
    }

    public static function maxSizeOf(string $extension): int
    {
        return self::kindOf($extension) === self::VIDEO ? self::MAX_VIDEO_SIZE : self::MAX_SIZE;
    }
}
