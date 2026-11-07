<?php

declare(strict_types=1);

namespace Campanella\Lite\Tests\Command;

use Campanella\Lite\Model\ContainerType;
use PHPUnit\Framework\Attributes\DataProvider;

final class LayoutCommandsTest extends CommandTestCase
{
    public function testCreatesEmptyAndCopiedLayouts(): void
    {
        $project = $this->apply("layout create blank\nlayout create landing --from=default --title=\"Landing page\"");

        self::assertSame([], $project->layout('blank')?->regions);
        self::assertSame($project->layout('default')?->columnPaths(), $project->layout('landing')?->columnPaths());
        self::assertSame('Landing page', $project->layout('landing')?->title->get('en'));
        self::assertSame('Default layout', $project->layout('default')?->title->get('en'));
    }

    public function testDeletesAnUnusedLayout(): void
    {
        $this->apply('layout create temp');
        $project = $this->apply('layout delete temp');

        self::assertNull($project->layout('temp'));
    }

    public function testAddsRegionsInPlace(): void
    {
        $project = $this->apply("layout region add default hero --after=nav --container=fluid\nlayout region add default notice --before=header\nlayout region add default bottom");
        $regions = array_map(static fn ($region) => $region->name, $project->layout('default')->regions ?? []);

        self::assertSame(['notice', 'header', 'nav', 'hero', 'body', 'footer', 'bottom'], $regions);
        self::assertSame(ContainerType::Fluid, $project->layout('default')?->region('hero')?->container);
    }

    public function testAddsAndRemovesColumns(): void
    {
        $project = $this->apply("set layout.default.body.main.width 6\nlayout column add default body.aside --width=2 --before=main");
        self::assertSame(['body.aside', 'body.main', 'body.sidebar'], array_values(array_filter($project->layout('default')?->columnPaths() ?? [], static fn (string $p): bool => str_starts_with($p, 'body.'))));
        self::assertSame(2, $project->layout('default')?->column('body.aside')?->width);

        $this->assertSame('invalid', $this->execute('layout column add default footer.extra --width=4')->outcome->value);
    }

    public function testRemovesRegionsAndColumnsWithForce(): void
    {
        $this->assertFails('layout column remove default body.main', 'layout.column_in_use');
        $project = $this->apply('layout column remove default body.main --force');

        self::assertNull($project->layout('default')?->column('body.main'));
        self::assertArrayNotHasKey('body.main', $project->page(2)->placements ?? []);

        $project = $this->apply('layout region remove default footer');
        self::assertNull($project->layout('default')?->region('footer'));
    }

    public function testPlacesAndUnplacesDefaultContent(): void
    {
        $project = $this->apply('layout place default body.sidebar team --at=1');
        self::assertSame(['team', 'contact-box'], $project->layout('default')?->column('body.sidebar')?->content);

        $project = $this->apply('layout unplace default body.sidebar contact-box');
        self::assertSame(['team'], $project->layout('default')?->column('body.sidebar')?->content);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function failures(): iterable
    {
        yield 'create existing' => ['layout create default', 'layout.exists'];
        yield 'copy unknown' => ['layout create x --from=wide', 'layout.not_found'];
        yield 'delete used' => ['layout delete default', 'layout.in_use'];
        yield 'region exists' => ['layout region add default body', 'layout.region_exists'];
        yield 'region invalid name' => ['layout region add default Hero', 'value.invalid_name'];
        yield 'region after unknown' => ['layout region add default x --after=top', 'layout.region_not_found'];
        yield 'region after and before' => ['layout region add default x --after=nav --before=body', 'command.conflicting_options'];
        yield 'region invalid container' => ['layout region add default x --container=wide', 'value.invalid_choice'];
        yield 'remove unknown region' => ['layout region remove default top', 'layout.region_not_found'];
        yield 'remove used region' => ['layout region remove default body', 'layout.region_in_use'];
        yield 'column without region part' => ['layout column add default aside --width=2', 'value.invalid_column_path'];
        yield 'column in unknown region' => ['layout column add default top.x --width=2', 'layout.region_not_found'];
        yield 'column exists' => ['layout column add default body.main --width=2', 'layout.column_exists'];
        yield 'column without width' => ['layout column add default footer.x', 'command.missing_option'];
        yield 'column width not a number' => ['layout column add default footer.x --width=wide', 'value.not_a_number'];
        yield 'column with unknown menu' => ['layout column add default footer.x --width=1 --menu=side', 'menu.not_found'];
        yield 'place twice' => ['layout place default body.sidebar contact-box', 'placement.already_present'];
        yield 'unplace missing' => ['layout unplace default body.main intro', 'placement.not_present'];
    }

    #[DataProvider('failures')]
    public function testFailures(string $input, string $key): void
    {
        $this->assertFails($input, $key);
    }
}
