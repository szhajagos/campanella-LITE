<?php

declare(strict_types=1);

namespace Campanella\Lite\I18n;

use Campanella\Lite\Storage\YamlCodec;
use Campanella\Lite\Validation\Violation;
use InvalidArgumentException;

/**
 * Turns messages into text in a given locale.
 *
 * Catalogues are flat YAML files, "messages.{locale}.yaml", mapping keys to
 * texts with {placeholders}. A key missing from the requested locale falls
 * back to English; a key missing everywhere is shown as the key itself, so
 * nothing is ever lost.
 */
final class Translator
{
    public const FALLBACK_LOCALE = 'en';

    private const LOCALE_PATTERN = '/^[a-z]{2,3}(?:-[A-Z]{2})?$/';

    /** @var array<string, array<string, string>> */
    private array $catalogues = [];

    public function __construct(private readonly string $directory)
    {
    }

    public function translate(Message $message, string $locale = self::FALLBACK_LOCALE): string
    {
        $template = $this->template($message->key, $locale);

        if ($template === null) {
            return $message->key . ($message->params === [] ? '' : ' ' . json_encode($message->params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $replacements = [];

        foreach ($message->params as $name => $value) {
            $replacements['{' . $name . '}'] = (string) $value;
        }

        return strtr($template, $replacements);
    }

    public function translateViolation(Violation $violation, string $locale = self::FALLBACK_LOCALE): string
    {
        return $this->translate(new Message('violation.' . $violation->code, $violation->params), $locale);
    }

    public function has(string $key, string $locale = self::FALLBACK_LOCALE): bool
    {
        return isset($this->catalogue($locale)[$key]);
    }

    /**
     * @return list<string> locales with a catalogue file
     */
    public function locales(): array
    {
        $locales = [];

        foreach (glob($this->directory . '/messages.*.yaml') ?: [] as $file) {
            $locale = substr(basename($file), strlen('messages.'), -strlen('.yaml'));

            if (preg_match(self::LOCALE_PATTERN, $locale) === 1) {
                $locales[] = $locale;
            }
        }

        sort($locales);

        return $locales;
    }

    /**
     * @return array<string, string>
     */
    public function catalogue(string $locale): array
    {
        if (preg_match(self::LOCALE_PATTERN, $locale) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid locale "%s".', $locale));
        }

        if (!isset($this->catalogues[$locale])) {
            $this->catalogues[$locale] = $this->load($locale);
        }

        return $this->catalogues[$locale];
    }

    private function template(string $key, string $locale): ?string
    {
        return $this->catalogue($locale)[$key] ?? $this->catalogue(self::FALLBACK_LOCALE)[$key] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function load(string $locale): array
    {
        $file = sprintf('%s/messages.%s.yaml', $this->directory, $locale);

        if (!is_file($file)) {
            return [];
        }

        $contents = file_get_contents($file);
        $messages = [];

        foreach (YamlCodec::decode($contents === false ? '' : $contents, basename($file)) as $key => $text) {
            if (is_string($key) && is_string($text)) {
                $messages[$key] = $text;
            }
        }

        return $messages;
    }
}
