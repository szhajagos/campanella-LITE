<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Parser;

use Campanella\Lite\I18n\Message;

/**
 * Turns the text of the command box into commands.
 *
 *     @dry-run                                  # directive, only before the first command
 *     # a comment line
 *     page create --title="About us" --alias=/about
 *     content edit intro <<<                    # multi-line text until a ">>>" line
 *     Text of the **content**.
 *     >>>
 *
 * Words are separated by whitespace. Double quotes group words and may
 * contain \" \\ \n and \t escapes. A word that starts with "--" outside
 * quotes is an option. "#" outside quotes starts a comment.
 */
final class BatchParser
{
    public const TEXT_START = '<<<';
    public const TEXT_END = '>>>';
    public const DIRECTIVES = ['dry-run'];

    private const OPTION_NAME = '/^[a-z][a-z0-9-]*$/';

    public function parse(string $input): ParsedBatch
    {
        $lines = preg_split('/\r\n|\n|\r/', $input) ?: [];
        $commands = [];
        $errors = [];
        $dryRun = false;
        $count = count($lines);

        for ($index = 0; $index < $count; $index++) {
            $number = $index + 1;
            $raw = $lines[$index];
            $trimmed = trim($raw);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if ($trimmed === self::TEXT_END) {
                $errors[] = $this->error($number, 'syntax.unexpected_text_end', $trimmed);
                continue;
            }

            if (str_starts_with($trimmed, '@')) {
                $directive = trim(explode('#', substr($trimmed, 1), 2)[0]);

                if ($commands !== []) {
                    $errors[] = $this->error($number, 'syntax.directive_after_command', $trimmed, ['directive' => $directive]);
                } elseif (!in_array($directive, self::DIRECTIVES, true)) {
                    $errors[] = $this->error($number, 'syntax.unknown_directive', $trimmed, ['directive' => $directive]);
                } else {
                    $dryRun = true;
                }

                continue;
            }

            $tokens = $this->tokenize($raw);

            if ($tokens instanceof Message) {
                $errors[] = new SyntaxError($number, $tokens, $trimmed);
                continue;
            }

            $text = null;
            $last = $tokens === [] ? null : $tokens[array_key_last($tokens)];

            if ($last !== null && !$last['quoted'] && $last['value'] === self::TEXT_START) {
                array_pop($tokens);
                $body = [];
                $closed = false;

                for ($index++; $index < $count; $index++) {
                    if (trim($lines[$index]) === self::TEXT_END) {
                        $closed = true;
                        break;
                    }

                    $body[] = $lines[$index];
                }

                if (!$closed) {
                    $errors[] = $this->error($number, 'syntax.unclosed_text', $trimmed);
                    break;
                }

                $text = $body === [] ? '' : implode("\n", $body) . "\n";
            }

            $command = $this->command($number, $tokens, $text, $trimmed);

            if ($command instanceof SyntaxError) {
                $errors[] = $command;
            } else {
                $commands[] = $command;
            }
        }

        return new ParsedBatch($commands, $errors, $dryRun);
    }

    /**
     * @param list<array{value: string, quoted: bool, startsQuoted: bool}> $tokens
     */
    private function command(int $number, array $tokens, ?string $text, string $source): ParsedCommand|SyntaxError
    {
        $words = [];
        $options = [];

        foreach ($tokens as $token) {
            if ($token['startsQuoted'] || !str_starts_with($token['value'], '--')) {
                $words[] = $token['value'];
                continue;
            }

            $parts = explode('=', substr($token['value'], 2), 2);
            $name = $parts[0];

            if (preg_match(self::OPTION_NAME, $name) !== 1) {
                return $this->error($number, 'syntax.invalid_option', $source, ['option' => '--' . $name]);
            }

            if (array_key_exists($name, $options)) {
                return $this->error($number, 'syntax.duplicate_option', $source, ['option' => '--' . $name]);
            }

            $options[$name] = $parts[1] ?? true;
        }

        if ($words === []) {
            return $this->error($number, 'syntax.missing_command', $source);
        }

        return new ParsedCommand($number, $words, $options, $text, $source);
    }

    /**
     * Splits a line into words, honouring quotes, escapes and comments.
     *
     * A token is "quoted" if any part of it was written in quotes, so "<<<"
     * in quotes is an ordinary word. It "starts quoted" if its first character
     * is a quote, so "--x" in quotes is a word while --title="About us" is an option.
     *
     * @return list<array{value: string, quoted: bool, startsQuoted: bool}>|Message
     */
    private function tokenize(string $line): array|Message
    {
        $tokens = [];
        $value = '';
        $quoted = false;
        $startsQuoted = false;
        $inToken = false;
        $inQuotes = false;
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            if ($inQuotes) {
                if ($char === '\\' && $i + 1 < $length) {
                    $next = $line[$i + 1];
                    $escaped = match ($next) {
                        '"' => '"',
                        '\\' => '\\',
                        'n' => "\n",
                        't' => "\t",
                        default => null,
                    };

                    if ($escaped !== null) {
                        $value .= $escaped;
                        $i++;
                        continue;
                    }
                }

                if ($char === '"') {
                    $inQuotes = false;
                    continue;
                }

                $value .= $char;
                continue;
            }

            if ($char === ' ' || $char === "\t") {
                if ($inToken) {
                    $tokens[] = ['value' => $value, 'quoted' => $quoted, 'startsQuoted' => $startsQuoted];
                    $value = '';
                    $quoted = false;
                    $startsQuoted = false;
                    $inToken = false;
                }

                continue;
            }

            if ($char === '#' && !$inToken) {
                break;
            }

            if ($char === '"') {
                $startsQuoted = $startsQuoted || !$inToken;
                $inToken = true;
                $inQuotes = true;
                $quoted = true;
                continue;
            }

            $inToken = true;

            $value .= $char;
        }

        if ($inQuotes) {
            return new Message('syntax.unclosed_quote');
        }

        if ($inToken) {
            $tokens[] = ['value' => $value, 'quoted' => $quoted, 'startsQuoted' => $startsQuoted];
        }

        return $tokens;
    }

    /**
     * @param array<string, string|int> $params
     */
    private function error(int $line, string $key, string $source, array $params = []): SyntaxError
    {
        return new SyntaxError($line, new Message($key, $params), $source);
    }
}
