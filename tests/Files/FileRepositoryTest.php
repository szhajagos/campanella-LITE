<?php

declare(strict_types=1);

namespace Campanella\Lite\Tests\Files;

use Campanella\Lite\Application;
use Campanella\Lite\Command\Log\LogEntry;
use Campanella\Lite\Files\FileRejected;
use Campanella\Lite\Files\FileRepository;
use Campanella\Lite\Paths;
use Campanella\Lite\Tests\Support\SampleFiles;
use Campanella\Lite\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class FileRepositoryTest extends TestCase
{
    use TemporaryDirectory;

    private Application $application;
    private SampleFiles $samples;
    private string $projectDir;

    protected function setUp(): void
    {
        $root = $this->temporaryDirectory() . '/site';
        mkdir($root);
        rename($this->copyFixture('valid-project'), $root . '/project');
        $this->projectDir = $root . '/project';
        $this->application = new Application(new Paths($root));
        $this->samples = new SampleFiles($this->temporaryDirectory() . '/uploads');
    }

    public function testAddsAFileWithItsManifest(): void
    {
        $file = $this->application->fileRepository()->add($this->samples->image('jpg', 64, 48), 'C:\\Photos\\Előadóterem 1.JPEG', 'admin');

        self::assertSame(1, $file->id);
        self::assertSame('Előadóterem 1.jpg', $file->filename);
        self::assertSame('image/jpeg', $file->mimeType);
        self::assertSame([64, 48], [$file->width, $file->height]);
        self::assertSame('admin', $file->uploadedBy);
        self::assertMatchesRegularExpression(FileRepository::STORED_NAME_PATTERN, $file->storedFilename);
        self::assertFileExists($this->projectDir . '/files/store/' . $file->storedFilename);
        self::assertSame($this->projectDir . '/files/store/' . $file->storedFilename, $this->application->fileRepository()->pathOf($file));

        $loaded = $this->application->repository()->load()->file(1);
        self::assertEquals($file, $loaded);
        self::assertSame([], $this->application->interpreter()->run('check')->violations);
    }

    public function testIdsAreNeverReused(): void
    {
        $repository = $this->application->fileRepository();
        $first = $repository->add($this->samples->image(), 'a.png', null);
        $second = $repository->add($this->samples->image(), 'b.png', null);

        self::assertSame([1, 2], [$first->id, $second->id]);
        self::assertSame('cli', $first->uploadedBy);
        self::assertNotSame($first->storedFilename, $second->storedFilename);

        $repository->purge($second, null);
        self::assertFileDoesNotExist($this->projectDir . '/files/manifest/2.yaml');
        self::assertFileDoesNotExist($this->projectDir . '/files/store/' . $second->storedFilename);

        self::assertSame(3, $repository->add($this->samples->image(), 'c.png', null)->id);
    }

    public function testRefusedFilesLeaveNothingBehind(): void
    {
        try {
            $this->application->fileRepository()->add($this->samples->bytes('page.html', '<html></html>'), 'page.html', 'admin');
            self::fail('The file was accepted.');
        } catch (FileRejected $rejected) {
            self::assertSame('file.unsupported_type', $rejected->reason->key);
        }

        self::assertDirectoryDoesNotExist($this->projectDir . '/files');
    }

    public function testUploadsAndPurgesAreLogged(): void
    {
        $repository = $this->application->fileRepository();
        $file = $repository->add($this->samples->image(), 'a.png', 'admin');
        $repository->purge($file, 'admin');

        $entries = $this->application->commandLog()->recent(5);
        self::assertSame([LogEntry::PURGED, LogEntry::UPLOADED], [$entries[0]->action, $entries[1]->action]);
        self::assertSame(['files/manifest/1.yaml', 'files/store/' . $file->storedFilename], $entries[1]->files);
        self::assertSame('upload a.png', $entries[1]->batch);
    }

    public function testFileNames(): void
    {
        self::assertSame('Photo 1.jpg', FileRepository::filename('/home/me/Photo 1.JPEG', 'jpg'));
        self::assertSame('report.pdf', FileRepository::filename("report\x07.pdf", 'pdf'));
        self::assertSame('file.png', FileRepository::filename(' ... ', 'png'));
        self::assertSame('archive.tar.mp3', FileRepository::filename('archive.tar.gz', 'mp3'));
        self::assertSame(120, mb_strlen(FileRepository::filename(str_repeat('é', 200) . '.png', 'png')));
    }
}
