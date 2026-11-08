<?php

declare(strict_types=1);

namespace Campanella\Lite\Render;

use Campanella\Lite\Model\Page;
use Campanella\Lite\Model\PageStatus;
use Campanella\Lite\Model\Project;
use Campanella\Lite\Routing\Urls;

/**
 * Generates pages ahead of time ("build"), instead of waiting for the first visitor.
 */
final class SiteBuilder
{
    public function __construct(
        private readonly PageRenderer $renderer,
        private readonly PageCache $cache,
    ) {
    }

    /**
     * Generates the published pages (all, or the given ones) in every language.
     *
     * @param list<Page>|null $pages
     *
     * @return int the number of files written
     */
    public function build(Project $project, ?array $pages = null): int
    {
        $count = 0;

        foreach ($this->targets($project, $pages) as [$page, $language]) {
            $this->cache->store(Urls::page($project->site, $page, $language), $this->renderer->render($project, $page, $language));
            $count++;
        }

        return $count;
    }

    /**
     * How many files a build would write.
     *
     * @param list<Page>|null $pages
     */
    public function count(Project $project, ?array $pages = null): int
    {
        return count($this->targets($project, $pages));
    }

    /**
     * @param list<Page>|null $pages
     *
     * @return list<array{0: Page, 1: string}>
     */
    private function targets(Project $project, ?array $pages): array
    {
        $targets = [];

        foreach ($pages ?? array_values($project->pages) as $page) {
            if ($page->status !== PageStatus::Published) {
                continue;
            }

            foreach ($project->site->languages as $language) {
                $targets[] = [$page, $language];
            }
        }

        return $targets;
    }
}
