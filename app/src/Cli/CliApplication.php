<?php

declare(strict_types=1);

namespace Campanella\Lite\Cli;

use Campanella\Lite\Application;
use Campanella\Lite\Command\Result\Outcome;
use Campanella\Lite\Paths;
use Throwable;

/**
 * The developer command line: runs a batch against a project directory,
 * through exactly the same interpreter as the admin dashboard.
 *
 *     bin/campanella -e "get site.name"
 *     bin/campanella batch.txt
 *     cat batch.txt | bin/campanella --dry-run --json
 */
final class CliApplication
{
    public const EXIT_OK = 0;
    public const EXIT_REJECTED = 1;
    public const EXIT_USAGE = 2;

    private const USAGE = <<<'TXT'
        Usage: campanella [options] [<file>]

        Runs a batch of Campanella Lite commands and prints the result.
        The batch comes from -e, from <file>, or from standard input.

        Options:
          -e, --execute=<commands>  Run these commands; separate lines with real line breaks
              --root=<directory>    Installation root (default: the directory above bin/)
              --dry-run             Show what would change without writing anything
              --json                Print the result as JSON
              --locale=<locale>     Language of the messages (default: en)
          -h, --help                Show this help

        Exit codes: 0 success, 1 batch rejected or invalid, 2 usage or system error.

        TXT;

    /**
     * @param resource $stdin
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct(
        private readonly string $defaultRoot,
        private $stdin,
        private $stdout,
        private $stderr,
    ) {
    }

    /**
     * @param list<string> $argv including the program name
     */
    public function run(array $argv): int
    {
        try {
            $arguments = $this->parseArguments(array_slice($argv, 1));
        } catch (UsageError $error) {
            fwrite($this->stderr, $error->getMessage() . "\n\n" . self::USAGE);

            return self::EXIT_USAGE;
        }

        if ($arguments['help']) {
            fwrite($this->stdout, self::USAGE);

            return self::EXIT_OK;
        }

        try {
            $input = $this->input($arguments);
            $application = new Application(new Paths($arguments['root']));

            if (!is_dir($application->paths->project())) {
                fwrite($this->stderr, sprintf("No project directory found at %s\n", $application->paths->project()));

                return self::EXIT_USAGE;
            }

            $result = $application->interpreter()->run($input, $arguments['dry-run']);
            $formatter = $arguments['json'] ? $application->jsonFormatter() : $application->textFormatter();
            fwrite($this->stdout, $formatter->format($result, $arguments['locale']));

            if ($result->outcome === Outcome::Failed) {
                return self::EXIT_USAGE;
            }

            return $result->isSuccess() ? self::EXIT_OK : self::EXIT_REJECTED;
        } catch (UsageError $error) {
            fwrite($this->stderr, $error->getMessage() . "\n");

            return self::EXIT_USAGE;
        } catch (Throwable $error) {
            fwrite($this->stderr, sprintf("%s: %s\n", $error::class, $error->getMessage()));

            return self::EXIT_USAGE;
        }
    }

    /**
     * @param list<string> $args
     *
     * @return array{root: string, execute: ?string, file: ?string, dry-run: bool, json: bool, locale: string, help: bool}
     */
    private function parseArguments(array $args): array
    {
        $result = [
            'root' => $this->defaultRoot,
            'execute' => null,
            'file' => null,
            'dry-run' => false,
            'json' => false,
            'locale' => 'en',
            'help' => false,
        ];

        for ($i = 0; $i < count($args); $i++) {
            $arg = $args[$i];

            if ($arg === '-h' || $arg === '--help') {
                $result['help'] = true;
            } elseif ($arg === '--dry-run') {
                $result['dry-run'] = true;
            } elseif ($arg === '--json') {
                $result['json'] = true;
            } elseif ($arg === '-e') {
                $result['execute'] = $args[++$i] ?? throw new UsageError('-e needs a value.');
            } elseif (str_starts_with($arg, '--execute=')) {
                $result['execute'] = substr($arg, strlen('--execute='));
            } elseif (str_starts_with($arg, '--root=')) {
                $result['root'] = substr($arg, strlen('--root='));
            } elseif (str_starts_with($arg, '--locale=')) {
                $locale = substr($arg, strlen('--locale='));

                if (preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/', $locale) !== 1) {
                    throw new UsageError(sprintf('Invalid locale "%s".', $locale));
                }

                $result['locale'] = $locale;
            } elseif (str_starts_with($arg, '-') && $arg !== '-') {
                throw new UsageError(sprintf('Unknown option "%s".', $arg));
            } elseif ($result['file'] === null) {
                $result['file'] = $arg;
            } else {
                throw new UsageError(sprintf('Unexpected argument "%s".', $arg));
            }
        }

        if ($result['execute'] !== null && $result['file'] !== null) {
            throw new UsageError('Give either -e or a file, not both.');
        }

        return $result;
    }

    /**
     * @param array{execute: ?string, file: ?string} $arguments
     */
    private function input(array $arguments): string
    {
        if ($arguments['execute'] !== null) {
            return $arguments['execute'];
        }

        if ($arguments['file'] !== null && $arguments['file'] !== '-') {
            if (!is_file($arguments['file'])) {
                throw new UsageError(sprintf('File not found: %s', $arguments['file']));
            }

            return (string) file_get_contents($arguments['file']);
        }

        return (string) stream_get_contents($this->stdin);
    }
}
