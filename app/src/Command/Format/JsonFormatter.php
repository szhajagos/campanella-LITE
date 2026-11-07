<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Format;

use Campanella\Lite\Command\Output;
use Campanella\Lite\Command\Result\BatchResult;
use Campanella\Lite\Command\Result\LineResult;
use Campanella\Lite\I18n\Message;
use Campanella\Lite\I18n\Translator;
use Campanella\Lite\Validation\Violation;

/**
 * Renders a batch result as JSON for programs: the admin dashboard and the
 * future AI module. Every message carries its stable key and parameters
 * next to the translated text, so programs never have to parse prose.
 */
final class JsonFormatter
{
    public function __construct(private readonly Translator $translator)
    {
    }

    public function format(BatchResult $result, string $locale = Translator::FALLBACK_LOCALE): string
    {
        return json_encode(
            $this->toArray($result, $locale),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(BatchResult $result, string $locale = Translator::FALLBACK_LOCALE): array
    {
        return [
            'ok' => $result->isSuccess(),
            'outcome' => $result->outcome->value,
            'dry_run' => $result->dryRun,
            'summary' => $this->message($result->summary, $locale),
            'lines' => array_map(fn (LineResult $line): array => $this->line($line, $locale), $result->lines),
            'violations' => array_map(fn (Violation $violation): array => [
                'code' => $violation->code,
                'path' => $violation->path,
                'params' => (object) $violation->params,
                'text' => $this->translator->translateViolation($violation, $locale),
            ], $result->violations),
            'changed_files' => $result->changedFiles,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function line(LineResult $line, string $locale): array
    {
        return [
            'line' => $line->line,
            'source' => $line->source,
            'command' => $line->command,
            'status' => $line->status->value,
            'message' => $this->message($line->message, $locale),
            'output' => $line->output === null ? null : [
                'type' => $line->output->type,
                'value' => $this->value($line->output, $locale),
            ],
        ];
    }

    private function value(Output $output, string $locale): mixed
    {
        if ($output->type === Output::VALUE) {
            return $output->value;
        }

        $translate = function (mixed $item) use (&$translate, $locale): mixed {
            if ($item instanceof Message) {
                return $this->translator->translate($item, $locale);
            }

            return is_array($item) ? array_map($translate, $item) : $item;
        };

        return $translate($output->value);
    }

    /**
     * @return array{key: string, params: object, text: string}
     */
    private function message(Message $message, string $locale): array
    {
        return [
            'key' => $message->key,
            'params' => (object) $message->params,
            'text' => $this->translator->translate($message, $locale),
        ];
    }
}
