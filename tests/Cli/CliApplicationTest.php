<?php

declare(strict_types=1);

namespace Campanella\Lite\Tests\Cli;

use Campanella\Lite\Cli\CliApplication;
use Campanella\Lite\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class CliApplicationTest extends TestCase
{
    use TemporaryDirectory;

    private string $root;

    protected function setUp(): void
    {
        $this->root = $this->temporaryDirectory() . '/site';
        mkdir($this->root);
        rename($this->copyFixture('valid-project'), $this->root . '/project');
    }

    public function testRunsCommandsFromTheOption(): void
    {
        [$code, $out] = $this->cli(['-e', 'get site.name']);

        self::assertSame(CliApplication::EXIT_OK, $code);
        self::assertSame("[1] Ok. site.name = Example Ltd.\n→ Done. Nothing was changed.\n", $out);
    }

    public function testReadsCommandsFromStandardInputAndPrintsJson(): void
    {
        [$code, $out] = $this->cli(['--json'], "set site.name Changed\n");

        self::assertSame(CliApplication::EXIT_OK, $code);
        self::assertStringContainsString('"outcome": "applied"', $out);
        self::assertStringContainsString('name: Changed', (string) file_get_contents($this->root . '/project/site.yaml'));
    }

    public function testReadsCommandsFromAFile(): void
    {
        $file = $this->temporaryDirectory() . '/batch.txt';
        file_put_contents($file, "@dry-run\nset site.name Changed\n");

        [$code, $out] = $this->cli([$file]);

        self::assertSame(CliApplication::EXIT_OK, $code);
        self::assertStringContainsString('Dry run, nothing was changed.', $out);
    }

    public function testDryRunOption(): void
    {
        [$code, $out] = $this->cli(['--dry-run', '-e', 'set site.name Changed']);

        self::assertSame(CliApplication::EXIT_OK, $code);
        self::assertStringContainsString('Dry run', $out);
        self::assertStringContainsString('name: Example Ltd.', (string) file_get_contents($this->root . '/project/site.yaml'));
    }

    public function testARejectedBatchExitsWithOne(): void
    {
        [$code] = $this->cli(['-e', 'get page.99']);

        self::assertSame(CliApplication::EXIT_REJECTED, $code);
    }

    public function testUsageErrorsExitWithTwo(): void
    {
        [$code, , $err] = $this->cli(['--unknown']);
        self::assertSame(CliApplication::EXIT_USAGE, $code);
        self::assertStringContainsString('Unknown option "--unknown"', $err);

        [$code, , $err] = $this->cli(['--root=' . $this->temporaryDirectory(), '-e', 'get site.name']);
        self::assertSame(CliApplication::EXIT_USAGE, $code);
        self::assertStringContainsString('No project directory', $err);

        [$code] = $this->cli(['--locale=../x', '-e', 'get site.name']);
        self::assertSame(CliApplication::EXIT_USAGE, $code);
    }

    public function testAnswersInHungarian(): void
    {
        [, $out] = $this->cli(['--locale=hu', '-e', 'get site.name']);

        self::assertSame("[1] Rendben. site.name = Example Ltd.\n→ Kész. Semmi nem változott.\n", $out);
    }

    public function testShowsHelp(): void
    {
        [$code, $out] = $this->cli(['--help']);

        self::assertSame(CliApplication::EXIT_OK, $code);
        self::assertStringContainsString('Usage: campanella', $out);
    }

    /**
     * @param list<string> $args
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function cli(array $args, string $stdin = ''): array
    {
        $in = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertNotFalse($in);
        self::assertNotFalse($out);
        self::assertNotFalse($err);

        fwrite($in, $stdin);
        rewind($in);

        $code = (new CliApplication($this->root, $in, $out, $err))->run(['campanella', ...$args]);

        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }
}
