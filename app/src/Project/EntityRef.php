<?php

declare(strict_types=1);

namespace Campanella\Lite\Project;

/**
 * Names one stored entity: the site, a layout, a page, a content item or a menu.
 */
final class EntityRef
{
    public const SITE = 'site';
    public const LAYOUT = 'layout';
    public const PAGE = 'page';
    public const CONTENT = 'content';
    public const MENU = 'menu';
    public const REDIRECTS = 'redirects';

    /**
     * @param self::SITE|self::LAYOUT|self::PAGE|self::CONTENT|self::MENU|self::REDIRECTS $type
     */
    private function __construct(
        public readonly string $type,
        public readonly string $id,
    ) {
    }

    public static function site(): self
    {
        return new self(self::SITE, '');
    }

    public static function layout(string $name): self
    {
        return new self(self::LAYOUT, $name);
    }

    public static function page(int $id): self
    {
        return new self(self::PAGE, (string) $id);
    }

    public static function content(string $name): self
    {
        return new self(self::CONTENT, $name);
    }

    public static function menu(string $name): self
    {
        return new self(self::MENU, $name);
    }

    public static function redirects(): self
    {
        return new self(self::REDIRECTS, '');
    }

    public function key(): string
    {
        return $this->type . ':' . $this->id;
    }
}
