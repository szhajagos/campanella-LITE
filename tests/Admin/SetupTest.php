<?php

declare(strict_types=1);

namespace Campanella\Lite\Tests\Admin;

use Campanella\Lite\Admin\AdminController;
use Campanella\Lite\Admin\LoginThrottle;
use Campanella\Lite\Admin\Session\ArraySession;
use Campanella\Lite\Application;
use Campanella\Lite\Http\Request;
use Campanella\Lite\Http\Response;
use Campanella\Lite\Install\Installer;
use Campanella\Lite\Paths;
use Campanella\Lite\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * The first-time setup on /admin of an installation without admin users.
 */
final class SetupTest extends TestCase
{
    use TemporaryDirectory;

    private const PASSWORD = 'correct horse battery';

    private Application $application;
    private ArraySession $session;

    protected function setUp(): void
    {
        $this->application = new Application(new Paths($this->temporaryDirectory()));
        $this->session = new ArraySession();
    }

    public function testShowsTheSetupFormAndCreatesTheKey(): void
    {
        $response = $this->get('/admin');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('action="/admin/setup"', $response->body);
        self::assertStringContainsString('config/setup-key.txt', $response->body);
        self::assertStringContainsString('name="site_name"', $response->body);
        self::assertSame('DENY', $response->headers['X-Frame-Options'] ?? null);
        self::assertFileExists($this->keyFile());
    }

    public function testOtherAdminAddressesLeadToTheSetup(): void
    {
        self::assertSame(303, $this->get('/admin/preview/1')->status);
        self::assertSame(303, $this->controller()->handle(new Request('POST', '/admin/run'))->status);
    }

    public function testTheSetupPageFollowsTheBrowserLanguage(): void
    {
        $hungarian = $this->controller()->handle(new Request('GET', '/admin', headers: ['accept-language' => 'hu-HU,hu;q=0.9,en;q=0.8']));
        self::assertStringContainsString('A weboldal létrehozása', $hungarian->body);
        self::assertStringContainsString('<option value="hu" selected>', $hungarian->body);

        $english = $this->controller()->handle(new Request('GET', '/admin', headers: ['accept-language' => 'hu'], query: ['lang' => 'en']));
        self::assertStringContainsString('Create the website', $english->body);
    }

    public function testSetsUpTheWebsiteAndLogsIn(): void
    {
        $response = $this->submit(['starter' => 'hu', 'site_name' => 'Saját oldal', 'locale' => 'hu']);

        self::assertSame(303, $response->status, $response->body);
        self::assertSame('admin', $this->session->get('user'));
        self::assertSame(1, $this->session->regenerations);
        self::assertFileDoesNotExist($this->keyFile());

        $site = $this->application->repository()->load()->site;
        self::assertSame('Saját oldal', $site->name);
        self::assertSame('https://www.example.org', $site->baseUrl);
        self::assertSame('hu', $this->application->users()->find('admin')?->locale);
        self::assertStringContainsString('setup_ok user=admin', (string) file_get_contents($this->application->paths->var() . '/log/auth.log'));

        $dashboard = $this->get('/admin');
        self::assertStringContainsString('id="commands"', $dashboard->body);
        self::assertStringContainsString('Saját oldal', $dashboard->body);
        self::assertSame(200, $this->application->frontController()->handle('GET', '/rolunk')->status);
    }

    public function testKeepsAnExistingProject(): void
    {
        $this->application->installer()->createProject('en', 'Already here');

        $form = $this->get('/admin')->body;
        self::assertStringNotContainsString('name="site_name"', $form);

        self::assertSame(303, $this->submit(['site_name' => 'Ignored'])->status);
        self::assertSame('Already here', $this->application->repository()->load()->site->name);
    }

    public function testAWrongKeyChangesNothing(): void
    {
        $response = $this->submit(['setup_key' => str_repeat('0', 24)]);

        self::assertSame(403, $response->status);
        self::assertStringContainsString('The setup key is wrong', $response->body);
        self::assertStringContainsString('value="My site"', $response->body);
        self::assertFalse($this->application->installer()->hasProject());
        self::assertFalse($this->application->users()->exists());
        self::assertStringContainsString('setup_failed', (string) file_get_contents($this->application->paths->var() . '/log/auth.log'));
    }

    public function testTooManyWrongKeysAreThrottled(): void
    {
        for ($i = 0; $i < LoginThrottle::MAX_FAILURES; $i++) {
            $this->submit(['setup_key' => 'wrong']);
        }

        $response = $this->submit();

        self::assertSame(429, $response->status);
        self::assertFalse($this->application->users()->exists());
    }

    public function testInvalidFormsChangeNothing(): void
    {
        self::assertSame(422, $this->submit(['password_repeat' => 'something else'])->status);
        self::assertSame(422, $this->submit(['site_name' => ''])->status);
        self::assertSame(422, $this->submit(['username' => 'Not Valid'])->status);
        self::assertSame(422, $this->submit(['password' => 'short', 'password_repeat' => 'short'])->status);
        self::assertSame(422, $this->submit(['starter' => 'xx'])->status);

        self::assertFalse($this->application->installer()->hasProject());
        self::assertFalse($this->application->users()->exists());
        self::assertFileExists($this->keyFile());
    }

    public function testTheFormNeedsTheCsrfToken(): void
    {
        self::assertSame(400, $this->submit(['_csrf' => 'forged'])->status);
        self::assertFalse($this->application->users()->exists());
    }

    public function testTheFrontEndAsksForTheSetup(): void
    {
        $response = $this->application->frontController()->handle('GET', '/');

        self::assertSame(503, $response->status);
        self::assertStringContainsString('/admin', $response->body);
    }

    /**
     * @param array<string, string> $fields
     */
    private function submit(array $fields = []): Response
    {
        $this->get('/admin');
        $key = trim((string) file_get_contents($this->keyFile()));

        return $this->controller()->handle(new Request('POST', '/admin/setup', $fields + [
            '_csrf' => (string) $this->session->get('csrf'),
            'setup_key' => $key,
            'starter' => 'en',
            'site_name' => 'My site',
            'username' => 'admin',
            'password' => self::PASSWORD,
            'password_repeat' => $fields['password'] ?? self::PASSWORD,
        ], headers: ['host' => 'www.example.org'], clientAddress: '203.0.113.7', secure: true));
    }

    private function controller(): AdminController
    {
        return $this->application->adminController($this->session, static fn (): int => 1_800_000_000);
    }

    private function get(string $path): Response
    {
        return $this->controller()->handle(new Request('GET', $path, clientAddress: '203.0.113.7'));
    }

    private function keyFile(): string
    {
        return $this->application->paths->config() . '/' . Installer::SETUP_KEY_FILE;
    }
}
