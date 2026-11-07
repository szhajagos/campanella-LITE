<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Format;

use Campanella\Lite\Command\Output;
use Campanella\Lite\Command\Result\BatchResult;
use Campanella\Lite\Command\Result\LineResult;
use Campanella\Lite\Command\Result\LineStatus;
use Campanella\Lite\I18n\Message;
use Campanella\Lite\I18n\Translator;
use Campanella\Lite\Storage\YamlCodec;

/**
 * Renders a batch result as plain text for the admin response box and the CLI:
 *
 *     [1] Ok. site.name = Example Ltd.
 *     [2] Error: No page with the id or alias "9".
 *     [3] Skipped: an earlier line failed.
 *     → Batch not executed, nothing was changed.
 */
final class TextFormatter
{
    private const INDENT = '    ';

    public function __construct(private readonly Translator $translator)
    {
    }

    public function format(BatchResult $result, string $locale = Translator::FALLBACK_LOCALE): string
    {
        $out = [];

        foreach ($result->lines as $line) {
            $out[] = $this->line($line, $locale);

            if ($line->output !== null) {
                foreach ($this->output($line->output, $locale) as $outputLine) {
                    $out[] = $outputLine === '' ? '' : self::INDENT . $outputLine;
                }
            }
        }

        if ($result->violations !== []) {
            $out[] = $this->translate(new Message('format.violations'), $locale);

            foreach ($result->violations as $violation) {
                $out[] = sprintf('%s- %s (%s)', self::INDENT, $this->translator->translateViolation($violation, $locale), $violation->path);
            }
        }

        $out[] = '→ ' . $this->translate($result->summary, $locale);

        return implode("\n", $out) . "\n";
    }

    private function line(LineResult $line, string $locale): string
    {
        $message = $this->translate($line->message, $locale);

        $text = match ($line->status) {
            LineStatus::Ok => $this->translate(new Message('format.ok'), $locale) . ' ' . $message,
            LineStatus::Error => $this->translate(new Message('format.error'), $locale) . ' ' . $message,
            LineStatus::Skipped => $message,
        };

        return sprintf('[%d] %s', $line->line, $text);
    }

    /**
     * @return list<string>
     */
    private function output(Output $output, string $locale): array
    {
        if ($output->type === Output::LINES) {
            /** @var list<string|Message> $lines */
            $lines = $output->value;

            return array_map(fn (string|Message $line): string => $this->cell($line, $locale), $lines);
        }

        if ($output->type === Output::TABLE) {
            /** @var array{columns: list<string>, rows: list<list<string|Message>>} $table */
            $table = $output->value;

            return $this->table($table['columns'], $table['rows'], $locale);
        }

        if (!is_array($output->value)) {
            return [];
        }

        return explode("\n", rtrim(YamlCodec::encode($output->value), "\n"));
    }

    /**
     * @param list<string>               $columns
     * @param list<list<string|Message>> $rows
     *
     * @return list<string>
     */
    private function table(array $columns, array $rows, string $locale): array
    {
        $cells = [$columns];

        foreach ($rows as $row) {
            $cells[] = array_map(fn (string|Message $cell): string => $this->cell($cell, $locale), $row);
        }

        $widths = [];

        foreach ($cells as $row) {
            foreach ($row as $index => $cell) {
                $widths[$index] = max($widths[$index] ?? 0, mb_strwidth($cell));
            }
        }

        $lines = [];

        foreach ($cells as $row) {
            $padded = [];

            foreach ($row as $index => $cell) {
                $padded[] = $cell . str_repeat(' ', $widths[$index] - mb_strwidth($cell));
            }

            $lines[] = rtrim(implode('  ', $padded));
        }

        return $lines;
    }

    private function cell(string|Message $cell, string $locale): string
    {
        return $cell instanceof Message ? $this->translate($cell, $locale) : $cell;
    }

    private function translate(Message $message, string $locale): string
    {
        return $this->translator->translate($message, $locale);
    }
}
