<?php

declare(strict_types=1);

namespace Campanella\Lite\Tests\Command;

use Campanella\Lite\Application;
use Campanella\Lite\Command\Result\BatchResult;
use Campanella\Lite\Command\Result\Outcome;
use Campanella\Lite\Model\Project;
use Campanella\Lite\Paths;
use Campanella\Lite\Project\ProjectRepository;
use Campanella\Lite\Storage\FileStore;
use Campanella\Lite\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * Runs batches against a fresh copy of the sample project.
 */
abstract class CommandTestCase extends TestCase
{
    use TemporaryDirectory;

    protected Application $application;
    protected string $projectDir;

    protected function setUp(): void
    {
        $root = $this->temporaryDirectory() . '/site';
        mkdir($root);
        rename($this->copyFixture('valid-project'), $root . '/project');
        $this->projectDir = $root . '/project';
        $this->application = new Application(new Paths($root));
    }

    protected function execute(string $input, ?string $user = null): BatchResult
    {
        return $this->application->interpreter()->run($input, false, $user);
    }

    /**
     * Runs a batch that must be applied and returns the project as it was written.
     */
    protected function apply(string $input, ?string $user = null): Project
    {
        $result = $this->execute($input, $user);
        $text = $this->application->textFormatter()->format($result);

        self::assertSame(Outcome::Applied, $result->outcome, $text);

        return $this->project();
    }

    /**
     * Runs a batch whose first failing line must report the given message key.
     */
    protected function assertFails(string $input, string $key, ?string $user = null): void
    {
        $before = $this->project();
        $result = $this->execute($input, $user);
        $failed = null;

        foreach ($result->lines as $line) {
            if ($line->status->value === 'error') {
                $failed = $line->message->key;
                break;
            }
        }

        self::assertSame($key, $failed, $this->application->textFormatter()->format($result));
        self::assertEquals($before, $this->project(), 'A rejected batch must not change anything.');
    }

    protected function project(): Project
    {
        return (new ProjectRepository(new FileStore($this->projectDir)))->load();
    }
}
