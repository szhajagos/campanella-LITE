<?php

declare(strict_types=1);

namespace Campanella\Lite\Model;

/**
 * How the body of a content item is written. HTML is planned for a later version.
 */
enum ContentFormat: string
{
    case Markdown = 'markdown';
}
