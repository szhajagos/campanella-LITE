<?php

declare(strict_types=1);

namespace Campanella\Lite\Render\Markdown;

use League\CommonMark\Node\Inline\AbstractInline;

/**
 * A file shown inside a paragraph: an image, a player or a link. The HTML
 * is made (and escaped) by FileEmbedder.
 */
final class EmbeddedFile extends AbstractInline
{
    public function __construct(public readonly string $html)
    {
        parent::__construct();
    }
}
