<?php

declare(strict_types=1);

namespace Campanella\Lite\Tests\Command;

use Campanella\Lite\Command\Parser\BatchParser;
use Campanella\Lite\Command\Parser\ParsedCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BatchParserTest extends TestCase
{
    private BatchParser $parser;

    protected function setUp(): void
    {
        $this->parser = new BatchParser();
    }

    public function testSplitsWordsAndOptions(): void
    {
        $command = $this->single('page create --title="About us" --alias=/about --force');

        self::assertSame(['page', 'create'], $command->words);
        self::assertSame(['title' => 'About us', 'alias' => '/about', 'force' => true], $command->options);
        self::assertNull($command->text);
        self::assertSame(1, $command->line);
    }

    public function testQuotedWordsKeepSpacesAndEscapes(): void
    {
        $command = $this->single('set site.name "Kovács \"Best\" Kft.\\\\ \n\tok"');

        self::assertSame(['set', 'site.name', "Kovács \"Best\" Kft.\\ \n\tok"], $command->words);
    }

    public function testUnknownEscapesStayLiteral(): void
    {
        self::assertSame(['set', 'x', 'C:\\path'], $this->single('set x "C:\\path"')->words);
    }

    public function testEmptyQuotesGiveAnEmptyWord(): void
    {
        self::assertSame(['set', 'page.2.description', ''], $this->single('set page.2.description ""')->words);
    }

    public function testQuotedOptionLookalikesAreWords(): void
    {
        self::assertSame(['set', 'x', '--not-an-option'], $this->single('set x "--not-an-option"')->words);
    }

    public function testCommentsAreIgnored(): void
    {
        $batch = $this->parser->parse("# heading\n\nget site.name # trailing comment\nset x \"a # b\"\n");

        self::assertCount(2, $batch->commands);
        self::assertSame(['get', 'site.name'], $batch->commands[0]->words);
        self::assertSame(3, $batch->commands[0]->line);
        self::assertSame(['set', 'x', 'a # b'], $batch->commands[1]->words);
    }

    public function testAHashInsideAWordIsNotAComment(): void
    {
        self::assertSame(['set', 'x', 'a#b'], $this->single('set x a#b')->words);
    }

    public function testReadsMultiLineText(): void
    {
        $batch = $this->parser->parse("content edit intro <<<\nFirst line\n\n  indented\n>>>\nget site.name");

        self::assertTrue($batch->isValid());
        self::assertCount(2, $batch->commands);
        self::assertSame(['content', 'edit', 'intro'], $batch->commands[0]->words);
        self::assertSame("First line\n\n  indented\n", $batch->commands[0]->text);
        self::assertSame(6, $batch->commands[1]->line);
    }

    public function testTextMayBeEmptyAndContainHashes(): void
    {
        $batch = $this->parser->parse("content edit a <<<\n>>>\ncontent edit b <<<\n# Heading\n>>>");

        self::assertSame('', $batch->commands[0]->text);
        self::assertSame("# Heading\n", $batch->commands[1]->text);
    }

    public function testAQuotedTextMarkerIsAWord(): void
    {
        $command = $this->single('set x "<<<"');

        self::assertSame(['set', 'x', '<<<'], $command->words);
        self::assertNull($command->text);
    }

    public function testAcceptsWindowsLineEndings(): void
    {
        $batch = $this->parser->parse("get a\r\nget b\r\n");

        self::assertCount(2, $batch->commands);
        self::assertSame(['get', 'b'], $batch->commands[1]->words);
    }

    public function testDryRunDirective(): void
    {
        $batch = $this->parser->parse("# check first\n@dry-run\nget site.name");

        self::assertTrue($batch->dryRun);
        self::assertTrue($batch->isValid());
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function syntaxErrors(): iterable
    {
        yield 'unclosed quote' => ['set x "open', 1, 'syntax.unclosed_quote'];
        yield 'unclosed text' => ["get a\ncontent edit x <<<\nbody", 2, 'syntax.unclosed_text'];
        yield 'stray text end' => ["get a\n>>>", 2, 'syntax.unexpected_text_end'];
        yield 'unknown directive' => ['@preview', 1, 'syntax.unknown_directive'];
        yield 'directive after command' => ["get a\n@dry-run", 2, 'syntax.directive_after_command'];
        yield 'invalid option name' => ['page create --Title=x', 1, 'syntax.invalid_option'];
        yield 'empty option name' => ['page create --=x', 1, 'syntax.invalid_option'];
        yield 'duplicate option' => ['page create --title=a --title=b', 1, 'syntax.duplicate_option'];
        yield 'options only' => ['--title=x', 1, 'syntax.missing_command'];
    }

    #[DataProvider('syntaxErrors')]
    public function testReportsSyntaxErrors(string $input, int $line, string $key): void
    {
        $batch = $this->parser->parse($input);

        self::assertFalse($batch->isValid());
        self::assertSame($line, $batch->errors[0]->line);
        self::assertSame($key, $batch->errors[0]->message->key);
    }

    public function testReportsEverySyntaxErrorAndKeepsParsing(): void
    {
        $batch = $this->parser->parse("set x \"a\nget ok\n@later");

        self::assertCount(2, $batch->errors);
        self::assertCount(1, $batch->commands);
    }

    private function single(string $input): ParsedCommand
    {
        $batch = $this->parser->parse($input);

        self::assertTrue($batch->isValid(), 'Unexpected syntax error');
        self::assertCount(1, $batch->commands);

        return $batch->commands[0];
    }
}
