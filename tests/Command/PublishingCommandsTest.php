<?php

declare(strict_types=1);

namespace Campanella\Lite\Tests\Command;

use Campanella\Lite\Command\Result\Outcome;
use Campanella\Lite\Render\PageCache;

/**
 * Redirects, build and cache commands, and how batches affect generated pages.
 */
final class PublishingCommandsTest extends CommandTestCase
{
    public function testChangingAnAliasCreatesARedirect(): void
    {
        $project = $this->apply('page alias 2 /team');

        self::assertSame(['/about' => ['page' => 2, 'language' => 'en']], $project->redirects->toArray());
        self::assertFileExists($this->projectDir . '/redirects.yaml');
    }

    public function testRemovingAnAliasCreatesARedirect(): void
    {
        $project = $this->apply('page alias 2 --remove');

        self::assertSame(['page' => 2, 'language' => 'en'], $project->redirects->find('/about'));
    }

    public function testRenamingTwiceKeepsEveryOldAddressPointingToThePage(): void
    {
        $this->apply('page alias 2 /team');
        $project = $this->apply('page alias 2 /people');

        self::assertSame(['/about', '/team'], array_keys($project->redirects->toArray()));
    }

    public function testARedirectEndsWhenAnotherPageTakesTheAddress(): void
    {
        $this->apply('page alias 2 /team');
        $project = $this->apply('page alias 1 /about');

        self::assertNull($project->redirects->find('/about'));
        self::assertFileDoesNotExist($this->projectDir . '/redirects.yaml');
    }

    public function testARedirectEndsWhenThePageGetsItsAddressBack(): void
    {
        $this->apply('page alias 2 /team');
        $project = $this->apply('page alias 2 /about');

        self::assertSame(['/team'], array_keys($project->redirects->toArray()));
    }

    public function testRedirectsOfADeletedPageAreRemoved(): void
    {
        $this->apply('page alias 2 /team');
        $project = $this->apply('page delete 2 --force');

        self::assertTrue($project->redirects->isEmpty());
    }

    public function testRedirectsInARemovedLanguageAreRemoved(): void
    {
        $this->apply("language add hu\nset page.2.title.hu Rólunk\npage alias 2 /rolunk --lang=hu");
        $this->apply('page alias 2 /csapat --lang=hu');
        self::assertNotNull($this->project()->redirects->find('/hu/rolunk'));

        $project = $this->apply('language remove hu --force');

        self::assertTrue($project->redirects->isEmpty());
    }

    public function testListsAndRemovesRedirects(): void
    {
        $this->apply('page alias 2 /team');

        $list = $this->execute('list redirects');
        /** @var array{rows: list<list<string>>} $table */
        $table = $list->lines[0]->output?->value;
        self::assertSame([['/about', '2', 'en', '/team']], $table['rows']);

        $project = $this->apply('redirect remove /about');
        self::assertTrue($project->redirects->isEmpty());

        $this->assertFails('redirect remove /about', 'redirect.not_found');
    }

    public function testCheckReportsARedirectToAMissingPage(): void
    {
        file_put_contents($this->projectDir . '/redirects.yaml', "/old: { page: 42, language: en }\n");

        $result = $this->execute('check');
        /** @var array{rows: list<list<mixed>>} $table */
        $table = $result->lines[0]->output?->value;

        self::assertSame('redirect./old', $table['rows'][0][0]);
    }

    public function testBuildGeneratesPublishedPages(): void
    {
        $result = $this->execute('build');

        self::assertSame('ok.built', $result->lines[0]->message->key);
        self::assertSame(2, $result->lines[0]->message->params['count']);
        self::assertFileExists($this->publicDir() . '/index.html');
        self::assertFileExists($this->publicDir() . '/about/index.html');
        self::assertSame(['about/index.html', 'index.html'], $this->cache()->files());
    }

    public function testBuildsOnePage(): void
    {
        $result = $this->execute('build /about');

        self::assertSame(1, $result->lines[0]->message->params['count']);
        self::assertSame(['about/index.html'], $this->cache()->files());
    }

    public function testBuildSeesTheChangesOfItsBatch(): void
    {
        $result = $this->execute("set page.2.title \"Our team\"\nbuild 2");

        self::assertSame(Outcome::Applied, $result->outcome);
        self::assertStringContainsString('<title>Our team – Example Ltd.</title>', (string) file_get_contents($this->publicDir() . '/about/index.html'));
    }

    public function testADryRunBuildsNothing(): void
    {
        $result = $this->execute("@dry-run\nbuild");

        self::assertSame('ok.build_planned', $result->lines[0]->message->key);
        self::assertSame([], $this->cache()->files());
    }

    public function testARejectedBatchBuildsNothing(): void
    {
        $this->execute("build\nget page.99");

        self::assertSame([], $this->cache()->files());
    }

    public function testEveryCommittedChangeClearsGeneratedPages(): void
    {
        $this->execute('build');
        self::assertCount(2, $this->cache()->files());

        $this->apply('set site.name Changed');

        self::assertSame([], $this->cache()->files());
        self::assertFileDoesNotExist($this->publicDir() . '/about/index.html');
    }

    public function testCacheClear(): void
    {
        $this->execute('build');
        $result = $this->execute('cache clear');

        self::assertSame(['key' => 'ok.cache_cleared', 'count' => 2], ['key' => $result->lines[0]->message->key, 'count' => $result->lines[0]->message->params['count']]);
        self::assertSame([], $this->cache()->files());
    }

    private function publicDir(): string
    {
        return $this->application->paths->public();
    }

    private function cache(): PageCache
    {
        return $this->application->pageCache();
    }
}
