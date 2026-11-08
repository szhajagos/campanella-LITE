<?php

declare(strict_types=1);

namespace Campanella\Lite\Tests\Command;

use Campanella\Lite\Command\Log\LogEntry;
use Campanella\Lite\Command\Result\Outcome;
use Campanella\Lite\Project\EntityRef;
use Campanella\Lite\Project\UndoHistory;
use Campanella\Lite\Storage\FileStore;

final class UndoAndLogTest extends CommandTestCase
{
    public function testUndoRemovesACreatedPage(): void
    {
        $original = $this->project();
        $this->apply('page create --title=News');
        self::assertFileExists($this->projectDir . '/pages/3.yaml');

        $result = $this->execute('undo');

        self::assertSame(Outcome::Applied, $result->outcome);
        self::assertSame('ok.undone', $result->lines[0]->message->key);
        self::assertSame(['pages/3.yaml', 'site.yaml'], $result->changedFiles);
        self::assertFileDoesNotExist($this->projectDir . '/pages/3.yaml');
        self::assertEquals($original, $this->project());
    }

    public function testUndoRestoresEditedAndDeletedContent(): void
    {
        $original = $this->project();
        $this->apply("content edit intro <<<\nChanged.\n>>>");
        $this->apply('content create news --title=News');

        $this->apply('undo');
        self::assertDirectoryDoesNotExist($this->projectDir . '/content/news');
        self::assertSame("Changed.\n", $this->project()->content('intro')?->translation('en')?->body);

        $this->apply('undo');
        self::assertEquals($original, $this->project());
    }

    public function testUndoTakesBackAnAliasAndItsRedirect(): void
    {
        $original = $this->project();
        $this->apply('page alias 2 /our-team');
        $withAlias = $this->project();
        $this->apply('page alias 2 /team-members');
        self::assertFileExists($this->projectDir . '/redirects.yaml');

        $this->apply('undo');
        self::assertEquals($withAlias, $this->project());

        $this->apply('undo');
        self::assertEquals($original, $this->project());
    }

    public function testNothingToUndo(): void
    {
        $this->assertFails('undo', 'undo.nothing');

        $this->apply('page create --title=News');
        $this->apply('undo');
        $this->assertFails('undo', 'undo.nothing');
    }

    public function testUndoMustBeAlone(): void
    {
        $this->apply('page create --title=News');

        $result = $this->execute("undo\npage publish 3");

        self::assertSame(Outcome::Rejected, $result->outcome);
        self::assertSame('line.standalone', $result->lines[0]->message->key);
        self::assertSame('line.skipped', $result->lines[1]->message->key);
        self::assertFileExists($this->projectDir . '/pages/3.yaml');
    }

    public function testDryRunUndoChangesNothing(): void
    {
        $this->apply('page create --title=News');

        $result = $this->execute("@dry-run\nundo");

        self::assertSame(Outcome::DryRun, $result->outcome);
        self::assertSame('ok.undo_planned', $result->lines[0]->message->key);
        self::assertFileExists($this->projectDir . '/pages/3.yaml');
        self::assertSame(Outcome::Applied, $this->execute('undo')->outcome);
    }

    public function testUndoRefusesToOverwriteLaterChanges(): void
    {
        $this->apply('page create --title=News');
        file_put_contents($this->projectDir . '/pages/3.yaml', str_replace('News', 'Edited by hand', (string) file_get_contents($this->projectDir . '/pages/3.yaml')));

        $result = $this->execute('undo');
        self::assertSame('undo.conflict', $result->lines[0]->message->key);
        self::assertSame(['files' => 'pages/3.yaml'], $result->lines[0]->message->params);
        self::assertFileExists($this->projectDir . '/pages/3.yaml');

        self::assertSame(Outcome::Applied, $this->execute('undo --force')->outcome);
        self::assertFileDoesNotExist($this->projectDir . '/pages/3.yaml');
    }

    public function testBatchesThatChangeNothingAreNotRecorded(): void
    {
        $this->execute("list pages\nget site.name");
        $this->execute("@dry-run\npage create --title=News");
        $this->execute('page publish 99');

        self::assertSame(0, $this->application->undoHistory()->count());
        self::assertSame([], $this->application->commandLog()->recent(10));
    }

    public function testOnlyTheLatestSnapshotsAreKept(): void
    {
        $root = dirname($this->projectDir);
        $history = new UndoHistory(new FileStore($this->projectDir), new FileStore($root . '/var'), limit: 2);
        $refs = [EntityRef::site()];

        foreach (['a', 'b', 'c'] as $id) {
            $history->record($id, $history->capture($refs), $refs, '', null, 'batch ' . $id);
        }

        self::assertSame(2, $history->count());
        self::assertSame('c', $history->latest()?->id);
    }

    public function testTheLogListsAppliedAndUndoneBatches(): void
    {
        $this->apply('page create --title=News');
        $this->apply("# publish it\npage publish 3\npage alias 3 /news");
        $this->apply('undo');

        $entries = $this->application->commandLog()->recent(10);

        self::assertCount(3, $entries);
        self::assertSame(LogEntry::UNDONE, $entries[0]->action);
        self::assertSame($entries[1]->id, $entries[0]->undoes);
        self::assertSame('page publish 3', $entries[1]->summary());
        self::assertSame(['site.yaml', 'pages/3.yaml'], $entries[2]->files);
        self::assertNull($entries[2]->user);

        $result = $this->execute('log --last=2');
        self::assertSame(Outcome::Unchanged, $result->outcome);
        self::assertSame(['count' => 2], $result->lines[0]->message->params);

        $text = $this->application->textFormatter()->format($result);
        self::assertStringContainsString('undid ' . $entries[1]->id, $text);
        self::assertStringContainsString('page publish 3', $text);
    }

    public function testTheLogRecordsTheAdmin(): void
    {
        $this->application->interpreter()->run('page create --title=News', user: 'admin');

        self::assertSame('admin', $this->application->commandLog()->recent(1)[0]->user);
        self::assertSame('admin', $this->application->undoHistory()->latest()?->user);
    }

    public function testInvalidLogCount(): void
    {
        $this->assertFails('log --last=0', 'log.invalid_count');
        $this->assertFails('log --last=many', 'log.invalid_count');
    }
}
