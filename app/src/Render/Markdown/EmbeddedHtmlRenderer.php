<?php

declare(strict_types=1);

namespace Campanella\Lite\Render\Markdown;

use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

/**
 * Outputs the HTML that FileEmbedder made for a file.
 */
final class EmbeddedHtmlRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        return $node instanceof EmbeddedFile || $node instanceof FileFigure ? $node->html : '';
    }
}
