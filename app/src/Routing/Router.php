<?php

declare(strict_types=1);

namespace Campanella\Lite\Routing;

use Campanella\Lite\Model\Page;
use Campanella\Lite\Model\PageStatus;
use Campanella\Lite\Model\Project;

/**
 * Decides what a requested path shows. Only published pages are visible.
 *
 * Every page has exactly one canonical address per language (see Urls).
 * Other ways to reach it answer with a permanent redirect to that address:
 * /node/{id} of a page with an alias, the alias of the front page, the
 * default language with an explicit prefix, and old aliases kept in
 * redirects.yaml.
 */
final class Router
{
    public function resolve(Project $project, string $path): Route
    {
        $site = $project->site;
        $path = self::normalize($path);

        if ($path === null) {
            return Route::notFound($site->defaultLanguage);
        }

        [$language, $rest] = $this->splitLanguage($project, $path);

        if ($language === $site->defaultLanguage && $rest !== $path) {
            return Route::redirect($rest, $language);
        }

        if ($rest === '/') {
            $front = $site->frontPage === null ? null : $project->page($site->frontPage);

            return $front !== null && self::visible($front)
                ? Route::page($front, $language)
                : Route::notFound($language);
        }

        $page = $this->pageAt($project, $language, $rest);

        if ($page !== null) {
            $canonical = Urls::page($site, $page, $language);

            return $canonical === $path ? Route::page($page, $language) : Route::redirect($canonical, $language);
        }

        $redirect = $project->redirects->find($path);
        $target = $redirect === null ? null : $project->page($redirect['page']);

        if ($redirect !== null && $target !== null && self::visible($target) && in_array($redirect['language'], $site->languages, true)) {
            return Route::redirect(Urls::page($site, $target, $redirect['language']), $redirect['language']);
        }

        return Route::notFound($language);
    }

    /**
     * Cleans a request path: decodes it, drops a trailing slash, and refuses anything odd.
     * Returns null for a path that can never be a page address.
     */
    public static function normalize(string $path): ?string
    {
        $path = rawurldecode($path);

        if ($path === '' || $path[0] !== '/' || preg_match('#//|/\.|[\x00-\x1F\\\\]#', $path) === 1) {
            return null;
        }

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /**
     * @return array{0: string, 1: string} the language and the path without its prefix
     */
    private function splitLanguage(Project $project, string $path): array
    {
        $segments = explode('/', $path, 3);
        $first = $segments[1];

        if (in_array($first, $project->site->languages, true)) {
            return [$first, '/' . ($segments[2] ?? '')];
        }

        return [$project->site->defaultLanguage, $path];
    }

    private function pageAt(Project $project, string $language, string $path): ?Page
    {
        if (preg_match('#^/node/([1-9][0-9]*)$#', $path, $match) === 1) {
            $page = $project->page((int) $match[1]);

            return $page !== null && self::visible($page) ? $page : null;
        }

        foreach ($project->pages as $page) {
            if ($page->alias->get($language) === $path && self::visible($page)) {
                return $page;
            }
        }

        return null;
    }

    private static function visible(Page $page): bool
    {
        return $page->status === PageStatus::Published;
    }
}
