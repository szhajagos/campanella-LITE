<?php

declare(strict_types=1);

namespace Campanella\Lite\Tests\Render;

use Campanella\Lite\Http\Response;
use Campanella\Lite\Tests\Command\CommandTestCase;
use Campanella\Lite\Tests\Support\SampleFiles;

/**
 * References to files in content, as the website shows them.
 */
final class FilesOnTheWebsiteTest extends CommandTestCase
{
    private SampleFiles $samples;

    protected function setUp(): void
    {
        parent::setUp();
        $this->samples = new SampleFiles($this->temporaryDirectory() . '/uploads');
        $repository = $this->application->fileRepository();
        $repository->add($this->samples->image('jpg', 1200, 800), 'Kert tavasszal.jpg', 'admin');      // 1
        $repository->add($this->samples->bytes('p.pdf', SampleFiles::pdf()), 'Árlista.pdf', 'admin');   // 2
        $repository->add($this->samples->bytes('s.mp3', 'ID3' . str_repeat("\0", 32)), 'Dal.mp3', 'admin');   // 3
        $repository->add($this->samples->bytes('v.mp4', "\0\0\0\x18ftypisom" . str_repeat("\0", 32)), 'Film.mp4', 'admin'); // 4
        $repository->add($this->samples->image('gif', 50, 40), 'anim.gif', 'admin');                    // 5
        $this->apply("language add hu\nfile describe 1 \"Spring garden\"\nfile describe 1 --lang=hu \"Tavaszi kert\"");
    }

    public function testAnImageInEverySizeWithWebp(): void
    {
        $html = $this->page("![](file:1)");

        self::assertStringContainsString('<picture><source type="image/webp" srcset="/files/1-kert-tavasszal-480.webp 480w, /files/1-kert-tavasszal-960.webp 960w, /files/1-kert-tavasszal-1200.webp 1200w"', $html);
        self::assertStringContainsString('<img src="/files/1-kert-tavasszal-1200.jpg" alt="Spring garden" width="1200" height="800" class="img-fluid" loading="lazy" decoding="async" srcset="/files/1-kert-tavasszal-480.jpg 480w, ', $html);
        self::assertStringContainsString('</picture>', $html);
    }

    public function testTheAltTextFollowsTheLanguage(): void
    {
        self::assertStringContainsString('alt="Tavaszi kert"', $this->page('![](file:1)', 'hu'));
        self::assertStringContainsString('alt="Our garden &quot;in&quot; &lt;spring&gt;"', $this->page('![Our garden "in" <spring>](file:1)'));
    }

    public function testACaptionMakesAFigure(): void
    {
        $html = $this->page("Before.\n\n![](file:1 \"The garden in April\")\n\nAfter.");

        self::assertMatchesRegularExpression('#<figure class="figure file-figure"><picture>.*</picture><figcaption class="figure-caption">The garden in April</figcaption></figure>#s', $html);
        self::assertStringNotContainsString('<p><figure', $html);
    }

    public function testOtherFiles(): void
    {
        $html = $this->page("[Prices](file:2) ![](file:3) ![Trailer](file:4) ![](file:2) ![](file:5)");

        self::assertStringContainsString('<a href="/files/2-arlista.pdf">Prices</a>', $html);
        self::assertStringContainsString('<audio controls preload="none" src="/files/3-dal.mp3"><a href="/files/3-dal.mp3">Dal.mp3</a></audio>', $html);
        self::assertStringContainsString('<video controls preload="metadata" src="/files/4-film.mp4" aria-label="Trailer" class="w-100">', $html);
        self::assertStringContainsString('<a href="/files/2-arlista.pdf">Árlista.pdf</a>', $html);
        self::assertStringContainsString('<img src="/files/5-anim.gif" alt="" width="50" height="40"', $html);
    }

    public function testArchivedFilesDisappear(): void
    {
        $this->apply("content edit intro <<<\nSee [the prices](file:2) and ![](file:1).\n>>>\nfile delete 1 --force\nfile delete 2 --force");
        $html = $this->request('/about')->body;

        self::assertStringContainsString('See the prices and .', $html);
        self::assertStringNotContainsString('/files/', $html);
    }

    public function testTheFrontControllerPublishesFiles(): void
    {
        $image = $this->request('/files/1-kert-tavasszal-480.jpg');
        self::assertSame(200, $image->status);
        self::assertSame('image/jpeg', $image->headers['Content-Type']);
        self::assertNotNull($image->file);
        self::assertSame([480, 320], array_slice((array) getimagesize($image->file), 0, 2));
        self::assertFileExists($this->application->paths->public() . '/files/1-kert-tavasszal-480.jpg');

        self::assertSame('image/webp', $this->request('/files/1-kert-tavasszal-960.webp')->headers['Content-Type']);
        self::assertSame('application/pdf', $this->request('/files/2-arlista.pdf')->headers['Content-Type']);

        $renamed = $this->request('/files/1-old-name-480.jpg');
        self::assertSame(301, $renamed->status);
        self::assertSame('/files/1-kert-tavasszal-1200.jpg', $renamed->headers['Location']);
        self::assertSame(301, $this->request('/files/1-kert-tavasszal.jpg')->status, 'The original image is never published.');

        self::assertSame(404, $this->request('/files/99-nothing.pdf')->status);
        $this->apply('file delete 2');
        self::assertSame(404, $this->request('/files/2-arlista.pdf')->status);
    }

    public function testCacheClearAlsoRemovesPublishedFiles(): void
    {
        $this->request('/files/2-arlista.pdf');
        $this->request('/about');

        $result = $this->execute('cache clear');

        self::assertSame(['count' => 2], $result->lines[0]->message->params);
        self::assertSame([], $this->application->fileCache()->files());
    }

    public function testAssetAddressesCarryAVersion(): void
    {
        $css = $this->application->paths->public() . '/assets/themes/default/theme.css';
        mkdir(dirname($css), 0777, true);
        file_put_contents($css, 'body {}');

        $html = $this->request('/about')->body;

        self::assertMatchesRegularExpression('#href="/assets/themes/default/theme\.css\?v=[0-9a-f]{8}"#', $html);
    }

    private function page(string $markdown, string $language = 'en'): string
    {
        $this->apply("content edit intro --lang=en <<<\n" . $markdown . "\n>>>\ncontent translate intro hu --title=Bevezető <<<\n" . $markdown . "\n>>>");

        return $this->request($language === 'en' ? '/about' : '/hu/node/2')->body;
    }

    private function request(string $path): Response
    {
        return $this->application->frontController()->handle('GET', $path);
    }
}
