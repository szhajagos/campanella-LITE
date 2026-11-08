<?php

declare(strict_types=1);

namespace Campanella\Lite\Http;

/**
 * One file of a multipart upload, as PHP received it.
 */
final class UploadedFile
{
    /**
     * @param string $name      the file name on the uploader's computer (not trusted)
     * @param string $temporary where PHP stored it
     * @param int    $error     one of PHP's UPLOAD_ERR_* codes
     */
    public function __construct(
        public readonly string $name,
        public readonly string $temporary,
        public readonly int $error = UPLOAD_ERR_OK,
    ) {
    }

    /**
     * The files of one form field ("files[]") from $_FILES. Entries that PHP
     * did not receive as uploads are left out.
     *
     * @param array<mixed> $files
     *
     * @return list<self>
     */
    public static function fromGlobals(array $files, string $field): array
    {
        $entry = $files[$field] ?? null;

        if (!is_array($entry)) {
            return [];
        }

        $names = (array) ($entry['name'] ?? []);
        $temporaries = (array) ($entry['tmp_name'] ?? []);
        $errors = (array) ($entry['error'] ?? []);
        $uploads = [];

        foreach ($names as $index => $name) {
            $temporary = $temporaries[$index] ?? '';
            $error = $errors[$index] ?? UPLOAD_ERR_NO_FILE;

            if (!is_string($name) || !is_string($temporary) || !is_int($error) || $error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($error === UPLOAD_ERR_OK && !is_uploaded_file($temporary)) {
                continue;
            }

            $uploads[] = new self($name, $temporary, $error);
        }

        return $uploads;
    }
}
