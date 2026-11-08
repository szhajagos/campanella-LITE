<?php

declare(strict_types=1);

namespace Campanella\Lite\Tests\Command;

use Campanella\Lite\Command\Result\Outcome;
use Campanella\Lite\Model\File;
use Campanella\Lite\Tests\Support\SampleFiles;

final class FileCommandsTest extends CommandTestCase
{
    private SampleFiles $samples;

    protected function setUp(): void
    {
        parent::setUp();
        $this->samples = new SampleFiles($this->temporaryDirectory() . '/uploads');
    }

    private function upload(string $name = 'hall.png', ?string $user = 'admin'): File
    {
        return $this->application->fileRepository()->add($this->samples->image('png', 20, 10), $name, $user);
    }

    private function file(int $id): File
    {
        $file = $this->project()->file($id);
        self::assertNotNull($file);

        return $file;
    }

    public function testDescribeInLanguages(): void
    {
        $this->upload();
        $this->apply("language add hu\nfile describe 1 \"The conference hall\"\nfile describe 1 --lang=hu \"Az előadóterem\"");

        self::assertSame(['en' => 'The conference hall', 'hu' => 'Az előadóterem'], $this->file(1)->description->toArray());
        self::assertSame('The conference hall', $this->execute('get file.1.description')->lines[0]->output?->value);

        $this->apply('file describe 1 --lang=hu ""');
        self::assertSame(['en' => 'The conference hall'], $this->file(1)->description->toArray());

        $this->assertFails('file describe 1 --lang=de "Saal"', 'language.not_enabled');
        $this->assertFails('file describe 9 "x"', 'file.not_found');
    }

    public function testRename(): void
    {
        $this->upload();
        $this->apply('file rename 1 "Conference hall"');
        self::assertSame('Conference hall.png', $this->file(1)->filename);

        $this->apply('file rename 1 "hall-2026.png"');
        self::assertSame('hall-2026.png', $this->file(1)->filename);

        $this->apply('file rename 1 "HALL.PNG"');
        self::assertSame('HALL.png', $this->file(1)->filename);

        $this->assertFails('file rename 1 "hall.jpg"', 'file.extension_change');
        $this->assertFails('file rename 1 "evil.php"', 'file.extension_change');
        $this->assertFails('file rename 1 "a/b"', 'file.invalid_filename');
    }

    public function testSetAndGet(): void
    {
        $this->upload();
        $this->apply("set file.1.filename \"new.png\"\nset file.1.description \"Hall\"");

        self::assertSame('new.png', $this->file(1)->filename);
        self::assertSame(20, $this->execute('get file.1.width')->lines[0]->output?->value);
        self::assertNull($this->execute('get file.1.archived_at')->lines[0]->output?->value);

        $result = $this->execute('set file.1.filename "new.jpg"');
        self::assertSame(Outcome::Invalid, $result->outcome);
        self::assertSame('file.invalid_filename', $result->violations[0]->code);

        $this->assertFails('set file.1.size 5', 'path.not_settable');
    }

    public function testArchiveRestoreAndUndo(): void
    {
        $this->upload();
        $this->apply('file delete 1');
        self::assertTrue($this->file(1)->isArchived());
        self::assertSame(['count' => 0], array_intersect_key($this->execute('list files')->lines[0]->message->params, ['count' => 1]));
        self::assertSame(1, $this->execute('list files --archived')->lines[0]->message->params['count']);
        $this->assertFails('file delete 1', 'file.already_archived');

        $this->apply('file restore 1');
        self::assertFalse($this->file(1)->isArchived());
        $this->assertFails('file restore 1', 'file.not_archived');

        $this->apply('file delete 1');
        $this->apply('undo');
        self::assertFalse($this->file(1)->isArchived());
    }

    public function testFilesInUseNeedForce(): void
    {
        $this->upload();
        $this->apply("content edit intro <<<\nLook: ![](file:1)\n>>>");

        $this->assertFails('file delete 1', 'file.in_use');
        $result = $this->execute('file delete 1 --force');
        self::assertSame(Outcome::Applied, $result->outcome);
        self::assertSame('ok.file_archived_in_use', $result->lines[0]->message->key);

        $this->assertFails('file purge 1', 'file.purge_in_use');
        $this->apply("content edit intro <<<\nNo picture.\n>>>");
    }

    public function testPurgeRemovesArchivedFilesForGood(): void
    {
        $kept = $this->upload('kept.png');
        $gone = $this->upload('gone.png');
        $used = $this->upload('used.png');
        $this->apply("content edit intro <<<\n[Picture](file:3)\n>>>\nfile delete 2\nfile delete 3 --force");

        $dry = $this->execute("@dry-run\nfile purge");
        self::assertSame('ok.files_purge_planned_some_kept', $dry->lines[0]->message->key);
        self::assertFileExists($this->projectDir . '/files/manifest/2.yaml');

        $result = $this->execute('file purge');
        self::assertSame(Outcome::Unchanged, $result->outcome);
        self::assertSame('ok.files_purged_some_kept', $result->lines[0]->message->key);
        self::assertSame(['count' => 1, 'kept' => '3'], $result->lines[0]->message->params);

        self::assertNull($this->project()->file(2));
        self::assertFileDoesNotExist($this->projectDir . '/files/store/' . $gone->storedFilename);
        self::assertNotNull($this->project()->file(1));
        self::assertFileExists($this->projectDir . '/files/store/' . $kept->storedFilename);
        self::assertNotNull($this->project()->file(3));
        self::assertFileExists($this->projectDir . '/files/store/' . $used->storedFilename);

        $this->assertFails('file purge 1', 'file.not_archived');
    }

    public function testListAndSearch(): void
    {
        $this->upload('hall.png', 'admin');
        $this->upload('garden.png', 'editor');
        $pdf = $this->application->fileRepository()->add($this->samples->bytes('prices.pdf', SampleFiles::pdf()), 'Prices 2027.pdf', 'admin');
        $this->apply("file describe 1 \"Conference hall\"\nfile describe {$pdf->id} \"Price list\"");

        $count = fn (string $command): int => (int) $this->execute($command)->lines[0]->message->params['count'];

        self::assertSame(3, $count('list files'));
        self::assertSame(2, $count('list files --type=image'));
        self::assertSame(1, $count('list files --type=document'));
        self::assertSame(1, $count('list files --name=GARDEN'));
        self::assertSame(2, $count('list files --by=admin'));
        self::assertSame(1, $count('list files --search=conference'));
        self::assertSame(1, $count('list files --search=price'));
        self::assertSame(3, $count('list files --since=2000-01-01'));
        self::assertSame(0, $count('list files --since=2999-01-01'));

        $text = $this->application->textFormatter()->format($this->execute('list files --type=image'));
        self::assertStringContainsString('20×10', $text);
        self::assertStringContainsString('Conference hall', $text);

        $this->assertFails('list files --type=font', 'value.invalid_choice');
        $this->assertFails('list files --since=yesterday', 'file.invalid_date');
    }

    public function testContentMustReferToExistingFiles(): void
    {
        $result = $this->execute("content edit intro <<<\n![](file:42)\n>>>");

        self::assertSame(Outcome::Invalid, $result->outcome);
        self::assertSame('content.unknown_file', $result->violations[0]->code);
        self::assertSame(['file' => 42], $result->violations[0]->params);
    }

    public function testTheLogShowsUploads(): void
    {
        $this->upload();
        $text = $this->application->textFormatter()->format($this->execute('log'));

        self::assertStringContainsString('uploaded', $text);
        self::assertStringContainsString('upload hall.png', $text);
    }
}
