<?php

declare(strict_types=1);

namespace Campanella\Lite\Files;

/**
 * Makes smaller and WebP versions of images with PHP's GD extension.
 *
 * Re-encoding drops every piece of metadata (EXIF, GPS position, camera
 * details); the orientation a camera recorded is applied first, so photos
 * taken upright stay upright.
 */
final class ImageResizer
{
    private const JPEG_QUALITY = 82;
    private const WEBP_QUALITY = 80;

    public static function available(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagescale');
    }

    public static function webpAvailable(): bool
    {
        return self::available() && function_exists('imagewebp');
    }

    /**
     * Whether the image can be decoded within PHP's memory limit.
     */
    public static function canResize(int $width, int $height, int $size): bool
    {
        return self::available() && FileInspector::fitsInMemory($width * $height * 10 + 2 * $size);
    }

    /**
     * Writes the image scaled to the given width (never larger than it is)
     * in the given format: jpg, png, gif or webp.
     */
    public function resize(string $source, string $target, int $width, string $format): void
    {
        $image = @imagecreatefromstring((string) file_get_contents($source));

        if ($image === false) {
            throw new FileRejected('file.damaged');
        }

        $image = $this->orient($image, $source);

        if ($width < imagesx($image)) {
            $height = max(1, (int) round(imagesy($image) * $width / imagesx($image)));
            $scaled = imagescale($image, max(1, $width), $height, IMG_BICUBIC);

            if ($scaled !== false) {
                $image = $scaled;
            }
        }

        if ($format === 'png' || $format === 'webp') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        $written = match ($format) {
            'jpg' => imagejpeg($image, $target, self::JPEG_QUALITY),
            'png' => imagepng($image, $target, 6),
            'gif' => imagegif($image, $target),
            'webp' => imagewebp($image, $target, self::WEBP_QUALITY),
            default => false,
        };

        if (!$written) {
            throw new FileRejected('file.damaged');
        }
    }

    /**
     * The width and height a viewer shows: swapped for photos a camera stored turned by 90°.
     *
     * @return array{0: int, 1: int}
     */
    public static function displaySize(string $path, int $width, int $height): array
    {
        return in_array(self::orientation($path), [5, 6, 7, 8], true) ? [$height, $width] : [$width, $height];
    }

    private function orient(\GdImage $image, string $path): \GdImage
    {
        $rotated = match (self::orientation($path)) {
            3, 4 => imagerotate($image, 180, 0),
            5, 6 => imagerotate($image, -90, 0),
            7, 8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated === false ? $image : $rotated;
    }

    private static function orientation(string $path): int
    {
        if (!function_exists('exif_read_data')) {
            return 1;
        }

        $head = (string) @file_get_contents($path, false, null, 0, 3);

        if ($head !== "\xFF\xD8\xFF") {
            return 1;
        }

        $exif = @exif_read_data($path);

        return is_array($exif) && is_int($exif['Orientation'] ?? null) ? $exif['Orientation'] : 1;
    }
}
