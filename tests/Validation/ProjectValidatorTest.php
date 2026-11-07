<?php

declare(strict_types=1);

namespace Campanella\Lite\Tests\Validation;

use Campanella\Lite\Model\Content;
use Campanella\Lite\Model\ContentTranslation;
use Campanella\Lite\Model\Layout;
use Campanella\Lite\Model\Menu;
use Campanella\Lite\Model\Page;
use Campanella\Lite\Model\Project;
use Campanella\Lite\Model\Site;
use Campanella\Lite\Project\ProjectRepository;
use Campanella\Lite\Storage\FileStore;
use Campanella\Lite\Validation\ProjectValidator;
use Campanella\Lite\Validation\Violation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProjectValidatorTest extends TestCase
{
    public function testTheSampleProjectIsValid(): void
    {
        $project = (new ProjectRepository(new FileStore(dirname(__DIR__) . '/Fixtures/valid-project')))->load();

        self::assertSame([], $this->violations($project));
    }

    public function testTheBaselineOfTheseTestsIsValid(): void
    {
        self::assertSame([], $this->violations($this->project()));
    }

    /**
     * Each case changes one part of a valid project and names the violation it must produce.
     *
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidProjects(): iterable
    {
        // Site
        yield 'no languages' => [['site' => ['languages' => []]], 'site.no_languages at site.languages'];
        yield 'invalid language code' => [['site' => ['languages' => ['en', 'Hungarian']]], 'site.invalid_language at site.languages (language=Hungarian)'];
        yield 'duplicate language' => [['site' => ['languages' => ['en', 'en']]], 'site.duplicate_language at site.languages (language=en)'];
        yield 'default language not enabled' => [['site' => ['default_language' => 'de']], 'site.unknown_default_language at site.default_language (language=de)'];
        yield 'unknown default layout' => [['site' => ['default_layout' => 'wide']], 'site.unknown_default_layout at site.default_layout (layout=wide)'];
        yield 'unknown front page' => [['site' => ['front_page' => 99]], 'site.unknown_front_page at site.front_page (page=99)'];
        yield 'next page id in use' => [['site' => ['next_page_id' => 2]], 'site.next_page_id_in_use at site.next_page_id (next=2, highest=2)'];

        // Layouts
        yield 'invalid layout name' => [['layout' => ['name' => 'Default']], 'name.invalid at layout.Default (name=Default, max=64)'];
        yield 'invalid region name' => [['layout.regions.0' => ['name' => 'main body']], 'name.invalid at layout.default.main body (name=main body, max=64)'];
        yield 'duplicate region' => [['layout.regions.1' => ['name' => 'header']], 'layout.duplicate_region at layout.default.header (region=header)'];
        yield 'duplicate column' => [['layout.regions.1.columns.1' => ['name' => 'main', 'width' => 4]], 'layout.duplicate_column at layout.default.body.main (column=main)'];
        yield 'zero width' => [['layout.regions.1.columns.1' => ['width' => 0]], 'layout.invalid_width at layout.default.body.sidebar.width (width=0, max=12)'];
        yield 'too wide column' => [['layout.regions.0.columns.0' => ['width' => 13]], 'layout.invalid_width at layout.default.header.brand.width (width=13, max=12)'];
        yield 'region wider than the grid' => [['layout.regions.1.columns.1' => ['width' => 5]], 'layout.region_too_wide at layout.default.body (width=13, max=12)'];
        yield 'content and menu in one column' => [['layout.regions.0.columns.0' => ['menu' => 'main']], 'layout.content_and_menu at layout.default.header.brand'];
        yield 'unknown layout content' => [['layout.regions.0.columns.0' => ['content' => ['logo']]], 'layout.unknown_content at layout.default.header.brand.content (content=logo)'];
        yield 'unknown layout menu' => [['layout.regions.1.columns.1' => ['content' => [], 'menu' => 'side']], 'layout.unknown_menu at layout.default.body.sidebar.menu (menu=side)'];
        yield 'layout title in unknown language' => [['layout' => ['title' => ['de' => 'Standard']]], 'text.unknown_language at layout.default.title.de (language=de)'];

        // Pages
        yield 'unknown page layout' => [['page' => ['layout' => 'wide']], 'page.unknown_layout at page.2.layout (layout=wide)'];
        yield 'missing title in the default language' => [['page' => ['title' => ['hu' => 'Rólunk']]], 'text.missing_default_language at page.2.title.en (language=en)'];
        yield 'blank title' => [['page' => ['title' => ['en' => '  ']]], 'text.missing_default_language at page.2.title.en (language=en)'];
        yield 'alias in unknown language' => [['page' => ['alias' => ['en' => '/about', 'de' => '/ueber-uns']]], 'text.unknown_language at page.2.alias.de (language=de)'];
        yield 'alias with accents' => [['page' => ['alias' => ['en' => '/rólunk']]], 'page.invalid_alias at page.2.alias.en (alias=/rólunk)'];
        yield 'alias with uppercase' => [['page' => ['alias' => ['en' => '/About']]], 'page.invalid_alias at page.2.alias.en (alias=/About)'];
        yield 'alias with trailing slash' => [['page' => ['alias' => ['en' => '/about/']]], 'page.invalid_alias at page.2.alias.en (alias=/about/)'];
        yield 'alias without leading slash' => [['page' => ['alias' => ['en' => 'about']]], 'page.invalid_alias at page.2.alias.en (alias=about)'];
        yield 'root alias' => [['page' => ['alias' => ['en' => '/']]], 'page.invalid_alias at page.2.alias.en (alias=/)'];
        yield 'reserved alias admin' => [['page' => ['alias' => ['en' => '/admin']]], 'page.reserved_alias at page.2.alias.en (alias=/admin, segment=admin)'];
        yield 'reserved alias below node' => [['page' => ['alias' => ['en' => '/node/7']]], 'page.reserved_alias at page.2.alias.en (alias=/node/7, segment=node)'];
        yield 'alias equal to a language code' => [['page' => ['alias' => ['en' => '/en/about']]], 'page.reserved_alias at page.2.alias.en (alias=/en/about, segment=en)'];
        yield 'duplicate alias' => [['page1' => ['alias' => ['en' => '/about']]], 'page.duplicate_alias at page.2.alias.en (alias=/about, page=1)'];
        yield 'placement in unknown column' => [['page' => ['placements' => ['body.center' => ['intro']]]], 'page.unknown_column at page.2.placements.body.center (column=body.center, layout=default)'];
        yield 'placement of unknown content' => [['page' => ['placements' => ['body.main' => ['gallery']]]], 'page.unknown_content at page.2.placements.body.main (content=gallery)'];

        // Content
        yield 'invalid content name' => [['content' => ['name' => 'Intro_Text']], 'name.invalid at content.Intro_Text (name=Intro_Text, max=64)'];
        yield 'content without translations' => [['content' => ['translations' => []]], 'content.no_translations at content.intro'];
        yield 'content in unknown language' => [['content' => ['translations' => [['de', 'Einleitung']]]], 'text.unknown_language at content.intro.de (language=de)'];
        yield 'content without title' => [['content' => ['translations' => [['en', '']]]], 'content.missing_title at content.intro.en.title'];

        // Menus
        yield 'invalid menu name' => [['menu' => ['name' => 'main menu']], 'name.invalid at menu.main menu (name=main menu, max=64)'];
        yield 'item without target' => [['menu.items.0' => ['label' => ['en' => 'Nowhere']]], 'menu.invalid_target at menu.main.items.1'];
        yield 'item with two targets' => [['menu.items.0' => ['label' => ['en' => 'Both'], 'page' => 1, 'url' => '/x']], 'menu.invalid_target at menu.main.items.1'];
        yield 'item to unknown page' => [['menu.items.0' => ['label' => ['en' => 'Gone'], 'page' => 42]], 'menu.unknown_page at menu.main.items.1.page (page=42)'];
        yield 'item with script url' => [['menu.items.0' => ['label' => ['en' => 'Bad'], 'url' => 'javascript:alert(1)']], 'menu.invalid_url at menu.main.items.1.url (url=javascript:alert(1))'];
        yield 'item without default label' => [['menu.items.0' => ['label' => [], 'page' => 1]], 'text.missing_default_language at menu.main.items.1.label.en (language=en)'];
        yield 'invalid child item' => [['menu.items.0' => ['label' => ['en' => 'Parent'], 'page' => 1, 'children' => [['label' => ['en' => 'Child'], 'page' => 77]]]], 'menu.unknown_page at menu.main.items.1.children.1.page (page=77)'];
    }

    /**
     * @param array<string, mixed> $changes
     */
    #[DataProvider('invalidProjects')]
    public function testReportsTheViolation(array $changes, string $expected): void
    {
        self::assertContains($expected, $this->violations($this->project($changes)));
    }

    public function testAcceptsValidMenuUrls(): void
    {
        foreach (['https://example.com/a?b=c', 'http://example.com', 'mailto:info@example.com', '/about', '/'] as $url) {
            $project = $this->project(['menu.items.0' => ['label' => ['en' => 'Link'], 'url' => $url]]);

            self::assertSame([], $this->violations($project), $url);
        }
    }

    public function testAcceptsMultiSegmentAliases(): void
    {
        self::assertSame([], $this->violations($this->project(['page' => ['alias' => ['en' => '/services/web-design']]])));
    }

    public function testReportsEveryViolationAtOnce(): void
    {
        $violations = $this->violations($this->project([
            'site' => ['default_layout' => 'wide'],
            'page' => ['layout' => 'wide'],
        ]));

        self::assertCount(2, $violations);
    }

    public function testNameRules(): void
    {
        self::assertTrue(ProjectValidator::isValidName('footer-text'));
        self::assertTrue(ProjectValidator::isValidName('col2'));
        self::assertFalse(ProjectValidator::isValidName('-leading'));
        self::assertFalse(ProjectValidator::isValidName('double--hyphen'));
        self::assertFalse(ProjectValidator::isValidName(''));
        self::assertFalse(ProjectValidator::isValidName(str_repeat('a', 65)));
    }

    /**
     * @return list<string>
     */
    private function violations(Project $project): array
    {
        return array_map(static fn (Violation $violation): string => (string) $violation, (new ProjectValidator())->validate($project));
    }

    /**
     * Builds a small valid project, then applies the changes. Keys name what to change:
     * "site", "layout", "layout.regions.N", "layout.regions.N.columns.M", "page" (page 2),
     * "page1", "content" (intro), "menu", "menu.items.N". Values are merged over the original.
     *
     * @param array<string, mixed> $changes
     */
    private function project(array $changes = []): Project
    {
        $site = [
            'name' => 'Test', 'languages' => ['en'], 'default_language' => 'en',
            'default_layout' => 'default', 'next_page_id' => 3, 'front_page' => 1,
        ];
        $layout = [
            'name' => 'default',
            'regions' => [
                ['name' => 'header', 'columns' => [['name' => 'brand', 'width' => 12, 'content' => ['intro']]]],
                ['name' => 'body', 'columns' => [['name' => 'main', 'width' => 8], ['name' => 'sidebar', 'width' => 4]]],
            ],
        ];
        $page1 = ['id' => 1, 'title' => ['en' => 'Home'], 'layout' => 'default', 'status' => 'published'];
        $page2 = ['id' => 2, 'alias' => ['en' => '/about'], 'title' => ['en' => 'About'], 'layout' => 'default', 'placements' => ['body.main' => ['intro']]];
        $content = ['name' => 'intro', 'translations' => [['en', 'Introduction']]];
        $menu = ['name' => 'main', 'items' => [['label' => ['en' => 'Home'], 'page' => 1]]];

        foreach ($changes as $target => $change) {
            self::assertIsArray($change);

            match (true) {
                $target === 'site' => $site = array_replace($site, $change),
                $target === 'layout' => $layout = array_replace($layout, $change),
                $target === 'page' => $page2 = array_replace($page2, $change),
                $target === 'page1' => $page1 = array_replace($page1, $change),
                $target === 'content' => $content = array_replace($content, $change),
                $target === 'menu' => $menu = array_replace($menu, $change),
                str_starts_with($target, 'layout.') => $layout = self::replaceAt($layout, substr($target, 7), $change),
                str_starts_with($target, 'menu.') => $menu = self::replaceAt($menu, substr($target, 5), $change, true),
                default => self::fail('Unknown change target ' . $target),
            };
        }

        /** @var list<array{string, string}> $translations */
        $translations = $content['translations'];
        self::assertIsString($content['name']);

        return new Project(
            Site::fromArray($site, 'site.yaml'),
            [Layout::fromArray($layout, 'layout')],
            [Page::fromArray($page1, 'page1'), Page::fromArray($page2, 'page2')],
            [new Content($content['name'], array_map(
                static fn (array $t): ContentTranslation => new ContentTranslation($t[0], $t[1], "Text\n"),
                $translations,
            ))],
            [Menu::fromArray($menu, 'menu')],
        );
    }

    /**
     * Changes the array at a dotted path ("regions.1.columns.0"): keys present in $change win,
     * or with $whole the array is replaced entirely (used for menu items, whose targets exclude
     * each other). A change for a path that does not exist yet adds it.
     *
     * @param array<mixed> $data
     * @param array<mixed> $change
     *
     * @return array<mixed>
     */
    private static function replaceAt(array $data, string $path, array $change, bool $whole = false): array
    {
        $keys = explode('.', $path);
        $key = array_shift($keys);
        $key = ctype_digit($key) ? (int) $key : $key;

        if ($keys === []) {
            $current = $data[$key] ?? [];
            self::assertIsArray($current);
            $data[$key] = $whole ? $change : array_replace($current, $change);

            return $data;
        }

        $child = $data[$key] ?? [];
        self::assertIsArray($child);
        $data[$key] = self::replaceAt($child, implode('.', $keys), $change, $whole);

        return $data;
    }
}
