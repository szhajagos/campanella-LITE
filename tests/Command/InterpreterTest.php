<?php

declare(strict_types=1);

namespace Campanella\Lite\Tests\Command;

use Campanella\Lite\Application;
use Campanella\Lite\Command\Result\BatchResult;
use Campanella\Lite\Command\Result\LineStatus;
use Campanella\Lite\Command\Result\Outcome;
use Campanella\Lite\Paths;
use Campanella\Lite\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class InterpreterTest extends TestCase
{
    use TemporaryDirectory;

    private Application $application;
    private string $projectDir;

    protected function setUp(): void
    {
        $root = $this->temporaryDirectory() . '/site';
        mkdir($root);
        rename($this->copyFixture('valid-project'), $root . '/project');
        $this->projectDir = $root . '/project';
        $this->application = new Application(new Paths($root));
    }

    public function testAppliesASuccessfulBatch(): void
    {
        $result = $this->execute("set site.name \"Kovács Kft.\"\nset layout.default.body.main.width 7");

        self::assertSame(Outcome::Applied, $result->outcome);
        self::assertSame(['site.yaml', 'layouts/default.yaml'], $result->changedFiles);
        self::assertStringContainsString("name: 'Kovács Kft.'", $this->file('site.yaml'));
        self::assertStringContainsString('{ name: main, width: 7 }', $this->file('layouts/default.yaml'));
    }

    public function testLaterCommandsSeeEarlierChanges(): void
    {
        $result = $this->execute("set site.name \"New name\"\nget site.name");

        self::assertSame('New name', $result->lines[1]->output?->value);
    }

    public function testAFailingCommandRejectsTheWholeBatch(): void
    {
        $before = $this->file('site.yaml');
        $result = $this->execute("set site.name Changed\nset page.99.title X\nget site.name");

        self::assertSame(Outcome::Rejected, $result->outcome);
        self::assertSame([LineStatus::Ok, LineStatus::Error, LineStatus::Skipped], array_map(static fn ($line) => $line->status, $result->lines));
        self::assertSame('page.not_found', $result->lines[1]->message->key);
        self::assertSame($before, $this->file('site.yaml'));
    }

    public function testASyntaxErrorRunsNothing(): void
    {
        $before = $this->file('site.yaml');
        $result = $this->execute("set site.name Changed\nset x \"open");

        self::assertSame(Outcome::Rejected, $result->outcome);
        self::assertSame(LineStatus::Skipped, $result->lines[0]->status);
        self::assertSame('syntax.unclosed_quote', $result->lines[1]->message->key);
        self::assertSame($before, $this->file('site.yaml'));
    }

    public function testChangesThatBreakRulesAreNotWritten(): void
    {
        $before = $this->file('pages/2.yaml');
        $result = $this->execute('set page.2.layout wide');

        self::assertSame(Outcome::Invalid, $result->outcome);
        self::assertSame(['page.unknown_layout at page.2.layout (layout=wide)'], array_map('strval', $result->violations));
        self::assertSame($before, $this->file('pages/2.yaml'));
    }

    public function testExistingProblemsDoNotBlockUnrelatedChanges(): void
    {
        file_put_contents($this->projectDir . '/pages/2.yaml', str_replace('layout: default', 'layout: missing', $this->file('pages/2.yaml')));

        $result = $this->execute('set site.name "Still works"');

        self::assertSame(Outcome::Applied, $result->outcome);
    }

    public function testDryRunWritesNothing(): void
    {
        $before = $this->file('site.yaml');

        $directive = $this->execute("@dry-run\nset site.name Changed");
        $option = $this->application->interpreter()->run('set site.name Changed', dryRun: true);

        foreach ([$directive, $option] as $result) {
            self::assertSame(Outcome::DryRun, $result->outcome);
            self::assertTrue($result->dryRun);
            self::assertSame(['site.yaml'], $result->changedFiles);
        }

        self::assertSame($before, $this->file('site.yaml'));
    }

    public function testSettingTheSameValueChangesNothing(): void
    {
        $result = $this->execute('set site.name "Example Ltd."');

        self::assertSame(Outcome::Unchanged, $result->outcome);
        self::assertSame([], $result->changedFiles);
    }

    public function testAnEmptyBatchDoesNothing(): void
    {
        self::assertSame('batch.empty', $this->execute("# only a comment\n")->summary->key);
    }

    public function testReportsAnUnreadableProject(): void
    {
        file_put_contents($this->projectDir . '/site.yaml', "name: [broken\n");

        $result = $this->execute('get site.name');

        self::assertSame(Outcome::Failed, $result->outcome);
        self::assertSame('batch.project_unreadable', $result->summary->key);
    }

    public function testGetReturnsValuesAndStructures(): void
    {
        $result = $this->execute("get site.name\nget page./about.title\nget page.2.description.en\nget menu.main.items.2\nget page.1.description");

        self::assertSame('Example Ltd.', $result->lines[0]->output?->value);
        self::assertSame('About us', $result->lines[1]->output?->value);
        self::assertSame('Meet our team.', $result->lines[2]->output?->value);
        self::assertSame(['label' => ['en' => 'About'], 'page' => 2], $result->lines[3]->output?->value);
        self::assertSame('ok.get_empty', $result->lines[4]->message->key);
    }

    public function testGetShowsLayoutsByName(): void
    {
        $result = $this->execute("get layout.default.body.sidebar\nget content.intro.en.title");

        self::assertSame(['width' => 4, 'content' => ['contact-box'], 'menu' => null], $result->lines[0]->output?->value);
        self::assertSame('Introduction', $result->lines[1]->output?->value);
    }

    public function testSetsLanguageDependentValues(): void
    {
        $result = $this->execute("set page./about.title \"About the team\"\nset menu.main.items.2.label Team");

        self::assertSame(Outcome::Applied, $result->outcome);
        self::assertSame('page.2.title.en', $result->lines[0]->message->params['path']);
        self::assertSame('menu.main.items.2.label.en', $result->lines[1]->message->params['path']);
        self::assertStringContainsString("en: 'About the team'", $this->file('pages/2.yaml'));
        self::assertStringContainsString('en: Team', $this->file('menus/main.yaml'));
    }

    public function testSetsAndRemovesOptionalValues(): void
    {
        $result = $this->execute("set page.2.alias \"\"\nset site.front_page 2\nset page.2.status draft\nset layout.default.body.container fluid");

        self::assertSame(Outcome::Applied, $result->outcome, (string) json_encode($result->lines));
        self::assertStringNotContainsString('alias', $this->file('pages/2.yaml'));
        self::assertStringContainsString('status: draft', $this->file('pages/2.yaml'));
        self::assertStringContainsString('front_page: 2', $this->file('site.yaml'));
        self::assertStringContainsString('container: fluid', $this->file('layouts/default.yaml'));
    }

    public function testSetsContentTitlesAndMenuTargets(): void
    {
        $result = $this->execute("set content.intro.en.title Welcome\nset menu.main.items.3.url https://news.example.com");

        self::assertSame(Outcome::Applied, $result->outcome);
        self::assertStringContainsString('title: Welcome', $this->file('content/intro/en.md'));
        self::assertStringContainsString('https://news.example.com', $this->file('menus/main.yaml'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function failingCommands(): iterable
    {
        yield 'unknown root' => ['get pages.2', 'path.unknown_root'];
        yield 'empty segment' => ['get site..name', 'path.invalid'];
        yield 'incomplete' => ['get page', 'path.incomplete'];
        yield 'unknown field' => ['get site.colour', 'path.unknown'];
        yield 'unknown page' => ['get page./contact', 'page.not_found'];
        yield 'unknown layout' => ['get layout.wide', 'layout.not_found'];
        yield 'unknown content' => ['get content.gallery', 'content.not_found'];
        yield 'unknown menu' => ['get menu.footer', 'menu.not_found'];
        yield 'system field' => ['set site.next_page_id 9', 'path.not_settable'];
        yield 'page id' => ['set page.2.id 5', 'path.not_settable'];
        yield 'not a number' => ['set layout.default.body.main.width wide', 'value.not_a_number'];
        yield 'invalid status' => ['set page.2.status hidden', 'value.invalid_choice'];
        yield 'invalid container' => ['set layout.default.body.container full', 'value.invalid_choice'];
        yield 'unknown region' => ['set layout.default.hero.container fluid', 'layout.region_not_found'];
        yield 'unknown column' => ['set layout.default.body.center.width 6', 'layout.column_not_found'];
        yield 'missing translation' => ['set content.intro.hu.title Bevezető', 'content.translation_not_found'];
        yield 'content body' => ['set content.intro.en.body Text', 'path.not_settable'];
        yield 'unknown menu item' => ['set menu.main.items.9.label X', 'menu.item_not_found'];
        yield 'zero menu item' => ['set menu.main.items.0.label X', 'menu.item_not_found'];
        yield 'unknown list type' => ['list users', 'list.unknown_type'];
        yield 'help for unknown command' => ['help publish', 'command.unknown'];
        yield 'unquoted spaces' => ['set site.name Example Ltd.', 'command.too_many_arguments'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failingCommands')]
    public function testReportsFailingCommands(string $input, string $key): void
    {
        $result = $this->execute($input);

        self::assertSame(Outcome::Rejected, $result->outcome);
        self::assertSame($key, $result->lines[0]->message->key);
    }

    public function testListsEntities(): void
    {
        $result = $this->execute("list pages\nlist content\nlist layouts\nlist menus\nlist languages");

        /** @var array{columns: list<string>, rows: list<list<string>>} $pages */
        $pages = $result->lines[0]->output?->value;
        self::assertSame(['id', 'path', 'status', 'layout', 'title'], $pages['columns']);
        self::assertSame(['1', '/node/1 (/)', 'published', 'default', 'Home'], $pages['rows'][0]);
        self::assertSame(['2', '/about', 'published', 'default', 'About us'], $pages['rows'][1]);
        self::assertSame(2, $result->lines[0]->message->params['count']);
        self::assertSame(5, $result->lines[1]->message->params['count']);
        self::assertSame(1, $result->lines[2]->message->params['count']);
    }

    public function testHelpListsAndDescribesCommands(): void
    {
        $result = $this->execute("help\nhelp set");

        self::assertSame(Outcome::Unchanged, $result->outcome);
        self::assertSame(count($this->application->registry()->all()), $result->lines[0]->message->params['count']);
        self::assertSame('set', $result->lines[1]->message->params['command']);
    }

    private function execute(string $input): BatchResult
    {
        return $this->application->interpreter()->run($input);
    }

    private function file(string $relative): string
    {
        return (string) file_get_contents($this->projectDir . '/' . $relative);
    }
}
