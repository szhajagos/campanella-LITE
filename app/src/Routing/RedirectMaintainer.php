<?php

declare(strict_types=1);

namespace Campanella\Lite\Routing;

use Campanella\Lite\Model\Project;

/**
 * Keeps redirects.yaml in step with the pages after a batch:
 *
 * - an alias that changed or was removed becomes a redirect to its page;
 * - a redirect is dropped when its page is deleted, when its language is
 *   disabled, or when its address is a page's current address again
 *   (another page took the alias, or the page got it back).
 */
final class RedirectMaintainer
{
    public function update(Project $before, Project $after): Project
    {
        $redirects = $after->redirects;

        foreach ($before->pages as $old) {
            $new = $after->page($old->id);

            if ($new === null) {
                continue;
            }

            foreach ($old->alias->toArray() as $language => $alias) {
                if ($new->alias->get($language) !== $alias && in_array($language, $after->site->languages, true)) {
                    $redirects = $redirects->with(Urls::alias($before->site, $language, $alias), $old->id, $language);
                }
            }
        }

        $current = [];

        foreach ($after->pages as $page) {
            foreach ($after->site->languages as $language) {
                $current[Urls::page($after->site, $page, $language)] = true;

                $alias = $page->alias->get($language);

                if ($alias !== null) {
                    $current[Urls::alias($after->site, $language, $alias)] = true;
                }
            }
        }

        $redirects = $redirects->filter(
            static fn (string $path, array $target): bool => !isset($current[$path])
                && $after->page($target['page']) !== null
                && in_array($target['language'], $after->site->languages, true),
        );

        return $after->withRedirects($redirects);
    }
}
