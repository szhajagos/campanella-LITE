<?php

declare(strict_types=1);

namespace Campanella\Lite\Tests\Storage;

use Campanella\Lite\Exception\StorageException;
use Campanella\Lite\Storage\FileStore;
use Campanella\Lite\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FileStoreTest extends TestCase
{
    use TemporaryDirectory;

    private FileStore $store;

    protected function setUp(): void
    {
        $this->store = new FileStore($this->temporaryDirectory());
    }

    public function testWritesAndReadsAFile(): void
    {
        $this->store->write('site.yaml', "name: Example\n");

        self::assertTrue($this->store->exists('site.yaml'));
        self::assertSame("name: Example\n", $this->store->read('site.yaml'));
    }

    public function testCreatesMissingDirectoriesOnWrite(): void
    {
        $this->store->write('content/intro/en.md', 'Hello');

        self::assertFileExists($this->temporaryDirectory() . '/content/intro/en.md');
    }

    public function testOverwritesAtomicallyWithoutLeavingTemporaryFiles(): void
    {
        $this->store->write('pages/1.yaml', 'first');
        $this->store->write('pages/1.yaml', 'second');

        self::assertSame('second', $this->store->read('pages/1.yaml'));
        self::assertSame(['.', '..', '1.yaml'], scandir($this->temporaryDirectory() . '/pages'));
    }

    public function testReadingAMissingFileFails(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('File not found: "missing.yaml"');

        $this->store->read('missing.yaml');
    }

    public function testDeletesFiles(): void
    {
        $this->store->write('menus/main.yaml', 'x');

        self::assertTrue($this->store->delete('menus/main.yaml'));
        self::assertFalse($this->store->exists('menus/main.yaml'));
        self::assertFalse($this->store->delete('menus/main.yaml'));
    }

    public function testDeletesOnlyEmptyDirectories(): void
    {
        $this->store->write('content/intro/en.md', 'x');

        self::assertFalse($this->store->deleteDirectoryIfEmpty('content/intro'));

        $this->store->delete('content/intro/en.md');

        self::assertTrue($this->store->deleteDirectoryIfEmpty('content/intro'));
        self::assertDirectoryDoesNotExist($this->temporaryDirectory() . '/content/intro');
    }

    public function testListsFilesByExtensionInNaturalOrder(): void
    {
        $this->store->write('pages/10.yaml', 'x');
        $this->store->write('pages/2.yaml', 'x');
        $this->store->write('pages/1.yaml', 'x');
        $this->store->write('pages/notes.txt', 'x');
        file_put_contents($this->temporaryDirectory() . '/pages/.hidden.yaml', 'x');

        self::assertSame(['1', '2', '10'], $this->store->listFiles('pages', 'yaml'));
    }

    public function testListsDirectories(): void
    {
        $this->store->write('content/team/en.md', 'x');
        $this->store->write('content/intro/en.md', 'x');
        $this->store->write('content/readme.txt', 'x');

        self::assertSame(['intro', 'team'], $this->store->listDirectories('content'));
    }

    public function testListingAMissingDirectoryIsEmpty(): void
    {
        self::assertSame([], $this->store->listFiles('layouts', 'yaml'));
        self::assertSame([], $this->store->listDirectories('content'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafePaths(): iterable
    {
        yield 'parent directory' => ['../secret.yaml'];
        yield 'nested parent directory' => ['pages/../../secret.yaml'];
        yield 'absolute path' => ['/etc/passwd'];
        yield 'backslash' => ['pages\\1.yaml'];
        yield 'hidden file' => ['.htaccess'];
        yield 'empty segment' => ['pages//1.yaml'];
        yield 'null byte' => ["pages/1.yaml\0.txt"];
    }

    #[DataProvider('unsafePaths')]
    public function testRejectsUnsafePaths(string $path): void
    {
        $this->expectException(StorageException::class);

        $this->store->write($path, 'x');
    }

    public function testRejectsWritingWithoutAFileName(): void
    {
        $this->expectException(StorageException::class);

        $this->store->write('', 'x');
    }
}
