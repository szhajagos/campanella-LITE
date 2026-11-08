<?php

declare(strict_types=1);

namespace Campanella\Lite\Tests\I18n;

use Campanella\Lite\Application;
use Campanella\Lite\I18n\Message;
use Campanella\Lite\I18n\Translator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Checks every shipped translation against the English catalogue.
 */
final class CataloguesTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function translations(): iterable
    {
        $translator = new Translator(Application::translationsDirectory());

        foreach ($translator->locales() as $locale) {
            if ($locale !== Translator::FALLBACK_LOCALE) {
                yield $locale => [$locale];
            }
        }
    }

    public function testThereIsAHungarianCatalogue(): void
    {
        self::assertContains('hu', (new Translator(Application::translationsDirectory()))->locales());
    }

    #[DataProvider('translations')]
    public function testUsesOnlyKnownKeysWithTheSamePlaceholders(string $locale): void
    {
        $translator = new Translator(Application::translationsDirectory());
        $english = $translator->catalogue('en');
        $problems = [];

        foreach ($translator->catalogue($locale) as $key => $text) {
            if (!isset($english[$key])) {
                $problems[] = 'unknown key ' . $key;
                continue;
            }

            if (self::placeholders($text) !== self::placeholders($english[$key])) {
                $problems[] = sprintf('placeholders of %s: %s instead of %s', $key, implode(',', self::placeholders($text)), implode(',', self::placeholders($english[$key])));
            }

            if (trim($text) === '') {
                $problems[] = 'empty text for ' . $key;
            }
        }

        self::assertSame([], $problems);
    }

    public function testTheHungarianCatalogueIsComplete(): void
    {
        $translator = new Translator(Application::translationsDirectory());
        $missing = array_keys(array_diff_key($translator->catalogue('en'), $translator->catalogue('hu')));

        self::assertSame([], $missing);
    }

    public function testMissingHungarianTextsFallBackToEnglish(): void
    {
        $translator = new Translator(Application::translationsDirectory());

        self::assertSame('Rendben.', $translator->translate(new Message('format.ok'), 'hu'));
        self::assertSame('Az oldal nem található', $translator->translate(new Message('theme.not_found_title'), 'hu'));
        self::assertSame('future.key', $translator->translate(new Message('future.key'), 'hu'));
    }

    /**
     * @return list<string>
     */
    private static function placeholders(string $text): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $text, $matches);
        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }
}
