<?php

declare(strict_types=1);

namespace Campanella\Lite\Tests\Command;

use Campanella\Lite\Model\PageStatus;
use PHPUnit\Framework\Attributes\DataProvider;

final class PageCommandsTest extends CommandTestCase
{
    public function testCreatesAPageWithTheNextId(): void
    {
        $project = $this->apply('page create --title="Contact" --alias=/contact --description="Write to us" --status=published');
        $page = $project->page(3);

        self::assertNotNull($page);
        self::assertSame('Contact', $page->title->get('en'));
        self::assertSame('/contact', $page->path('en'));
        self::assertSame('Write to us', $page->description->get('en'));
        self::assertSame(PageStatus::Published, $page->status);
        self::assertSame('default', $page->layout);
        self::assertSame(4, $project->site->nextPageId);
    }

    public function testNewPagesAreDraftsAtTheirSystemPath(): void
    {
        $result = $this->execute("page create --title=One\npage create --title=Two");

        self::assertSame(['page' => 3, 'path' => '/node/3'], $result->lines[0]->message->params);
        self::assertSame(4, $result->lines[1]->message->params['page']);
        self::assertSame(PageStatus::Draft, $this->project()->page(4)?->status);
    }

    public function testDeletesAnUnreferencedPage(): void
    {
        $this->apply("page create --title=Temp\npage delete 3");

        self::assertNull($this->project()->page(3));
    }

    public function testForcedDeleteRemovesMenuItemsAndTheFrontPage(): void
    {
        $project = $this->apply('page delete 1 --force');

        self::assertNull($project->page(1));
        self::assertNull($project->site->frontPage);
        self::assertSame(['About', 'Blog'], array_map(static fn ($item) => $item->label->get('en'), $project->menu('main')->items ?? []));
    }

    public function testSetsAndRemovesAliases(): void
    {
        $page = $this->apply('page alias 1 /home')->page(1);
        self::assertSame('/home', $page?->path('en'));

        $page = $this->apply('page alias /home --remove')->page(1);
        self::assertSame('/node/1', $page?->path('en'));
    }

    public function testPublishesAndUnpublishes(): void
    {
        $page = $this->apply('page unpublish /about')->page(2);
        self::assertSame(PageStatus::Draft, $page?->status);

        $page = $this->apply('page publish 2')->page(2);
        self::assertSame(PageStatus::Published, $page?->status);
    }

    public function testPlacingStartsFromTheLayoutDefaults(): void
    {
        $project = $this->apply('page place 1 body.sidebar team');

        self::assertSame(['contact-box', 'team'], $project->page(1)?->placements['body.sidebar']);
    }

    public function testPlacesAtAPosition(): void
    {
        $project = $this->apply('page place 2 body.main site-title footer-text --at=2');

        self::assertSame(['intro', 'site-title', 'footer-text', 'team'], $project->page(2)?->placements['body.main']);
    }

    public function testUnplacesAndResets(): void
    {
        $project = $this->apply('page unplace 2 body.sidebar contact-box');
        self::assertSame([], $project->page(2)?->placements['body.sidebar']);

        $project = $this->apply('page reset 2 body.sidebar');
        self::assertArrayNotHasKey('body.sidebar', $project->page(2)->placements ?? []);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function failures(): iterable
    {
        yield 'create without title' => ['page create --alias=/x', 'command.missing_option'];
        yield 'create with unknown layout' => ['page create --title=X --layout=wide', 'layout.not_found'];
        yield 'create with invalid status' => ['page create --title=X --status=hidden', 'value.invalid_choice'];
        yield 'create in a disabled language' => ['page create --title=X --lang=hu', 'language.not_enabled'];
        yield 'delete unknown page' => ['page delete 99', 'page.not_found'];
        yield 'delete referenced page' => ['page delete 2', 'page.in_use'];
        yield 'alias without value' => ['page alias 2', 'command.missing_argument'];
        yield 'alias with value and remove' => ['page alias 2 /x --remove', 'command.too_many_arguments'];
        yield 'place into unknown column' => ['page place 2 body.center intro', 'layout.column_not_found'];
        yield 'place unknown content' => ['page place 2 body.main gallery', 'content.not_found'];
        yield 'place twice' => ['page place 2 body.main intro', 'placement.already_present'];
        yield 'place duplicate in one command' => ['page place 2 body.sidebar team team', 'placement.already_present'];
        yield 'place without content' => ['page place 2 body.main', 'command.missing_argument'];
        yield 'place at invalid position' => ['page place 2 body.main site-title --at=0', 'value.invalid_position'];
        yield 'unplace missing content' => ['page unplace 2 body.sidebar team', 'placement.not_present'];
    }

    #[DataProvider('failures')]
    public function testFailures(string $input, string $key): void
    {
        $this->assertFails($input, $key);
    }

    public function testADuplicateAliasIsAValidationError(): void
    {
        $result = $this->execute('page create --title=X --alias=/about');

        self::assertSame('invalid', $result->outcome->value);
        self::assertSame('page.duplicate_alias', $result->violations[0]->code);
    }
}
