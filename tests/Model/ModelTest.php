<?php

declare(strict_types=1);

namespace Campanella\Lite\Tests\Model;

use Campanella\Lite\Exception\InvalidDataException;
use Campanella\Lite\Model\ContainerType;
use Campanella\Lite\Model\ContentTranslation;
use Campanella\Lite\Model\Layout;
use Campanella\Lite\Model\LocalizedText;
use Campanella\Lite\Model\Menu;
use Campanella\Lite\Model\Page;
use Campanella\Lite\Model\PageStatus;
use Campanella\Lite\Model\Site;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModelTest extends TestCase
{
    public function testLocalizedTextIsImmutable(): void
    {
        $title = LocalizedText::of('en', 'About us');
        $translated = $title->with('hu', 'Rólunk');

        self::assertSame(['en' => 'About us'], $title->toArray());
        self::assertSame(['en' => 'About us', 'hu' => 'Rólunk'], $translated->toArray());
        self::assertSame(['en'], $translated->without('hu')->languages());
        self::assertNull($title->get('hu'));
        self::assertTrue(LocalizedText::empty()->isEmpty());
    }

    public function testSiteRoundTripsAndAllocatesPageIds(): void
    {
        $data = [
            'name' => 'Example',
            'base_url' => 'https://example.com',
            'languages' => ['en'],
            'default_language' => 'en',
            'default_layout' => 'default',
            'theme' => 'default',
            'next_page_id' => 5,
            'front_page' => 1,
        ];
        $site = Site::fromArray($data, 'site.yaml');

        self::assertSame($data, $site->toArray());

        [$updated, $id] = $site->allocatePageId();

        self::assertSame(5, $id);
        self::assertSame(6, $updated->nextPageId);
        self::assertSame(5, $site->nextPageId);
        self::assertSame(1, $updated->frontPage);
    }

    public function testSiteDefaults(): void
    {
        $site = Site::fromArray(['name' => 'X', 'languages' => ['en'], 'default_language' => 'en', 'default_layout' => 'default'], 'site.yaml');

        self::assertSame('', $site->baseUrl);
        self::assertSame('default', $site->theme);
        self::assertSame(1, $site->nextPageId);
        self::assertNull($site->frontPage);
    }

    public function testLayoutRoundTripsAndFindsColumns(): void
    {
        $data = [
            'name' => 'default',
            'title' => ['en' => 'Default'],
            'regions' => [
                ['name' => 'nav', 'container' => 'fluid', 'columns' => [['name' => 'menu', 'width' => 12, 'menu' => 'main']]],
                ['name' => 'body', 'columns' => [
                    ['name' => 'main', 'width' => 8],
                    ['name' => 'sidebar', 'width' => 4, 'content' => ['contact']],
                ]],
            ],
        ];
        $layout = Layout::fromArray($data, 'layouts/default.yaml');

        self::assertSame($data, $layout->toArray());
        self::assertSame(['nav.menu', 'body.main', 'body.sidebar'], $layout->columnPaths());
        self::assertSame(4, $layout->column('body.sidebar')?->width);
        self::assertNull($layout->column('body.missing'));
        self::assertNull($layout->column('body'));
        self::assertSame(ContainerType::Fluid, $layout->region('nav')?->container);
        self::assertSame(12, $layout->region('body')?->totalWidth());
    }

    public function testPageRoundTripsAndKnowsItsPath(): void
    {
        $data = [
            'id' => 2,
            'alias' => ['en' => '/about'],
            'title' => ['en' => 'About us'],
            'description' => ['en' => 'Meet our team.'],
            'layout' => 'default',
            'status' => 'published',
            'placements' => ['body.main' => ['intro', 'team']],
        ];
        $page = Page::fromArray($data, 'pages/2.yaml');

        self::assertSame($data, $page->toArray());
        self::assertSame(PageStatus::Published, $page->status);
        self::assertSame('/about', $page->path('en'));
        self::assertSame('/node/2', $page->path('hu'));
    }

    public function testANewPageIsADraft(): void
    {
        $page = Page::fromArray(['id' => 3, 'title' => ['en' => 'New'], 'layout' => 'default'], 'pages/3.yaml');

        self::assertSame(PageStatus::Draft, $page->status);
        self::assertSame('/node/3', $page->path('en'));
    }

    public function testMenuRoundTrips(): void
    {
        $data = [
            'name' => 'main',
            'items' => [
                ['label' => ['en' => 'Home'], 'page' => 1],
                ['label' => ['en' => 'More'], 'url' => 'https://example.com', 'children' => [
                    ['label' => ['en' => 'About'], 'page' => 2],
                ]],
            ],
        ];

        self::assertSame($data, Menu::fromArray($data, 'menus/main.yaml')->toArray());
    }

    public function testContentTranslationFromDocument(): void
    {
        $translation = ContentTranslation::fromDocument('en', ['title' => 'Intro', 'format' => 'markdown'], "Text\n", 'content/intro/en.md');

        self::assertSame('en', $translation->language);
        self::assertSame('Intro', $translation->title);
        self::assertSame("Text\n", $translation->body);
        self::assertSame(['title' => 'Intro', 'format' => 'markdown'], $translation->frontMatter());
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function invalidPages(): iterable
    {
        $valid = ['id' => 2, 'title' => ['en' => 'About'], 'layout' => 'default'];

        yield 'missing id' => [array_diff_key($valid, ['id' => true]), 'field "id": this field is required'];
        yield 'id as text' => [['id' => 'two'] + $valid, 'field "id": expected a whole number'];
        yield 'title as plain text' => [['title' => 'About'] + $valid, 'field "title": expected a mapping'];
        yield 'title value not text' => [['title' => ['en' => ['x']]] + $valid, 'field "title.en": expected text'];
        yield 'unknown status' => [['status' => 'hidden'] + $valid, 'field "status": expected "draft" or "published"'];
        yield 'unknown key' => [['colour' => 'red'] + $valid, 'field "colour": unknown key'];
        yield 'placement not a list' => [['placements' => ['body.main' => 'intro']] + $valid, 'field "placements.body.main": expected a list'];
    }

    /**
     * @param array<mixed> $data
     */
    #[DataProvider('invalidPages')]
    public function testRejectsStructurallyInvalidPages(array $data, string $message): void
    {
        $this->expectException(InvalidDataException::class);
        $this->expectExceptionMessage('pages/2.yaml, ' . $message);

        Page::fromArray($data, 'pages/2.yaml');
    }

    public function testRejectsAnInvalidColumnWithItsFullPath(): void
    {
        $this->expectException(InvalidDataException::class);
        $this->expectExceptionMessage('field "regions.1.columns.2.width": expected a whole number');

        Layout::fromArray([
            'name' => 'default',
            'regions' => [['name' => 'body', 'columns' => [['name' => 'a', 'width' => 6], ['name' => 'b', 'width' => 'wide']]]],
        ], 'layouts/default.yaml');
    }

    public function testRejectsAnUnknownContainer(): void
    {
        $this->expectException(InvalidDataException::class);
        $this->expectExceptionMessage('expected "fixed" or "fluid"');

        Layout::fromArray(['name' => 'x', 'regions' => [['name' => 'r', 'container' => 'wide', 'columns' => []]]], 'layouts/x.yaml');
    }

    public function testRejectsAnUnknownContentFormat(): void
    {
        $this->expectException(InvalidDataException::class);
        $this->expectExceptionMessage('expected "markdown"');

        ContentTranslation::fromDocument('en', ['title' => 'X', 'format' => 'html'], '', 'content/x/en.md');
    }
}
