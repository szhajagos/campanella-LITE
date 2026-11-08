<?php

declare(strict_types=1);

namespace Campanella\Lite\Tests\Files;

use Campanella\Lite\Files\PublicFiles;
use Campanella\Lite\Files\Slug;
use Campanella\Lite\Model\File;
use Campanella\Lite\Tests\Command\CommandTestCase;
use Campanella\Lite\Tests\Support\SampleFiles;

final class PublicFilesTest extends CommandTestCase
{
    private SampleFiles $samples;
    private PublicFiles $files;

    protected function setUp(): void
    {
        parent::setUp();
        $this->samples = new SampleFiles($this->temporaryDirectory() . '/uploads');
        $this->files = $this->application->publicFiles();
    }

    public function testSlugs(): void
    {
        self::assertSame('eloadoterem-2026', Slug::of('Előadóterem 2026.jpg'));
        self::assertSame('arvizturo-tukorfurogep', Slug::of('ÁRVÍZTŰRŐ tükörfúrógép.pdf'));
        self::assertSame('strasse-und-cafe', Slug::of('Straße und Café.png'));
        self::assertSame('file', Slug::of('日本.pdf'));
        self::assertSame(60, strlen(Slug::of(str_repeat('a', 100) . '.png')));
    }

    public function testImageSizesAreNeverLargerThanTheImage(): void
    {
        self::assertSame([480, 960, 1200], $this->files->widths($this->upload('jpg', 1200, 800)));
        self::assertSame([480, 960, 1600], $this->files->widths($this->upload('png', 2400, 1600)));
        self::assertSame([300], $this->files->widths($this->upload('png', 300, 200)));
        self::assertSame([], $this->files->widths($this->upload('gif', 300, 200)));
    }

    public function testAddresses(): void
    {
        $image = $this->upload('jpg', 1200, 800, 'Kert tavasszal.jpg');
        $pdf = $this->application->fileRepository()->add($this->samples->bytes('a.pdf', SampleFiles::pdf()), 'Árlista 2027.pdf', 'admin');

        self::assertSame('/files/1-kert-tavasszal-960.jpg', $this->files->url($image, 960));
        self::assertSame('/files/1-kert-tavasszal-960.webp', $this->files->url($image, 960, true));
        self::assertSame('/files/1-kert-tavasszal-1200.jpg', $this->files->linkUrl($image));
        self::assertSame('/files/2-arlista-2027.pdf', $this->files->linkUrl($pdf));
        self::assertTrue($this->files->hasWebp($image));
        self::assertCount(6, $this->files->addresses($image));
        self::assertArrayNotHasKey('/files/1-kert-tavasszal.jpg', $this->files->addresses($image), 'The original image (with its metadata) is never published.');
    }

    public function testPublishesResizedCopies(): void
    {
        $image = $this->upload('png', 1200, 800);

        $stored = $this->files->publish($image, '/files/1-hall-480.png');
        self::assertNotNull($stored);
        self::assertSame($this->application->paths->public() . '/files/1-hall-480.png', $stored);
        self::assertSame([480, 320], array_slice((array) getimagesize($stored), 0, 2));

        $webp = (string) $this->files->publish($image, '/files/1-hall-960.webp');
        self::assertSame(IMAGETYPE_WEBP, getimagesize($webp)[2] ?? null);

        self::assertNull($this->files->publish($image, '/files/1-hall.png'));
        self::assertNull($this->files->publish($image, '/files/1-hall-500.png'));
        self::assertSame(['files/1-hall-480.png', 'files/1-hall-960.webp'], $this->application->fileCache()->files());
    }

    public function testOutdatedCopiesArePruned(): void
    {
        $this->upload('png', 1200, 800);
        $this->upload('png', 600, 400, 'garden.png');
        $this->publishAll();
        self::assertCount(10, $this->application->fileCache()->files());

        $this->apply("file rename 1 \"Conference hall\"\nfile delete 2");

        self::assertSame([], $this->application->fileCache()->files());
        self::assertFileDoesNotExist($this->application->paths->public() . '/files/1-hall-480.png');

        $this->publishAll();
        $this->apply("content edit intro <<<\nChanged.\n>>>");
        self::assertCount(6, $this->application->fileCache()->files(), 'Other changes keep the published files.');
    }

    private function publishAll(): void
    {
        foreach ($this->project()->files as $file) {
            if (!$file->isArchived()) {
                foreach (array_keys($this->files->addresses($file)) as $address) {
                    $this->files->publish($file, $address);
                }
            }
        }
    }

    /**
     * @param 'png'|'jpg'|'gif'|'webp' $type
     * @param int<1, max>              $width
     * @param int<1, max>              $height
     */
    private function upload(string $type, int $width, int $height, string $name = 'hall.png'): File
    {
        return $this->application->fileRepository()->add($this->samples->image($type, $width, $height), $name, 'admin');
    }
}
