<?php

declare(strict_types=1);

namespace Campanella\Lite\Routing;

use Campanella\Lite\Model\Page;

/**
 * What a requested address leads to: a page, a redirect, or nothing.
 */
final class Route
{
    public const PAGE = 'page';
    public const REDIRECT = 'redirect';
    public const NOT_FOUND = 'not_found';

    /**
     * @param self::PAGE|self::REDIRECT|self::NOT_FOUND $type
     */
    private function __construct(
        public readonly string $type,
        public readonly string $language,
        public readonly ?Page $page = null,
        public readonly ?string $location = null,
    ) {
    }

    public static function page(Page $page, string $language): self
    {
        return new self(self::PAGE, $language, $page);
    }

    public static function redirect(string $location, string $language): self
    {
        return new self(self::REDIRECT, $language, location: $location);
    }

    public static function notFound(string $language): self
    {
        return new self(self::NOT_FOUND, $language);
    }
}
