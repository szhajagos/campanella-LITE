<?php

declare(strict_types=1);

namespace Campanella\Lite\Tests\Command;

use Campanella\Lite\Application;
use Campanella\Lite\Command\Format\JsonFormatter;
use Campanella\Lite\Command\Format\TextFormatter;
use Campanella\Lite\Command\Output;
use Campanella\Lite\Command\Result\BatchResult;
use Campanella\Lite\Command\Result\LineResult;
use Campanella\Lite\Command\Result\LineStatus;
use Campanella\Lite\Command\Result\Outcome;
use Campanella\Lite\I18n\Message;
use Campanella\Lite\I18n\Translator;
use Campanella\Lite\Validation\Violation;
use PHPUnit\Framework\TestCase;

final class FormatterTest extends TestCase
{
    private Translator $translator;

    protected function setUp(): void
    {
        $this->translator = new Translator(Application::translationsDirectory());
    }

    public function testFormatsLinesAndSummaryAsText(): void
    {
        $result = new BatchResult(Outcome::Rejected, [
            new LineResult(1, 'set site.name X', LineStatus::Ok, new Message('ok.set', ['path' => 'site.name', 'value' => 'X'])),
            new LineResult(3, 'get page.9', LineStatus::Error, new Message('page.not_found', ['page' => '9'])),
            new LineResult(4, 'get site', LineStatus::Skipped, new Message('line.skipped')),
        ], new Message('batch.rejected'));

        $expected = <<<'TXT'
            [1] Ok. site.name = X
            [3] Error: No page with the id or alias "9".
            [4] Skipped: an earlier line failed.
            → Batch not executed, nothing was changed.

            TXT;

        self::assertSame($expected, (new TextFormatter($this->translator))->format($result));
    }

    public function testFormatsTablesStructuresAndViolations(): void
    {
        $result = new BatchResult(Outcome::Invalid, [
            new LineResult(1, 'list x', LineStatus::Ok, new Message('ok.list', ['type' => 'pages', 'count' => 2]), Output::table(['id', 'title'], [['1', 'Home'], ['12', new Message('help.examples')]])),
            new LineResult(2, 'get x', LineStatus::Ok, new Message('ok.get_structure', ['path' => 'page.2']), Output::value(['id' => 2])),
        ], new Message('batch.invalid'), violations: [new Violation('page.unknown_layout', 'page.2.layout', ['layout' => 'wide'])]);

        $expected = <<<'TXT'
            [1] Ok. pages: 2
                id  title
                1   Home
                12  Examples:
            [2] Ok. page.2:
                id: 2
            The changes would break these rules:
                - The layout "wide" does not exist. (page.2.layout)
            → Batch not executed, nothing was changed.

            TXT;

        self::assertSame($expected, (new TextFormatter($this->translator))->format($result));
    }

    public function testFormatsJsonWithKeysAndTexts(): void
    {
        $result = new BatchResult(Outcome::Applied, [
            new LineResult(1, 'set site.name X', LineStatus::Ok, new Message('ok.set', ['path' => 'site.name', 'value' => 'X']), null, 'set'),
            new LineResult(2, 'help', LineStatus::Ok, new Message('ok.help_index', ['count' => 1]), Output::table(['usage', 'summary'], [['get <path>', new Message('help.get')]]), 'help'),
        ], new Message('batch.applied', ['count' => 1, 'files' => 'site.yaml']), changedFiles: ['site.yaml']);

        $data = json_decode((new JsonFormatter($this->translator))->format($result), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        self::assertTrue($data['ok']);
        self::assertSame('applied', $data['outcome']);
        self::assertSame(['key' => 'batch.applied', 'params' => ['count' => 1, 'files' => 'site.yaml'], 'text' => 'Done. Files changed (1): site.yaml'], $data['summary']);
        self::assertSame('set', $data['lines'][0]['command']);
        self::assertSame('ok.set', $data['lines'][0]['message']['key']);
        self::assertSame('site.name = X', $data['lines'][0]['message']['text']);
        self::assertNull($data['lines'][0]['output']);
        self::assertSame('Shows a value or a whole entity by its path.', $data['lines'][1]['output']['value']['rows'][0][1]);
        self::assertSame(['site.yaml'], $data['changed_files']);
    }

    public function testEmptyParamsAreJsonObjects(): void
    {
        $result = new BatchResult(Outcome::Unchanged, [], new Message('batch.empty'));

        self::assertStringContainsString('"params": {}', (new JsonFormatter($this->translator))->format($result));
    }
}
