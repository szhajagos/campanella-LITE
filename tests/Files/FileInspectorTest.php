<?php

declare(strict_types=1);

namespace Campanella\Lite\Tests\Files;

use Campanella\Lite\Files\FileInspector;
use Campanella\Lite\Files\FileRejected;
use Campanella\Lite\Tests\Support\SampleFiles;
use Campanella\Lite\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FileInspectorTest extends TestCase
{
    use TemporaryDirectory;

    private SampleFiles $samples;

    protected function setUp(): void
    {
        $this->samples = new SampleFiles($this->temporaryDirectory());
    }

    public function testImagesAreRecognisedWithTheirSize(): void
    {
        foreach (['png', 'jpg', 'gif', 'webp'] as $type) {
            $info = (new FileInspector())->inspect($this->samples->image($type, 40, 30));
            self::assertSame(['extension' => $type, 'width' => 40, 'height' => 30], $info, $type);
        }
    }

    public function testTheContentDecidesNotTheName(): void
    {
        $png = $this->samples->image('png');
        rename($png, $png . '.pdf');

        self::assertSame('png', (new FileInspector())->inspect($png . '.pdf')['extension']);
    }

    public function testOtherAcceptedTypes(): void
    {
        $pad = str_repeat("\0", 32);
        $cases = [
            'wav' => "RIFF\x24\0\0\0WAVEfmt " . $pad,
            'aiff' => "FORM\0\0\0\x20AIFFCOMM" . $pad,
            'flac' => 'fLaC' . $pad,
            'mp3' => 'ID3' . $pad,
            'opus' => 'OggS' . $pad . 'OpusHead' . $pad,
            'ogg' => 'OggS' . $pad . "\x01vorbis" . $pad,
            'mp4' => "\0\0\0\x18ftypisom" . $pad,
            'webm' => "\x1A\x45\xDF\xA3" . "\x42\x82\x84webm" . $pad,
            'pdf' => SampleFiles::pdf(),
        ];

        foreach ($cases as $extension => $contents) {
            self::assertSame($extension, (new FileInspector())->inspect($this->samples->bytes('sample.bin', $contents))['extension'], $extension);
        }

        self::assertSame('mp3', (new FileInspector())->inspect($this->samples->bytes('frame.bin', "\xFF\xFB\x90\x64" . $pad))['extension']);
    }

    public function testOfficeDocumentsWithoutMacros(): void
    {
        self::assertSame('docx', (new FileInspector())->inspect($this->samples->office('a.docx', SampleFiles::DOCX))['extension']);
        self::assertSame('xlsx', (new FileInspector())->inspect($this->samples->office('a.xlsx', SampleFiles::XLSX))['extension']);
        self::assertSame('pptx', (new FileInspector())->inspect($this->samples->office('a.pptx', SampleFiles::PPTX))['extension']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refused(): iterable
    {
        yield 'html' => ['<!DOCTYPE html><script>alert(1)</script>', 'file.unsupported_type'];
        yield 'svg' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'file.unsupported_type'];
        yield 'php' => ['<?php echo 1;', 'file.unsupported_type'];
        yield 'quicktime' => ["\0\0\0\x14ftypqt  " . str_repeat("\0", 16), 'file.unsupported_type'];
        yield 'heic' => ["\0\0\0\x18ftypheic" . str_repeat("\0", 16), 'file.unsupported_type'];
        yield 'matroska' => ["\x1A\x45\xDF\xA3\x42\x82\x88matroska" . str_repeat("\0", 16), 'file.unsupported_type'];
        yield 'ogg theora' => ['OggS' . str_repeat("\0", 24) . "\x80theora", 'file.unsupported_type'];
        yield 'png of impossible size' => ["\x89PNG\r\n\x1A\n" . str_repeat('x', 40), 'file.image_too_large'];
        yield 'png with broken data' => ["\x89PNG\r\n\x1A\n\0\0\0\x0DIHDR\0\0\0\x10\0\0\0\x10\x08\x02\0\0\0" . str_repeat('x', 40), 'file.damaged'];
        yield 'pdf with javascript' => [SampleFiles::pdf('/OpenAction << /S /JavaScript /JS (app.alert(1)) >>'), 'file.pdf_active'];
        yield 'pdf with hidden javascript' => [SampleFiles::pdf('/OpenAction << /S /J#61vaScript >>'), 'file.pdf_active'];
        yield 'pdf launching a program' => [SampleFiles::pdf('/OpenAction << /S /Launch /F (cmd.exe) >>'), 'file.pdf_active'];
        yield 'pdf with an embedded file' => [SampleFiles::pdf('/Names << /EmbeddedFiles 2 0 R >>'), 'file.pdf_active'];
        yield 'empty' => ['', 'file.empty'];
    }

    #[DataProvider('refused')]
    public function testRefused(string $contents, string $key): void
    {
        $this->expectRejection($key, $this->samples->bytes('refused.bin', $contents));
    }

    public function testMacroEnabledAndOtherZipFilesAreRefused(): void
    {
        $this->expectRejection('file.macros', $this->samples->office('a.docm', SampleFiles::DOCM));
        $this->expectRejection('file.macros', $this->samples->office('b.docx', SampleFiles::DOCX, vba: true));
        $this->expectRejection('file.unsupported_type', $this->samples->zip('c.zip', ['readme.txt' => 'hello']));
    }

    public function testSizeLimits(): void
    {
        $large = $this->samples->bytes('large.pdf', SampleFiles::pdf());
        $handle = fopen($large, 'r+');
        self::assertNotFalse($handle);
        ftruncate($handle, 10 * 1024 * 1024 + 1);
        fclose($handle);

        $this->expectRejection('file.too_large', $large);

        $video = $this->samples->bytes('video.mp4', "\0\0\0\x18ftypisom" . str_repeat("\0", 16));
        $handle = fopen($video, 'r+');
        self::assertNotFalse($handle);
        ftruncate($handle, 50 * 1024 * 1024);
        fclose($handle);

        self::assertSame('mp4', (new FileInspector())->inspect($video)['extension']);
    }

    private function expectRejection(string $key, string $path): void
    {
        try {
            (new FileInspector())->inspect($path);
            self::fail('Expected ' . $key);
        } catch (FileRejected $rejected) {
            self::assertSame($key, $rejected->reason->key);
        }
    }
}
