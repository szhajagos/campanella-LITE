<?php

declare(strict_types=1);

namespace Campanella\Lite\Tests\Routing;

use Campanella\Lite\Model\LocalizedText;
use Campanella\Lite\Model\Page;
use Campanella\Lite\Model\PageStatus;
use Campanella\Lite\Model\Project;
use Campanella\Lite\Model\Redirects;
use Campanella\Lite\Model\Site;
use Campanella\Lite\Routing\Route;
use Campanella\Lite\Routing\Router;
use Campanella\Lite\Routing\Urls;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private static function project(): Project
    {
        $site = new Site('Test', 'https://example.com', ['en', 'hu'], 'en', 'default', frontPage: 1, nextPageId: 6);

        return new Project($site, [], [
            new Page(1, LocalizedText::of('en', 'Home'), 'default', PageStatus::Published, LocalizedText::of('en', '/home')),
            new Page(2, LocalizedText::of('en', 'About'), 'default', PageStatus::Published, LocalizedText::fromArray(['en' => '/about', 'hu' => '/rolunk'])),
            new Page(3, LocalizedText::of('en', 'No alias'), 'default', PageStatus::Published),
            new Page(4, LocalizedText::of('en', 'Draft'), 'default', PageStatus::Draft, LocalizedText::of('en', '/draft')),
            new Page(5, LocalizedText::of('en', 'Services'), 'default', PageStatus::Published, LocalizedText::of('en', '/services/web')),
        ], [], [], Redirects::none()
            ->with('/about-us', 2, 'en')
            ->with('/hu/regi', 2, 'hu')
            ->with('/gone', 99, 'en')
            ->with('/secret', 4, 'en'));
    }

    /**
     * @return iterable<string, array{string, string, ?int, string, ?string}>
     */
    public static function routes(): iterable
    {
        yield 'front page' => ['/', Route::PAGE, 1, 'en', null];
        yield 'front page in hungarian' => ['/hu/', Route::PAGE, 1, 'hu', null];
        yield 'front page in hungarian without slash' => ['/hu', Route::PAGE, 1, 'hu', null];
        yield 'alias' => ['/about', Route::PAGE, 2, 'en', null];
        yield 'alias with trailing slash' => ['/about/', Route::PAGE, 2, 'en', null];
        yield 'nested alias' => ['/services/web', Route::PAGE, 5, 'en', null];
        yield 'hungarian alias' => ['/hu/rolunk', Route::PAGE, 2, 'hu', null];
        yield 'node without alias' => ['/node/3', Route::PAGE, 3, 'en', null];
        yield 'hungarian node without alias' => ['/hu/node/3', Route::PAGE, 3, 'hu', null];
        yield 'node with alias redirects' => ['/node/2', Route::REDIRECT, null, 'en', '/about'];
        yield 'hungarian node with alias redirects' => ['/hu/node/2', Route::REDIRECT, null, 'hu', '/hu/rolunk'];
        yield 'front page alias redirects to root' => ['/home', Route::REDIRECT, null, 'en', '/'];
        yield 'front page node redirects to root' => ['/node/1', Route::REDIRECT, null, 'en', '/'];
        yield 'default language prefix redirects' => ['/en/about', Route::REDIRECT, null, 'en', '/about'];
        yield 'old alias redirects' => ['/about-us', Route::REDIRECT, null, 'en', '/about'];
        yield 'old hungarian alias redirects' => ['/hu/regi', Route::REDIRECT, null, 'hu', '/hu/rolunk'];
        yield 'english alias under hungarian prefix' => ['/hu/about', Route::NOT_FOUND, null, 'hu', null];
        yield 'draft' => ['/draft', Route::NOT_FOUND, null, 'en', null];
        yield 'draft node' => ['/node/4', Route::NOT_FOUND, null, 'en', null];
        yield 'redirect to a draft' => ['/secret', Route::NOT_FOUND, null, 'en', null];
        yield 'redirect to a missing page' => ['/gone', Route::NOT_FOUND, null, 'en', null];
        yield 'unknown' => ['/nothing', Route::NOT_FOUND, null, 'en', null];
        yield 'unknown in hungarian' => ['/hu/semmi', Route::NOT_FOUND, null, 'hu', null];
        yield 'missing page id' => ['/node/42', Route::NOT_FOUND, null, 'en', null];
        yield 'encoded traversal' => ['/%2e%2e/etc/passwd', Route::NOT_FOUND, null, 'en', null];
        yield 'double slash' => ['//about', Route::NOT_FOUND, null, 'en', null];
        yield 'backslash' => ['/about\\x', Route::NOT_FOUND, null, 'en', null];
    }

    #[DataProvider('routes')]
    public function testResolves(string $path, string $type, ?int $page, string $language, ?string $location): void
    {
        $route = (new Router())->resolve(self::project(), $path);

        self::assertSame($type, $route->type);
        self::assertSame($page, $route->page?->id);
        self::assertSame($language, $route->language);
        self::assertSame($location, $route->location);
    }

    public function testAFrontPageThatIsADraftIsNotFound(): void
    {
        $project = self::project();
        $project = $project->withPage(($project->page(1) ?? self::fail())->with(status: PageStatus::Draft));

        self::assertSame(Route::NOT_FOUND, (new Router())->resolve($project, '/')->type);
    }

    public function testBuildsCanonicalAddressesAndFiles(): void
    {
        $project = self::project();
        $site = $project->site;

        $front = $project->page(1);
        $plain = $project->page(3);
        self::assertNotNull($front);
        self::assertNotNull($plain);

        self::assertSame('/', Urls::page($site, $front, 'en'));
        self::assertSame('/hu/', Urls::page($site, $front, 'hu'));
        self::assertSame('/hu/node/3', Urls::page($site, $plain, 'hu'));
        self::assertSame('index.html', Urls::file('/'));
        self::assertSame('hu/index.html', Urls::file('/hu/'));
        self::assertSame('services/web/index.html', Urls::file('/services/web'));
    }
}
