<?php

declare(strict_types=1);

namespace Campanella\Lite\Model;

use Campanella\Lite\Files\FileTypes;

/**
 * A file in the file repository: an image, a document, a sound or a video.
 * Its manifest is stored in project/files/manifest/{id}.yaml, the file
 * itself under project/files/store/ with a random name.
 *
 * The id is assigned when the file is uploaded and never changes or gets
 * reused. The file name is the one shown to people; it can be changed
 * without touching the stored file or the references to it ("file:12").
 */
final class File
{
    public const MAX_FILENAME_LENGTH = 120;

    public readonly LocalizedText $description;

    public function __construct(
        public readonly int $id,
        public readonly string $filename,
        public readonly string $storedFilename,
        public readonly string $mimeType,
        public readonly string $extension,
        public readonly int $size,
        public readonly string $uploadedAt,
        public readonly string $uploadedBy,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        ?LocalizedText $description = null,
        public readonly ?string $archivedAt = null,
    ) {
        $this->description = $description ?? LocalizedText::empty();
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data, string $source): self
    {
        $fields = new Fields($data, $source);
        $fields->allowOnly([
            'id', 'filename', 'stored_filename', 'mime_type', 'extension', 'size', 'width', 'height',
            'description', 'uploaded_at', 'uploaded_by', 'archived_at',
        ]);

        return new self(
            id: $fields->int('id'),
            filename: $fields->string('filename'),
            storedFilename: $fields->string('stored_filename'),
            mimeType: $fields->string('mime_type'),
            extension: $fields->string('extension'),
            size: $fields->int('size'),
            uploadedAt: $fields->string('uploaded_at'),
            uploadedBy: $fields->string('uploaded_by'),
            width: $fields->optionalInt('width'),
            height: $fields->optionalInt('height'),
            description: $fields->localized('description'),
            archivedAt: $fields->optionalString('archived_at'),
        );
    }

    /**
     * Returns a copy with the given fields replaced. Pass $archivedAt = '' to restore an archived file.
     */
    public function with(?string $filename = null, ?LocalizedText $description = null, ?string $archivedAt = null): self
    {
        return new self(
            $this->id,
            $filename ?? $this->filename,
            $this->storedFilename,
            $this->mimeType,
            $this->extension,
            $this->size,
            $this->uploadedAt,
            $this->uploadedBy,
            $this->width,
            $this->height,
            $description ?? $this->description,
            $archivedAt === null ? $this->archivedAt : ($archivedAt === '' ? null : $archivedAt),
        );
    }

    /**
     * A shown file name: not empty, at most 120 characters, no folders or
     * control characters, not hidden, ending with the file's extension.
     */
    public static function isValidFilename(string $filename, string $extension): bool
    {
        return mb_check_encoding($filename, 'UTF-8')
            && mb_strlen($filename) <= self::MAX_FILENAME_LENGTH
            && preg_match('#[/\\\\\x00-\x1F\x7F]#', $filename) !== 1
            && !str_starts_with($filename, '.')
            && str_ends_with($filename, '.' . $extension)
            && strlen($filename) > strlen($extension) + 1;
    }

    public function isArchived(): bool
    {
        return $this->archivedAt !== null;
    }

    /** image, audio, video or document */
    public function kind(): string
    {
        return FileTypes::kindOf($this->extension);
    }

    /** How content refers to the file: "file:12". */
    public function reference(): string
    {
        return 'file:' . $this->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'filename' => $this->filename,
            'stored_filename' => $this->storedFilename,
            'mime_type' => $this->mimeType,
            'extension' => $this->extension,
            'size' => $this->size,
        ];

        if ($this->width !== null) {
            $data['width'] = $this->width;
        }

        if ($this->height !== null) {
            $data['height'] = $this->height;
        }

        if (!$this->description->isEmpty()) {
            $data['description'] = $this->description->toArray();
        }

        $data['uploaded_at'] = $this->uploadedAt;
        $data['uploaded_by'] = $this->uploadedBy;

        if ($this->archivedAt !== null) {
            $data['archived_at'] = $this->archivedAt;
        }

        return $data;
    }
}
