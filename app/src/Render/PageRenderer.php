<?php

declare(strict_types=1);

namespace Campanella\Lite\Render;

use Campanella\Lite\I18n\Message;
use Campanella\Lite\I18n\Translator;
use Campanella\Lite\Model\Column;
use Campanella\Lite\Model\Content;
use Campanella\Lite\Model\Layout;
use Campanella\Lite\Model\LocalizedText;
use Campanella\Lite\Model\MenuItem;
use Campanella\Lite\Model\Page;
use Campanella\Lite\Model\PageStatus;
use Campanella\Lite\Model\Project;
use Campanella\Lite\Model\Region;
use Campanella\Lite\Routing\Urls;
use League\CommonMark\CommonMarkConverter;
use League\CommonMark\ConverterInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Renders pages of the website to HTML with the site's theme.
 *
 * The theme only receives plain arrays (the "view"), never model objects,
 * so templates cannot reach into the project or call methods on it.
 * Texts missing in a language fall back to the default language.
 */
final class PageRenderer
{
    public const ASSETS = '/assets';

    private readonly ConverterInterface $markdown;

    /** @var array<string, Environment> theme name => Twig environment */
    private array $environments = [];

    public function __construct(
        private readonly string $themesDirectory,
        private readonly Translator $translator,
        private readonly string|false $cacheDirectory = false,
    ) {
        $this->markdown = new CommonMarkConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 50,
        ]);
    }

    public function render(Project $project, Page $page, string $language): string
    {
        return $this->twig($project->site->theme)->render('page.html.twig', $this->pageView($project, $page, $language));
    }

    public function renderNotFound(Project $project, string $language): string
    {
        return $this->twig($project->site->theme)->render('404.html.twig', [
            'site' => $this->siteView($project, $language),
            'home' => Urls::prefix($project->site, $language) . '/',
            'assets' => self::ASSETS,
        ]);
    }

    /**
     * Everything the page template receives.
     *
     * @return array<string, mixed>
     */
    public function pageView(Project $project, Page $page, string $language): array
    {
        $site = $project->site;
        $layout = $project->layout($page->layout);
        $baseUrl = rtrim($site->baseUrl, '/');
        $path = Urls::page($site, $page, $language);

        $alternates = [];

        foreach ($site->languages as $alternate) {
            $alternates[] = [
                'language' => $alternate,
                'url' => Urls::page($site, $page, $alternate),
                'absolute_url' => $baseUrl . Urls::page($site, $page, $alternate),
                'current' => $alternate === $language,
            ];
        }

        return [
            'site' => $this->siteView($project, $language),
            'page' => [
                'id' => $page->id,
                'title' => self::text($page->title, $language, $site->defaultLanguage),
                'description' => self::text($page->description, $language, $site->defaultLanguage),
                'path' => $path,
                'canonical' => $baseUrl === '' ? null : $baseUrl . $path,
                'is_front' => $site->frontPage === $page->id,
            ],
            'regions' => $layout === null ? [] : $this->regionsView($project, $layout, $page, $language),
            'alternates' => $alternates,
            'assets' => self::ASSETS,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function siteView(Project $project, string $language): array
    {
        return [
            'name' => $project->site->name,
            'language' => $language,
            'languages' => $project->site->languages,
            'theme' => $project->site->theme,
            'home' => Urls::prefix($project->site, $language) . '/',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function regionsView(Project $project, Layout $layout, Page $page, string $language): array
    {
        return array_map(fn (Region $region): array => [
            'name' => $region->name,
            'container' => $region->container->value === 'fluid' ? 'container-fluid' : 'container',
            'columns' => array_map(
                fn (Column $column): array => $this->columnView($project, $region, $column, $page, $language),
                $region->columns,
            ),
        ], $layout->regions);
    }

    /**
     * @return array<string, mixed>
     */
    private function columnView(Project $project, Region $region, Column $column, Page $page, string $language): array
    {
        $names = $page->placements[$region->name . '.' . $column->name] ?? $column->content;
        $blocks = [];

        foreach ($names as $name) {
            $content = $project->content($name);

            if ($content !== null) {
                $blocks[] = ['name' => $name, 'html' => $this->html($content, $language, $project->site->defaultLanguage)];
            }
        }

        $menu = $column->menu === null ? null : $project->menu($column->menu);

        return [
            'name' => $column->name,
            'width' => $column->width,
            'blocks' => $blocks,
            'menu' => $menu === null ? null : [
                'name' => $menu->name,
                'items' => $this->menuItems($project, $menu->items, $page, $language),
            ],
        ];
    }

    /**
     * Menu items as links; items that point to unpublished or missing pages are left out.
     *
     * @param list<MenuItem> $items
     *
     * @return list<array<string, mixed>>
     */
    private function menuItems(Project $project, array $items, Page $current, string $language): array
    {
        $view = [];

        foreach ($items as $item) {
            $url = $item->url;
            $isCurrent = false;

            if ($item->page !== null) {
                $target = $project->page($item->page);

                if ($target === null || $target->status !== PageStatus::Published) {
                    continue;
                }

                $url = Urls::page($project->site, $target, $language);
                $isCurrent = $target->id === $current->id;
            }

            $children = $this->menuItems($project, $item->children, $current, $language);

            $view[] = [
                'label' => self::text($item->label, $language, $project->site->defaultLanguage),
                'url' => $url,
                'current' => $isCurrent,
                'active' => $isCurrent || in_array(true, array_column($children, 'active'), true),
                'children' => $children,
            ];
        }

        return $view;
    }

    private function html(Content $content, string $language, string $defaultLanguage): string
    {
        $translation = $content->translation($language)
            ?? $content->translation($defaultLanguage)
            ?? (array_values($content->translations)[0] ?? null);

        return $translation === null ? '' : $this->markdown->convert($translation->body)->getContent();
    }

    private static function text(LocalizedText $text, string $language, string $defaultLanguage): string
    {
        return $text->get($language) ?? $text->get($defaultLanguage) ?? '';
    }

    private function twig(string $theme): Environment
    {
        if (isset($this->environments[$theme])) {
            return $this->environments[$theme];
        }

        $directory = $this->themesDirectory . '/' . $theme . '/templates';

        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $theme) !== 1 || !is_dir($directory)) {
            $directory = $this->themesDirectory . '/default/templates';
        }

        $twig = new Environment(new FilesystemLoader($directory), [
            'autoescape' => 'html',
            'strict_variables' => true,
            'cache' => $this->cacheDirectory,
            'auto_reload' => true,
        ]);

        $twig->addFunction(new TwigFunction(
            't',
            fn (string $key, string $language, array $params = []): string => $this->translator->translate(new Message('theme.' . $key, $params), $language),
        ));

        return $this->environments[$theme] = $twig;
    }
}
