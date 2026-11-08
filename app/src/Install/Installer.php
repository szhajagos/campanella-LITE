<?php

declare(strict_types=1);

namespace Campanella\Lite\Install;

use Campanella\Lite\Admin\User;
use Campanella\Lite\Admin\UserRepository;
use Campanella\Lite\Paths;
use Campanella\Lite\Project\ProjectRepository;
use Campanella\Lite\Storage\FileStore;

/**
 * Sets up a new installation: the directories outside the web root, a
 * project copied from a starter, and the first admin user.
 *
 * The web setup (on /admin while there is no admin user) is protected by
 * a setup key: a random code written to config/setup-key.txt, which only
 * someone with access to the server's files can read. The key is removed
 * when the first user has been created.
 */
final class Installer
{
    public const STARTERS = ['en', 'hu'];
    public const SETUP_KEY_FILE = 'setup-key.txt';
    public const MAX_SITE_NAME_LENGTH = 100;

    /** Keeps the web server away from a directory, should it ever be inside the web root. */
    private const DENY_ALL = "# Campanella Lite: never served by the web server.\nRequire all denied\n";

    public function __construct(private readonly Paths $paths)
    {
    }

    /** The bundled starter projects, one per language. */
    public static function startersDirectory(): string
    {
        return dirname(__DIR__, 2) . '/starter';
    }

    public function hasProject(): bool
    {
        return is_file($this->paths->project() . '/' . ProjectRepository::SITE_FILE);
    }

    public function hasUsers(): bool
    {
        return $this->users()->exists();
    }

    /**
     * Creates config/, var/ and project/ when they are missing and makes sure
     * the web server never serves them.
     *
     * @throws InstallError
     */
    public function prepareDirectories(): void
    {
        foreach ([$this->paths->config() => 0750, $this->paths->var() => 0775, $this->paths->project() => 0775] as $directory => $mode) {
            if (!is_dir($directory) && !@mkdir($directory, $mode, true) && !is_dir($directory)) {
                throw new InstallError('install.directory_not_writable', ['directory' => $directory]);
            }

            if (!is_writable($directory)) {
                throw new InstallError('install.directory_not_writable', ['directory' => $directory]);
            }

            if (!is_file($directory . '/.htaccess')) {
                @file_put_contents($directory . '/.htaccess', self::DENY_ALL);
            }
        }
    }

    /**
     * Copies a starter project into project/ and names the site.
     *
     * @param string|null $siteName null keeps the starter's name
     *
     * @throws InstallError
     */
    public function createProject(string $starter, ?string $siteName = null, string $baseUrl = ''): void
    {
        $siteName = $siteName === null ? null : trim($siteName);

        if (!in_array($starter, self::STARTERS, true)) {
            throw new InstallError('install.unknown_starter', ['starter' => $starter, 'starters' => implode(', ', self::STARTERS)]);
        }

        if ($siteName !== null && ($siteName === '' || mb_strlen($siteName) > self::MAX_SITE_NAME_LENGTH || preg_match('/[\x00-\x1F\x7F]/u', $siteName) === 1)) {
            throw new InstallError('install.invalid_site_name', ['max' => self::MAX_SITE_NAME_LENGTH]);
        }

        if ($baseUrl !== '' && preg_match('#^https?://[A-Za-z0-9.-]+(?::[0-9]{1,5})?$#', $baseUrl) !== 1) {
            throw new InstallError('install.invalid_base_url', ['url' => $baseUrl]);
        }

        if ($this->hasProject()) {
            throw new InstallError('install.project_exists', ['directory' => $this->paths->project()]);
        }

        $this->prepareDirectories();

        $source = new FileStore(self::startersDirectory() . '/' . $starter);
        $target = new FileStore($this->paths->project());
        $this->copy($source, $target, '');

        $repository = new ProjectRepository($target);
        $project = $repository->load();
        $repository->saveSite($project->site->with(name: $siteName, baseUrl: $baseUrl));

        // The sample texts (heading, footer) mention the starter's name; use the real one.
        if ($siteName !== null) {
            foreach ($project->contents as $content) {
                foreach ($content->translations as $language => $translation) {
                    $path = ProjectRepository::CONTENT_DIR . '/' . $content->name . '/' . $language . '.md';
                    $target->write($path, str_replace($project->site->name, $siteName, $target->read($path)));
                }
            }
        }
    }

    /**
     * Creates the first admin user, then removes the setup key.
     *
     * @throws InstallError
     */
    public function createUser(string $name, string $password, string $locale): User
    {
        $this->validateUser($name, $password);

        if ($this->hasUsers()) {
            throw new InstallError('install.users_exist');
        }

        $this->prepareDirectories();
        $user = User::create($name, $password, $locale);
        $this->users()->save($user);
        $this->removeSetupKey();

        return $user;
    }

    /**
     * @throws InstallError if the name or the password cannot be used
     */
    public function validateUser(string $name, string $password): void
    {
        if (preg_match(UserRepository::NAME_PATTERN, $name) !== 1) {
            throw new InstallError('install.invalid_user_name');
        }

        if (mb_strlen($password) < User::MIN_PASSWORD_LENGTH) {
            throw new InstallError('install.password_too_short', ['min' => User::MIN_PASSWORD_LENGTH]);
        }
    }

    /**
     * The setup key, created on first use.
     *
     * @throws InstallError
     */
    public function setupKey(): string
    {
        $this->prepareDirectories();
        $file = $this->setupKeyPath();

        if (is_file($file)) {
            $key = trim((string) @file_get_contents($file));

            if (preg_match('/^[a-f0-9]{24}$/', $key) === 1) {
                return $key;
            }
        }

        $key = bin2hex(random_bytes(12));

        if (@file_put_contents($file, $key . "\n") === false) {
            throw new InstallError('install.directory_not_writable', ['directory' => $this->paths->config()]);
        }

        @chmod($file, 0600);

        return $key;
    }

    public function checkSetupKey(string $given): bool
    {
        $file = $this->setupKeyPath();
        $key = is_file($file) ? trim((string) @file_get_contents($file)) : '';

        return strlen($key) === 24 && hash_equals($key, strtolower(trim($given)));
    }

    /** Where the setup key is stored, relative to the installation root. */
    public function setupKeyLocation(): string
    {
        return basename($this->paths->config()) . '/' . self::SETUP_KEY_FILE;
    }

    private function removeSetupKey(): void
    {
        if (is_file($this->setupKeyPath())) {
            @unlink($this->setupKeyPath());
        }
    }

    private function setupKeyPath(): string
    {
        return $this->paths->config() . '/' . self::SETUP_KEY_FILE;
    }

    private function users(): UserRepository
    {
        return new UserRepository(new FileStore($this->paths->config()));
    }

    private function copy(FileStore $source, FileStore $target, string $directory): void
    {
        $prefix = $directory === '' ? '' : $directory . '/';

        foreach (['yaml', 'md'] as $extension) {
            foreach ($source->listFiles($directory, $extension) as $name) {
                $target->write($prefix . $name . '.' . $extension, $source->read($prefix . $name . '.' . $extension));
            }
        }

        foreach ($source->listDirectories($directory) as $child) {
            $this->copy($source, $target, $prefix . $child);
        }
    }
}
