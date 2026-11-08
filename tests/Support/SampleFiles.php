<?php

declare(strict_types=1);

namespace Campanella\Lite\Tests\Support;

/**
 * Small files of every accepted type (and some refused ones) for tests.
 * Images are drawn with GD; the other formats only need a correct signature.
 */
final class SampleFiles
{
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
    }

    /**
     * @param 'png'|'jpg'|'gif'|'webp' $type
     * @param int<1, max>              $width
     * @param int<1, max>              $height
     */
    public function image(string $type = 'png', int $width = 40, int $height = 30, string $name = ''): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 120, 200));
        $path = $this->directory . '/' . ($name !== '' ? $name : 'image-' . bin2hex(random_bytes(3)) . '.' . $type);

        match ($type) {
            'png' => imagepng($image, $path),
            'jpg' => imagejpeg($image, $path),
            'gif' => imagegif($image, $path),
            'webp' => imagewebp($image, $path),
        };

        return $path;
    }

    public function bytes(string $name, string $contents): string
    {
        $path = $this->directory . '/' . $name;
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * @param array<string, string> $entries
     */
    public function zip(string $name, array $entries): string
    {
        $path = $this->directory . '/' . $name;
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        foreach ($entries as $entry => $contents) {
            $zip->addFromString($entry, $contents);
        }

        $zip->close();

        return $path;
    }

    public function office(string $name, string $mainType, bool $vba = false): string
    {
        $entries = ['[Content_Types].xml' => '<?xml version="1.0"?><Types><Override PartName="/main.xml" ContentType="' . $mainType . '"/></Types>', 'main.xml' => '<doc/>'];

        if ($vba) {
            $entries['word/vbaProject.bin'] = 'x';
        }

        return $this->zip($name, $entries);
    }

    public const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml';
    public const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml';
    public const PPTX = 'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml';
    public const DOCM = 'application/vnd.ms-word.document.macroEnabled.main+xml';

    public static function pdf(string $extra = ''): string
    {
        return "%PDF-1.4\n1 0 obj << /Type /Catalog " . $extra . " >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    }
}
