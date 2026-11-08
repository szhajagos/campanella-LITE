<?php

declare(strict_types=1);

namespace Campanella\Lite\Tests\Render;

use Campanella\Lite\Exception\StorageException;
use Campanella\Lite\Http\Response;
use Campanella\Lite\Tests\Command\CommandTestCase;

/**
 * The renderer, the page cache and the front controller, against the sample project.
 */
final class RenderingTest extends CommandTestCase
{
    public function testRendersAPage(): void
    {
        $html = $this->render('/about');

        self::assertStringContainsString('<html lang="en">', $html);
        self::assertStringContainsString('<title>About us – Example Ltd.</title>', $html);
        self::assertStringContainsString('<meta name="description" content="Meet our team.">', $html);
        self::assertStringContainsString('<link rel="canonical" href="https://example.com/about">', $html);
        self::assertStringContainsString('<p>Our small team has been building <strong>custom</strong> websites since 2015.</p>', $html);
        self::assertStringContainsString('<div class="col-md-8 column column-main">', $html);
        self::assertStringContainsString('<div class="container-fluid">', $html);
        self::assertStringContainsString('<a class="nav-link active" href="/about" aria-current="page">About</a>', $html);
        self::assertStringContainsString('<a class="nav-link" href="/">Home</a>', $html);
        self::assertStringNotContainsString('hreflang', $html);
    }

    public function testTheFrontPageTitleIsTheSiteName(): void
    {
        self::assertStringContainsString('<title>Example Ltd.</title>', $this->render('/'));
    }

    public function testEscapesRawHtmlInContentAndTexts(): void
    {
        $this->apply("content edit intro <<<\n<script>alert(1)</script>\n\nA [link](javascript:alert(2)) and <img src=x onerror=alert(3)>\n>>>\nset page.2.title \"<b>Bold</b>\"");
        $html = $this->render('/about');

        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('href="javascript:', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('<title>&lt;b&gt;Bold&lt;/b&gt; – Example Ltd.</title>', $html);
    }

    public function testFallsBackToTheDefaultLanguageAndLinksTranslations(): void
    {
        $this->apply("language add hu\nset page.2.title.hu Rólunk\npage alias 2 /rolunk --lang=hu\nset menu.main.items.2.label.hu Rólunk");
        $html = $this->render('/hu/rolunk');

        self::assertStringContainsString('<html lang="hu">', $html);
        self::assertStringContainsString('<title>Rólunk – Example Ltd.</title>', $html);
        self::assertStringContainsString('<link rel="alternate" hreflang="en" href="https://example.com/about">', $html);
        self::assertStringContainsString('<link rel="alternate" hreflang="hu" href="https://example.com/hu/rolunk">', $html);
        self::assertStringContainsString('websites since 2015', $html, 'Content without a Hungarian version falls back to English.');
        self::assertStringContainsString('<a class="nav-link" href="/hu/">Home</a>', $html);
        self::assertStringContainsString('aria-current="page">Rólunk</a>', $html);
    }

    public function testMenusLeaveOutUnpublishedPages(): void
    {
        $this->apply('page unpublish /about');

        self::assertStringNotContainsString('>About</a>', $this->render('/'));
    }

    public function testRendersNotFoundPages(): void
    {
        $response = $this->request('/nothing');

        self::assertSame(404, $response->status);
        self::assertStringContainsString('<h1>Page not found</h1>', $response->body);
        self::assertStringContainsString('<meta name="robots" content="noindex">', $response->body);
        self::assertSame([], $this->application->pageCache()->files());
    }

    public function testTheFrontControllerStoresPagesAndRedirects(): void
    {
        $page = $this->request('/about/');
        self::assertSame(200, $page->status);
        self::assertSame('text/html; charset=utf-8', $page->headers['Content-Type']);
        self::assertSame($page->body, file_get_contents($this->application->paths->public() . '/about/index.html'));

        $redirect = $this->request('/node/2?x=1');
        self::assertSame(301, $redirect->status);
        self::assertSame('/about', $redirect->headers['Location']);

        self::assertSame(['about/index.html'], $this->application->pageCache()->files());
    }

    public function testTheFrontControllerOnlyAnswersGetAndHead(): void
    {
        self::assertSame(405, $this->application->frontController()->handle('POST', '/about')->status);
        self::assertSame(200, $this->application->frontController()->handle('HEAD', '/about')->status);
    }

    public function testABrokenProjectGivesAServiceUnavailableAnswer(): void
    {
        file_put_contents($this->projectDir . '/site.yaml', "name: [broken\n");

        $response = $this->request('/about');

        self::assertSame(503, $response->status);
        self::assertStringNotContainsString('site.yaml', $response->body);
    }

    public function testTheCacheNeverOverwritesFilesItDidNotCreate(): void
    {
        mkdir($this->application->paths->public() . '/about', 0775, true);
        file_put_contents($this->application->paths->public() . '/about/index.html', 'mine');

        $this->expectException(StorageException::class);

        $this->application->pageCache()->store('/about', '<html>');
    }

    public function testClearingRemovesFilesAndEmptyDirectories(): void
    {
        $cache = $this->application->pageCache();
        $cache->store('/services/web', 'a');
        $cache->store('/', 'b');
        file_put_contents($this->application->paths->public() . '/robots.txt', 'keep');

        self::assertSame(2, $cache->clear());
        self::assertDirectoryDoesNotExist($this->application->paths->public() . '/services');
        self::assertFileExists($this->application->paths->public() . '/robots.txt');
        self::assertSame([], $cache->files());
    }

    private function render(string $path): string
    {
        $response = $this->request($path);
        self::assertSame(200, $response->status, $response->body);

        return $response->body;
    }

    private function request(string $path): Response
    {
        return $this->application->frontController()->handle('GET', $path);
    }
}
