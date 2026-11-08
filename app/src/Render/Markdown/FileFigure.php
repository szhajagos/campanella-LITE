<?php

declare(strict_types=1);

namespace Campanella\Lite\Render\Markdown;

use League\CommonMark\Node\Block\AbstractBlock;

/**
 * An image with a caption, alone in its paragraph: shown as a <figure>.
 */
final class FileFigure extends AbstractBlock
{
    public function __construct(public readonly string $html)
    {
        parent::__construct();
    }
}
