<?php

declare(strict_types=1);

namespace Campanella\Lite\Cli;

use Campanella\Lite\Admin\User;
use Campanella\Lite\Application;
use Campanella\Lite\Command\Result\Outcome;
use Campanella\Lite\Install\InstallError;
use Campanella\Lite\Install\Installer;
use Campanella\Lite\Paths;
use InvalidArgumentException;
use Throwable;

/**
 * The developer command line: runs a batch against a project directory,
 * through exactly the same interpreter as the admin dashboard.
 *
 *     bin/campanella -e "get site.name"
 *     bin/campanella batch.txt
 *     cat batch.txt | bin/campanella --dry-run --json
 *
 * It also sets up a new installation:
 *
 *     bin/campanella --init --starter=hu --site-name="Saját oldal" --add-user=admin
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
              --add-user=<name>     Create an admin user, or set a new password for an
                                    existing one. The password is read from standard
                                    input (at least 10 characters).
              --init                Set up a new installation: create config/, var/ and
                                    project/, and copy a starter website into project/
              --starter=<language>  Starter website for --init: en or hu (default: en)
              --site-name=<name>    Name of the website for --init
              --base-url=<url>      Address of the website for --init, e.g. https://example.com
          -V, --version             Show the version
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

        if ($arguments['version']) {
            fwrite($this->stdout, 'Campanella Lite ' . Application::VERSION . "\n");

            return self::EXIT_OK;
        }

        if ($arguments['help']) {
            fwrite($this->stdout, self::USAGE);

            return self::EXIT_OK;
        }

        if ($arguments['init']) {
            $status = $this->init($arguments);

            if ($status !== self::EXIT_OK || $arguments['add-user'] === null) {
                return $status;
            }
        }

        if ($arguments['add-user'] !== null) {
            return $this->addUser($arguments['root'], $arguments['add-user'], $arguments['locale']);
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
     * @return array{root: string, execute: ?string, file: ?string, dry-run: bool, json: bool, locale: string, help: bool, version: bool, add-user: ?string, init: bool, starter: ?string, site-name: ?string, base-url: string}
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
            'version' => false,
            'add-user' => null,
            'init' => false,
            'starter' => null,
            'site-name' => null,
            'base-url' => '',
        ];

        for ($i = 0; $i < count($args); $i++) {
            $arg = $args[$i];

            if ($arg === '-V' || $arg === '--version') {
                $result['version'] = true;
            } elseif ($arg === '-h' || $arg === '--help') {
                $result['help'] = true;
            } elseif ($arg === '--dry-run') {
                $result['dry-run'] = true;
            } elseif ($arg === '--init') {
                $result['init'] = true;
            } elseif (str_starts_with($arg, '--starter=')) {
                $result['starter'] = substr($arg, strlen('--starter='));
            } elseif (str_starts_with($arg, '--site-name=')) {
                $result['site-name'] = substr($arg, strlen('--site-name='));
            } elseif (str_starts_with($arg, '--base-url=')) {
                $result['base-url'] = rtrim(substr($arg, strlen('--base-url=')), '/');
            } elseif ($arg === '--json') {
                $result['json'] = true;
            } elseif ($arg === '-e') {
                $result['execute'] = $args[++$i] ?? throw new UsageError('-e needs a value.');
            } elseif (str_starts_with($arg, '--execute=')) {
                $result['execute'] = substr($arg, strlen('--execute='));
            } elseif (str_starts_with($arg, '--add-user=')) {
                $result['add-user'] = substr($arg, strlen('--add-user='));
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

        if (!$result['init'] && ($result['starter'] !== null || $result['site-name'] !== null || $result['base-url'] !== '')) {
            throw new UsageError('--starter, --site-name and --base-url go with --init.');
        }

        if ($result['init'] && ($result['execute'] !== null || $result['file'] !== null)) {
            throw new UsageError('--init does not run commands; run them afterwards.');
        }

        return $result;
    }

    private function addUser(string $root, string $name, string $locale): int
    {
        try {
            $application = new Application(new Paths($root));
            $users = $application->users();
            $interactive = stream_isatty($this->stdin);

            if ($interactive) {
                fwrite($this->stderr, 'Password for ' . $name . ': ');
                @shell_exec('stty -echo 2>/dev/null');
            }

            $password = rtrim((string) fgets($this->stdin), "\r\n");

            if ($interactive) {
                @shell_exec('stty echo 2>/dev/null');
                fwrite($this->stderr, "\n");
            }

            if (mb_strlen($password) < User::MIN_PASSWORD_LENGTH) {
                throw new UsageError(sprintf('The password must be at least %d characters long.', User::MIN_PASSWORD_LENGTH));
            }

            $existing = $users->find($name);
            $users->save($existing === null ? User::create($name, $password, $locale) : $existing->withPassword($password));
            fwrite($this->stdout, sprintf("%s the admin user \"%s\".\n", $existing === null ? 'Created' : 'Updated the password of', $name));

            return self::EXIT_OK;
        } catch (UsageError | InvalidArgumentException $error) {
            fwrite($this->stderr, $error->getMessage() . "\n");

            return self::EXIT_USAGE;
        }
    }

    /**
     * @param array{root: string, locale: string, starter: ?string, site-name: ?string, base-url: string, add-user: ?string} $arguments
     */
    private function init(array $arguments): int
    {
        try {
            $application = new Application(new Paths($arguments['root']));
            $installer = $application->installer();
            $starter = $arguments['starter'] ?? Installer::STARTERS[0];

            if ($installer->hasProject()) {
                $installer->prepareDirectories();
                fwrite($this->stdout, sprintf("A website already exists in %s; it was left as it is.\n", $application->paths->project()));
            } else {
                $installer->createProject($starter, $arguments['site-name'], $arguments['base-url']);
                fwrite($this->stdout, sprintf("Created the website in %s from the \"%s\" starter.\n", $application->paths->project(), $starter));
            }

            if ($arguments['add-user'] === null && !$installer->hasUsers()) {
                fwrite($this->stdout, "Next, create an admin user: bin/campanella --add-user=admin\n");
            }

            return self::EXIT_OK;
        } catch (InstallError $error) {
            fwrite($this->stderr, (new Application(new Paths($arguments['root'])))->translator()->translate($error->reason, $arguments['locale']) . "\n");

            return self::EXIT_USAGE;
        } catch (InvalidArgumentException $error) {
            fwrite($this->stderr, $error->getMessage() . "\n");

            return self::EXIT_USAGE;
        }
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
