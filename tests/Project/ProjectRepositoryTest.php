<?php

declare(strict_types=1);

namespace Campanella\Lite\Tests\Project;

use Campanella\Lite\Exception\InvalidDataException;
use Campanella\Lite\Model\Content;
use Campanella\Lite\Model\ContentTranslation;
use Campanella\Lite\Model\LocalizedText;
use Campanella\Lite\Model\Menu;
use Campanella\Lite\Model\MenuItem;
use Campanella\Lite\Model\Page;
use Campanella\Lite\Model\PageStatus;
use Campanella\Lite\Project\ProjectRepository;
use Campanella\Lite\Storage\FileStore;
use Campanella\Lite\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class ProjectRepositoryTest extends TestCase
{
    use TemporaryDirectory;

    private string $projectDir;
    private ProjectRepository $repository;

    protected function setUp(): void
    {
        $this->projectDir = $this->copyFixture('valid-project');
        $this->repository = new ProjectRepository(new FileStore($this->projectDir));
    }

    public function testLoadsTheWholeProject(): void
    {
        $project = $this->repository->load();

        self::assertSame('Example Ltd.', $project->site->name);
        self::assertSame(1, $project->site->frontPage);
        self::assertSame(['default'], array_keys($project->layouts));
        self::assertSame([1, 2], array_keys($project->pages));
        self::assertSame(['contact-box', 'footer-text', 'intro', 'site-title', 'team'], array_keys($project->contents));
        self::assertSame(['main'], array_keys($project->menus));
        self::assertSame('/about', $project->page(2)?->path('en'));
        self::assertSame("Our small team has been building **custom** websites since 2015.\n", $project->content('intro')?->translation('en')?->body);
    }

    public function testSavedEntitiesLoadBackUnchanged(): void
    {
        $original = $this->repository->load();

        $this->repository->saveSite($original->site);

        foreach ($original->layouts as $layout) {
            $this->repository->saveLayout($layout);
        }

        foreach ($original->pages as $page) {
            $this->repository->savePage($page);
        }

        foreach ($original->contents as $content) {
            $this->repository->saveContent($content);
        }

        foreach ($original->menus as $menu) {
            $this->repository->saveMenu($menu);
        }

        self::assertEquals($original, $this->repository->load());
    }

    public function testSavesAndDeletesAPage(): void
    {
        $page = new Page(3, LocalizedText::of('en', 'Contact'), 'default', PageStatus::Draft, LocalizedText::of('en', '/contact'));
        $this->repository->savePage($page);

        self::assertFileExists($this->projectDir . '/pages/3.yaml');
        self::assertEquals($page, $this->repository->load()->page(3));

        self::assertTrue($this->repository->deletePage(3));
        self::assertNull($this->repository->load()->page(3));
    }

    public function testSavingContentRemovesDroppedTranslations(): void
    {
        $this->repository->saveContent(new Content('news', [
            new ContentTranslation('en', 'News', "English\n"),
            new ContentTranslation('hu', 'Hírek', "Magyar\n"),
        ]));

        self::assertSame(['en', 'hu'], $this->repository->load()->content('news')?->languages());

        $this->repository->saveContent(new Content('news', [new ContentTranslation('en', 'News', "English\n")]));

        self::assertSame(['en'], $this->repository->load()->content('news')?->languages());
        self::assertFileDoesNotExist($this->projectDir . '/content/news/hu.md');
    }

    public function testDeletesContentWithItsDirectory(): void
    {
        self::assertTrue($this->repository->deleteContent('team'));
        self::assertDirectoryDoesNotExist($this->projectDir . '/content/team');
        self::assertNull($this->repository->load()->content('team'));
    }

    public function testSavesAndDeletesAMenuAndALayout(): void
    {
        $menu = new Menu('footer', [new MenuItem(LocalizedText::of('en', 'Imprint'), url: '/imprint')]);
        $this->repository->saveMenu($menu);

        self::assertEquals($menu, $this->repository->load()->menu('footer'));
        self::assertTrue($this->repository->deleteMenu('footer'));

        $layout = $this->repository->load()->layout('default');
        self::assertNotNull($layout);
        self::assertTrue($this->repository->deleteLayout('default'));
        self::assertNull($this->repository->load()->layout('default'));
    }

    public function testWritesReadableYaml(): void
    {
        $this->repository->savePage(new Page(
            id: 7,
            title: LocalizedText::of('en', 'Services'),
            layout: 'default',
            status: PageStatus::Published,
            alias: LocalizedText::of('en', '/services'),
            placements: ['body.main' => ['intro']],
        ));

        $expected = <<<'YAML'
            id: 7
            alias:
              en: /services
            title:
              en: Services
            layout: default
            status: published
            placements:
              body.main:
                - intro

            YAML;

        self::assertSame($expected, file_get_contents($this->projectDir . '/pages/7.yaml'));
    }

    public function testRejectsAPageFileNotNamedAfterItsId(): void
    {
        file_put_contents($this->projectDir . '/pages/about.yaml', "id: 9\ntitle: { en: X }\nlayout: default\n");

        $this->expectException(InvalidDataException::class);
        $this->expectExceptionMessage('page files must be named after the page id');

        $this->repository->load();
    }

    public function testRejectsAPageWhoseIdDiffersFromItsFileName(): void
    {
        file_put_contents($this->projectDir . '/pages/9.yaml', "id: 8\ntitle: { en: X }\nlayout: default\n");

        $this->expectException(InvalidDataException::class);
        $this->expectExceptionMessage('pages/9.yaml, field "id": "8" does not match the file name "9"');

        $this->repository->load();
    }

    public function testRejectsALayoutWhoseNameDiffersFromItsFileName(): void
    {
        file_put_contents($this->projectDir . '/layouts/wide.yaml', "name: narrow\nregions: []\n");

        $this->expectException(InvalidDataException::class);
        $this->expectExceptionMessage('layouts/wide.yaml, field "name"');

        $this->repository->load();
    }

    public function testReportsBrokenContentFiles(): void
    {
        file_put_contents($this->projectDir . '/content/intro/en.md', "No front matter here\n");

        $this->expectException(InvalidDataException::class);
        $this->expectExceptionMessage('content/intro/en.md');

        $this->repository->load();
    }
}
