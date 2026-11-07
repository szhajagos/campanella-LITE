<?php

declare(strict_types=1);

namespace Campanella\Lite\Tests\Command;

use Campanella\Lite\Model\Menu;
use Campanella\Lite\Model\MenuItem;
use PHPUnit\Framework\Attributes\DataProvider;

final class MenuAndLanguageCommandsTest extends CommandTestCase
{
    public function testCreatesAndDeletesMenus(): void
    {
        $project = $this->apply('menu create footer');
        self::assertSame([], $project->menu('footer')?->items);

        $project = $this->apply('menu delete footer');
        self::assertNull($project->menu('footer'));
    }

    public function testForcedMenuDeleteClearsLayoutColumns(): void
    {
        $this->assertFails('menu delete main', 'menu.in_use');
        $project = $this->apply('menu delete main --force');

        self::assertNull($project->layout('default')?->column('nav.menu')?->menu);
    }

    public function testAddsItemsAtPositionsAndBelowParents(): void
    {
        $result = $this->execute(implode("\n", [
            'menu item add main --label=Contact --url=/contact',
            'menu item add main --label=First --page=/about --at=1',
            'menu item add main --label=Child --url=https://example.com/child --parent=3',
            'menu item add main --label=Grandchild --url=/gc --parent=3.1',
        ]));

        self::assertSame(['4', '1', '3.1', '3.1.1'], array_map(static fn ($line) => $line->message->params['item'] ?? null, $result->lines));
        $menu = $this->project()->menu('main');
        self::assertInstanceOf(Menu::class, $menu);
        self::assertSame(['First', 'Home', 'About', 'Blog', 'Contact'], self::labels($menu->items));
        self::assertSame(2, $menu->items[0]->page);
        self::assertSame(['Child'], self::labels($menu->items[2]->children));
        self::assertSame(['Grandchild'], self::labels($menu->items[2]->children[0]->children));
    }

    public function testRemovesItemsWithTheirChildren(): void
    {
        $this->apply('menu item add main --label=Child --url=/c --parent=2');
        self::assertCount(1, $this->project()->menu('main')->items[1]->children ?? []);
        $this->apply('menu item remove main 2.1');
        self::assertSame([], $this->project()->menu('main')?->items[1]->children);

        $project = $this->apply('menu item remove main 1');
        self::assertSame(['About', 'Blog'], self::labels($project->menu('main')->items ?? []));
    }

    public function testManagesLanguages(): void
    {
        $project = $this->apply("language add hu\nlanguage add de");
        self::assertSame(['en', 'hu', 'de'], $project->site->languages);

        // A new default language needs the required texts in that language.
        self::assertSame('invalid', $this->execute('language default hu')->outcome->value);

        $project = $this->apply(implode("\n", [
            'set page.1.title.hu Kezdőlap',
            'set page.2.title.hu Rólunk',
            'set menu.main.items.1.label.hu Kezdőlap',
            'set menu.main.items.2.label.hu Rólunk',
            'set menu.main.items.3.label.hu Blog',
            'language default hu',
            'language remove de',
        ]));
        self::assertSame(['en', 'hu'], $project->site->languages);
        self::assertSame('hu', $project->site->defaultLanguage);

        // Same for a language added as the default right away.
        self::assertSame('invalid', $this->execute('language add fr --default')->outcome->value);
    }

    public function testForcedLanguageRemovalDeletesItsTexts(): void
    {
        $this->apply("language add hu\nset page.2.title.hu Rólunk\nset menu.main.items.1.label.hu Kezdőlap\ncontent translate intro hu --title=Bevezető\ncontent create only-hu --title=Csak --lang=hu");
        $this->assertFails('language remove hu', 'language.in_use');

        $project = $this->apply('language remove hu --force');

        self::assertSame(['en'], $project->site->languages);
        self::assertFalse($project->page(2)?->title->has('hu'));
        self::assertFalse($project->menu('main')?->items[0]->label->has('hu'));
        self::assertSame(['en'], $project->content('intro')?->languages());
        self::assertNull($project->content('only-hu'));
    }

    public function testCheckListsEveryProblem(): void
    {
        file_put_contents($this->projectDir . '/pages/2.yaml', str_replace('layout: default', 'layout: missing', (string) file_get_contents($this->projectDir . '/pages/2.yaml')));

        $result = $this->execute('check');

        self::assertSame('ok.check_problems', $result->lines[0]->message->key);
        /** @var array{rows: list<list<mixed>>} $table */
        $table = $result->lines[0]->output?->value;
        self::assertSame('page.2.layout', $table['rows'][0][0]);
    }

    public function testCheckOnACleanProject(): void
    {
        self::assertSame('ok.check_clean', $this->execute('check')->lines[0]->message->key);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function failures(): iterable
    {
        yield 'menu exists' => ['menu create main', 'menu.exists'];
        yield 'menu invalid name' => ['menu create Main', 'value.invalid_name'];
        yield 'item without label' => ['menu item add main --url=/x', 'command.missing_option'];
        yield 'item without target' => ['menu item add main --label=X', 'menu.item_target'];
        yield 'item with two targets' => ['menu item add main --label=X --url=/x --page=1', 'menu.item_target'];
        yield 'item to unknown page' => ['menu item add main --label=X --page=/nowhere', 'page.not_found'];
        yield 'item below unknown parent' => ['menu item add main --label=X --url=/x --parent=9', 'menu.item_not_found'];
        yield 'remove unknown item' => ['menu item remove main 4', 'menu.item_not_found'];
        yield 'remove invalid position' => ['menu item remove main 1.x', 'menu.item_not_found'];
        yield 'invalid language code' => ['language add Hungarian', 'value.invalid_language'];
        yield 'language exists' => ['language add en', 'language.exists'];
        yield 'remove default language' => ["language add hu\nlanguage remove en", 'language.is_default'];
        yield 'remove disabled language' => ['language remove hu', 'language.not_enabled'];
        yield 'default to disabled language' => ['language default hu', 'language.not_enabled'];
    }

    #[DataProvider('failures')]
    public function testFailures(string $input, string $key): void
    {
        $this->assertFails($input, $key);
    }

    /**
     * @param list<MenuItem> $items
     *
     * @return list<string|null>
     */
    private static function labels(array $items): array
    {
        return array_map(static fn (MenuItem $item): ?string => $item->label->get('en'), $items);
    }
}
