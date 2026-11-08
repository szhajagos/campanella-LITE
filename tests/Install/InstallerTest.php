<?php

declare(strict_types=1);

namespace Campanella\Lite\Tests\Install;

use Campanella\Lite\Application;
use Campanella\Lite\Install\InstallError;
use Campanella\Lite\Install\Installer;
use Campanella\Lite\Model\PageStatus;
use Campanella\Lite\Paths;
use Campanella\Lite\Project\ProjectRepository;
use Campanella\Lite\Storage\FileStore;
use Campanella\Lite\Tests\Support\TemporaryDirectory;
use Campanella\Lite\Validation\ProjectValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InstallerTest extends TestCase
{
    use TemporaryDirectory;

    private Paths $paths;
    private Installer $installer;

    protected function setUp(): void
    {
        $this->paths = new Paths($this->temporaryDirectory());
        $this->installer = new Installer($this->paths);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function starters(): iterable
    {
        foreach (Installer::STARTERS as $starter) {
            yield $starter => [$starter];
        }
    }

    #[DataProvider('starters')]
    public function testEveryStarterIsAValidWebsite(string $starter): void
    {
        $project = (new ProjectRepository(new FileStore(Installer::startersDirectory() . '/' . $starter)))->load();

        self::assertSame([], array_map('strval', (new ProjectValidator())->validate($project)));
        self::assertSame([$starter], $project->site->languages);
        self::assertCount(3, $project->pages);
        self::assertSame(1, $project->site->frontPage);

        foreach ($project->pages as $page) {
            self::assertSame(PageStatus::Published, $page->status);
        }
    }

    #[DataProvider('starters')]
    public function testEveryStarterRenders(string $starter): void
    {
        $this->installer->createProject($starter);
        $application = new Application($this->paths);
        $project = $application->repository()->load();

        foreach ($project->pages as $page) {
            $html = $application->renderer()->render($project, $page, $starter);
            self::assertStringContainsString('<html lang="' . $starter . '"', $html);
        }
    }

    public function testCreatesTheProjectAndTheDirectories(): void
    {
        $this->installer->createProject('hu', 'Saját oldal', 'https://example.org');

        $site = (new Application($this->paths))->repository()->load()->site;
        self::assertSame('Saját oldal', $site->name);
        self::assertSame('https://example.org', $site->baseUrl);
        self::assertSame('hu', $site->defaultLanguage);
        self::assertStringContainsString('# Saját oldal', (string) file_get_contents($this->paths->project() . '/content/site-title/hu.md'));
        self::assertStringContainsString('© Saját oldal.', (string) file_get_contents($this->paths->project() . '/content/footer-text/hu.md'));
        self::assertTrue($this->installer->hasProject());

        foreach ([$this->paths->config(), $this->paths->var(), $this->paths->project()] as $directory) {
            self::assertStringContainsString('Require all denied', (string) file_get_contents($directory . '/.htaccess'));
        }
    }

    public function testKeepsTheStarterNameWhenNoneIsGiven(): void
    {
        $this->installer->createProject('en');

        self::assertSame('My website', (new Application($this->paths))->repository()->load()->site->name);
    }

    public function testNeverOverwritesAProject(): void
    {
        $this->installer->createProject('en', 'First');

        $this->expectFailure('install.project_exists', fn () => $this->installer->createProject('hu', 'Second'));
        self::assertSame('First', (new Application($this->paths))->repository()->load()->site->name);
    }

    public function testRejectsBadInput(): void
    {
        $this->expectFailure('install.unknown_starter', fn () => $this->installer->createProject('de'));
        $this->expectFailure('install.invalid_site_name', fn () => $this->installer->createProject('en', '  '));
        $this->expectFailure('install.invalid_site_name', fn () => $this->installer->createProject('en', str_repeat('x', 101)));
        $this->expectFailure('install.invalid_base_url', fn () => $this->installer->createProject('en', 'Site', 'https://example.org/path'));
        $this->expectFailure('install.invalid_user_name', fn () => $this->installer->createUser('Admin', 'long enough password', 'en'));
        $this->expectFailure('install.password_too_short', fn () => $this->installer->createUser('admin', 'short', 'en'));

        self::assertFalse($this->installer->hasProject());
        self::assertFalse($this->installer->hasUsers());
    }

    public function testTheSetupKeyIsStableUntilTheFirstUserExists(): void
    {
        $key = $this->installer->setupKey();
        $file = $this->paths->config() . '/' . Installer::SETUP_KEY_FILE;

        self::assertMatchesRegularExpression('/^[a-f0-9]{24}$/', $key);
        self::assertSame($key, $this->installer->setupKey());
        self::assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
        self::assertTrue($this->installer->checkSetupKey(' ' . strtoupper($key) . "\n"));
        self::assertFalse($this->installer->checkSetupKey('0' . substr($key, 1) === $key ? '1' . substr($key, 1) : '0' . substr($key, 1)));
        self::assertFalse($this->installer->checkSetupKey(''));

        $this->installer->createUser('admin', 'correct horse battery', 'hu');

        self::assertFileDoesNotExist($file);
        self::assertFalse($this->installer->checkSetupKey($key));
        self::assertSame('hu', (new Application($this->paths))->users()->find('admin')?->locale);
        $this->expectFailure('install.users_exist', fn () => $this->installer->createUser('other', 'correct horse battery', 'en'));
    }

    private function expectFailure(string $key, \Closure $action): void
    {
        try {
            $action();
            self::fail('Expected ' . $key);
        } catch (InstallError $error) {
            self::assertSame($key, $error->reason->key);
        }
    }
}
