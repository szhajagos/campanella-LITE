<?php

declare(strict_types=1);

namespace Campanella\Lite\Tests\I18n;

use Campanella\Lite\Application;
use Campanella\Lite\I18n\Message;
use Campanella\Lite\I18n\Translator;
use Campanella\Lite\Tests\Support\TemporaryDirectory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class TranslatorTest extends TestCase
{
    use TemporaryDirectory;

    public function testReplacesPlaceholders(): void
    {
        $translator = $this->translator(['greeting' => 'Hello {name}, you have {count} pages.']);

        self::assertSame('Hello Anna, you have 3 pages.', $translator->translate(new Message('greeting', ['name' => 'Anna', 'count' => 3])));
    }

    public function testFallsBackToEnglishAndThenToTheKey(): void
    {
        $translator = $this->translator(['only.english' => 'English', 'both' => 'English both'], ['both' => 'Magyar mindkettő']);

        self::assertSame('Magyar mindkettő', $translator->translate(new Message('both'), 'hu'));
        self::assertSame('English', $translator->translate(new Message('only.english'), 'hu'));
        self::assertSame('missing.key {"a":"/b"}', $translator->translate(new Message('missing.key', ['a' => '/b']), 'hu'));
        self::assertSame(['en', 'hu'], $translator->locales());
        self::assertTrue($translator->has('both', 'hu'));
        self::assertFalse($translator->has('only.english', 'hu'));
    }

    public function testRejectsUnsafeLocales(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->translator([])->translate(new Message('x'), '../../etc');
    }

    /**
     * Every message key and violation code used in the source code must have an English text.
     */
    public function testTheEnglishCatalogueCoversEveryKeyInTheCode(): void
    {
        $translator = new Translator(Application::translationsDirectory());
        $source = dirname(__DIR__, 2) . '/app/src';
        $missing = [];

        /** @var iterable<string, \SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $path => $file) {
            $code = (string) file_get_contents($path);

            preg_match_all("/(?:new Message|because|->error|->ok|CommandResult::ok)\\(\\s*(?:\\\$number,\\s*)?'([a-z_]+\\.[a-z_.]+)'/", $code, $messages);
            preg_match_all("/->add\\(\\s*'([a-z_]+\\.[a-z_.]+)'/", $code, $violations);

            foreach ($messages[1] as $key) {
                if (!$translator->has($key)) {
                    $missing[] = $key . ' (' . basename($path) . ')';
                }
            }

            foreach ($violations[1] as $code) {
                if (!$translator->has('violation.' . $code)) {
                    $missing[] = 'violation.' . $code . ' (' . basename($path) . ')';
                }
            }
        }

        self::assertSame([], $missing);
    }

    public function testEveryCommandHasHelp(): void
    {
        $application = new Application(new \Campanella\Lite\Paths($this->temporaryDirectory()));
        $translator = $application->translator();

        foreach ($application->registry()->all() as $handler) {
            self::assertTrue($translator->has($handler->signature()->helpKey()), $handler->signature()->name);
        }
    }

    /**
     * @param array<string, string> $english
     * @param array<string, string> $hungarian
     */
    private function translator(array $english, array $hungarian = []): Translator
    {
        $directory = $this->temporaryDirectory();
        file_put_contents($directory . '/messages.en.yaml', \Campanella\Lite\Storage\YamlCodec::encode($english));

        if ($hungarian !== []) {
            file_put_contents($directory . '/messages.hu.yaml', \Campanella\Lite\Storage\YamlCodec::encode($hungarian));
        }

        return new Translator($directory);
    }
}
