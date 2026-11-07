<?php

declare(strict_types=1);

namespace Campanella\Lite\Tests\Command;

use PHPUnit\Framework\Attributes\DataProvider;

final class ContentCommandsTest extends CommandTestCase
{
    public function testCreatesContentWithText(): void
    {
        $project = $this->apply("content create news --title=\"News\" <<<\nFirst **news**.\n>>>");
        $translation = $project->content('news')?->translation('en');

        self::assertNotNull($translation);
        self::assertSame('News', $translation->title);
        self::assertSame("First **news**.\n", $translation->body);
        self::assertFileExists($this->projectDir . '/content/news/en.md');
    }

    public function testCreatesContentWithoutText(): void
    {
        self::assertSame('', $this->apply('content create empty --title=Empty')->content('empty')?->translation('en')?->body);
    }

    public function testEditsTheText(): void
    {
        $translation = $this->apply("content edit intro <<<\nNew text.\n>>>")->content('intro')?->translation('en');

        self::assertNotNull($translation);
        self::assertSame("New text.\n", $translation->body);
        self::assertSame('Introduction', $translation->title);
    }

    public function testTranslatesAndUpdatesATranslation(): void
    {
        $intro = $this->apply("language add hu\ncontent translate intro hu --title=Bemutatkozás <<<\nMagyar szöveg.\n>>>")->content('intro');
        self::assertNotNull($intro);
        self::assertSame(['en', 'hu'], $intro->languages());
        self::assertSame("Magyar szöveg.\n", $intro->translation('hu')?->body);

        $hungarian = $this->apply('content translate intro hu --title="Rólunk röviden"')->content('intro')?->translation('hu');
        self::assertNotNull($hungarian);
        self::assertSame('Rólunk röviden', $hungarian->title);
        self::assertSame("Magyar szöveg.\n", $hungarian->body);

        $project = $this->apply("content edit intro --lang=hu <<<\nÚj.\n>>>");
        self::assertSame("Új.\n", $project->content('intro')?->translation('hu')?->body);
    }

    public function testDeletesATranslation(): void
    {
        $this->apply("language add hu\ncontent translate intro hu --title=Bevezető");
        $project = $this->apply('content delete intro --lang=hu');

        self::assertSame(['en'], $project->content('intro')?->languages());
        self::assertFileDoesNotExist($this->projectDir . '/content/intro/hu.md');
    }

    public function testDeletesUnusedContent(): void
    {
        $this->apply('content create temp --title=Temp');
        $project = $this->apply('content delete temp');

        self::assertNull($project->content('temp'));
        self::assertDirectoryDoesNotExist($this->projectDir . '/content/temp');
    }

    public function testForcedDeleteRemovesEveryPlacement(): void
    {
        $project = $this->apply('content delete intro --force');

        self::assertNull($project->content('intro'));
        self::assertSame([], $project->page(1)?->placements['body.main']);
        self::assertSame(['team'], $project->page(2)?->placements['body.main']);
    }

    public function testForcedDeleteRemovesLayoutDefaults(): void
    {
        $project = $this->apply('content delete contact-box --force');

        self::assertSame([], $project->layout('default')?->column('body.sidebar')?->content);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function failures(): iterable
    {
        yield 'create existing' => ['content create intro --title=X', 'content.exists'];
        yield 'create invalid name' => ['content create Intro_Text --title=X', 'value.invalid_name'];
        yield 'create without title' => ['content create news', 'command.missing_option'];
        yield 'edit without text' => ['content edit intro', 'command.text_required'];
        yield 'edit missing translation' => ["language add hu\ncontent edit intro --lang=hu <<<\nx\n>>>", 'content.translation_not_found'];
        yield 'translate into disabled language' => ['content translate intro de --title=X', 'language.not_enabled'];
        yield 'new translation without title' => ["language add hu\ncontent translate intro hu", 'command.missing_option'];
        yield 'delete used content' => ['content delete intro', 'content.in_use'];
        yield 'delete only translation' => ['content delete intro --lang=en', 'content.last_translation'];
        yield 'delete missing translation' => ['content delete intro --lang=hu', 'content.translation_not_found'];
    }

    #[DataProvider('failures')]
    public function testFailures(string $input, string $key): void
    {
        $this->assertFails($input, $key);
    }
}
