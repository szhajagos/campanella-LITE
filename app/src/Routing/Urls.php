<?php

declare(strict_types=1);

namespace Campanella\Lite\Routing;

use Campanella\Lite\Model\Page;
use Campanella\Lite\Model\Site;

/**
 * The public addresses of pages.
 *
 * The default language has no prefix; every other language is prefixed
 * with its code:
 *
 *     /                 front page, default language
 *     /about            alias in the default language
 *     /node/7           page without an alias
 *     /hu/              front page in Hungarian
 *     /hu/rolunk        Hungarian alias
 *     /hu/node/7        Hungarian version of a page without a Hungarian alias
 *
 * Language codes and "admin", "assets", "node" are reserved as first path
 * segments of aliases, so these addresses never collide.
 */
final class Urls
{
    /**
     * The canonical address of a page in a language.
     */
    public static function page(Site $site, Page $page, string $language): string
    {
        $prefix = self::prefix($site, $language);

        if ($site->frontPage === $page->id) {
            return $prefix . '/';
        }

        return $prefix . ($page->alias->get($language) ?? $page->systemPath());
    }

    /**
     * The address of an alias in a language, e.g. ("hu", "/rolunk") → "/hu/rolunk".
     */
    public static function alias(Site $site, string $language, string $alias): string
    {
        return self::prefix($site, $language) . $alias;
    }

    public static function prefix(Site $site, string $language): string
    {
        return $language === $site->defaultLanguage ? '' : '/' . $language;
    }

    /**
     * The file a generated page is stored in, relative to the web root.
     */
    public static function file(string $path): string
    {
        $trimmed = trim($path, '/');

        return $trimmed === '' ? 'index.html' : $trimmed . '/index.html';
    }
}
