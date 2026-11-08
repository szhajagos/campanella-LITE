<?php

declare(strict_types=1);

namespace Campanella\Lite\Render\Markdown;

use Campanella\Lite\Files\FileReferences;
use Campanella\Lite\Files\FileTypes;
use Campanella\Lite\Files\PublicFiles;
use Campanella\Lite\Model\File;
use Campanella\Lite\Model\Project;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Node;
use League\CommonMark\Node\StringContainerInterface;

/**
 * Turns references to files in Markdown into what the website shows:
 *
 *     ![Alt text](file:12)              an image in every published size (and WebP)
 *     ![Alt text](file:12 "Caption")    alone in a paragraph: a figure with a caption
 *     ![](file:14)                      audio and video: a player; other files: a link
 *     [Price list](file:13)             a link to the file
 *
 * Without alt text, an image uses the file's description in the page's
 * language. References to archived or missing files show nothing (images,
 * players) or only their text (links).
 */
final class FileEmbedder
{
    /** How wide an image is shown: the content column at most, the screen on small devices. */
    private const SIZES = '(min-width: 1400px) 1320px, (min-width: 1200px) 1140px, 100vw';

    private ?Project $project = null;
    private string $language = '';

    public function __construct(private readonly PublicFiles $files)
    {
    }

    /** The project and language of the content converted next. */
    public function use(Project $project, string $language): void
    {
        $this->project = $project;
        $this->language = $language;
    }

    public function onDocumentParsed(DocumentParsedEvent $event): void
    {
        $nodes = [];

        foreach ($event->getDocument()->iterator() as $node) {
            if (($node instanceof Image || $node instanceof Link) && str_starts_with($node->getUrl(), 'file:')) {
                $nodes[] = $node;
            }
        }

        foreach ($nodes as $node) {
            $file = $this->file($node->getUrl());

            if ($node instanceof Image) {
                $this->embed($node, $file);
            } else {
                $this->link($node, $file);
            }
        }
    }

    private function file(string $url): ?File
    {
        $ids = FileReferences::in('](' . $url . ')');
        $file = $ids === [] ? null : $this->project?->file($ids[0]);

        return $file === null || $file->isArchived() ? null : $file;
    }

    private function link(Link $link, ?File $file): void
    {
        if ($file !== null) {
            $link->setUrl($this->files->linkUrl($file));

            return;
        }

        foreach (iterator_to_array($link->children(), false) as $child) {
            $link->insertBefore($child);
        }

        $link->detach();
    }

    private function embed(Image $image, ?File $file): void
    {
        if ($file === null) {
            $image->detach();

            return;
        }

        $alt = trim(self::text($image));
        $caption = trim((string) $image->getTitle());

        $html = match ($file->kind()) {
            FileTypes::IMAGE => $this->picture($file, $alt !== '' ? $alt : $this->description($file), $caption),
            FileTypes::AUDIO => $this->player('audio', $file, $alt, 'none'),
            FileTypes::VIDEO => $this->player('video', $file, $alt, 'metadata'),
            default => sprintf('<a href="%s">%s</a>', self::e($this->files->linkUrl($file)), self::e($alt !== '' ? $alt : $file->filename)),
        };

        $parent = $image->parent();

        if ($file->kind() === FileTypes::IMAGE && $caption !== '' && $parent instanceof Paragraph && self::isOnlyChild($image)) {
            $parent->replaceWith(new FileFigure(sprintf(
                '<figure class="figure file-figure">%s<figcaption class="figure-caption">%s</figcaption></figure>',
                $html,
                self::e($caption),
            )));

            return;
        }

        $image->replaceWith(new EmbeddedFile($html));
    }

    private function picture(File $file, string $alt, string $caption): string
    {
        $widths = $this->files->widths($file);
        $width = $widths === [] ? $file->width : max($widths);
        $height = $file->width !== null && $file->height !== null && $width !== null
            ? max(1, (int) round($file->height * $width / $file->width))
            : $file->height;

        $attributes = [
            'src' => $this->files->url($file, $widths === [] ? null : max($widths)),
            'alt' => $alt,
            'width' => $width === null ? null : (string) $width,
            'height' => $height === null ? null : (string) $height,
            'class' => 'img-fluid',
            'loading' => 'lazy',
            'decoding' => 'async',
            'title' => $caption !== '' ? $caption : null,
        ];

        if (count($widths) > 1) {
            $attributes['srcset'] = $this->srcset($file, $widths, false);
            $attributes['sizes'] = self::SIZES;
        }

        $img = '<img' . self::attributes($attributes) . '>';

        if (!$this->files->hasWebp($file)) {
            return $img;
        }

        return sprintf(
            '<picture><source type="image/webp" srcset="%s"%s>%s</picture>',
            self::e($this->srcset($file, $widths, true)),
            count($widths) > 1 ? ' sizes="' . self::e(self::SIZES) . '"' : '',
            $img,
        );
    }

    /**
     * @param list<int> $widths
     */
    private function srcset(File $file, array $widths, bool $webp): string
    {
        return implode(', ', array_map(fn (int $width): string => $this->files->url($file, $width, $webp) . ' ' . $width . 'w', $widths));
    }

    private function player(string $element, File $file, string $label, string $preload): string
    {
        $url = $this->files->linkUrl($file);

        return sprintf(
            '<%1$s controls preload="%2$s" src="%3$s"%4$s%5$s><a href="%3$s">%6$s</a></%1$s>',
            $element,
            $preload,
            self::e($url),
            $label !== '' ? ' aria-label="' . self::e($label) . '"' : '',
            $element === 'video' ? ' class="w-100"' : '',
            self::e($label !== '' ? $label : $file->filename),
        );
    }

    private function description(File $file): string
    {
        $default = $this->project?->site->defaultLanguage ?? '';

        return $file->description->get($this->language) ?? $file->description->get($default) ?? '';
    }

    /** The plain text of the image description: words, code and (escaped later) markup alike. */
    private static function text(Node $node): string
    {
        $text = '';

        foreach ($node->iterator() as $child) {
            if ($child instanceof StringContainerInterface) {
                $text .= $child->getLiteral();
            }
        }

        return $text;
    }

    private static function isOnlyChild(Node $node): bool
    {
        return $node->previous() === null && $node->next() === null;
    }

    /**
     * @param array<string, string|null> $attributes null values are left out
     */
    private static function attributes(array $attributes): string
    {
        $html = '';

        foreach ($attributes as $name => $value) {
            if ($value !== null) {
                $html .= ' ' . $name . '="' . self::e($value) . '"';
            }
        }

        return $html;
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
