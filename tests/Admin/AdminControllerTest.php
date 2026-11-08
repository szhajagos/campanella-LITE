<?php

declare(strict_types=1);

namespace Campanella\Lite\Tests\Admin;

use Campanella\Lite\Admin\AdminController;
use Campanella\Lite\Admin\LoginThrottle;
use Campanella\Lite\Admin\Session\ArraySession;
use Campanella\Lite\Admin\User;
use Campanella\Lite\Http\Request;
use Campanella\Lite\Http\Response;
use Campanella\Lite\Tests\Command\CommandTestCase;

final class AdminControllerTest extends CommandTestCase
{
    private const PASSWORD = 'correct horse battery';

    private ArraySession $session;
    private int $now = 1_800_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new ArraySession();
        $this->application->users()->save(User::create('admin', self::PASSWORD));
    }

    public function testShowsTheLoginFormWithACsrfToken(): void
    {
        $response = $this->get('/admin');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('action="/admin/login"', $response->body);
        self::assertStringContainsString('name="_csrf" value="' . $this->session->get('csrf') . '"', $response->body);
        self::assertStringNotContainsString('There is no admin user yet', $response->body);
    }

    public function testSendsSecurityHeaders(): void
    {
        $headers = $this->get('/admin')->headers;

        self::assertStringContainsString("frame-ancestors 'none'", $headers['Content-Security-Policy'] ?? '');
        self::assertStringContainsString("script-src 'self'", $headers['Content-Security-Policy'] ?? '');
        self::assertSame('DENY', $headers['X-Frame-Options'] ?? null);
        self::assertSame('no-store', $headers['Cache-Control'] ?? null);
    }

    public function testTellsWhenThereIsNoUserYet(): void
    {
        unlink($this->application->paths->config() . '/users.yaml');

        self::assertStringContainsString('bin/campanella --add-user=admin', $this->get('/admin')->body);
    }

    public function testLogsIn(): void
    {
        $response = $this->login();

        self::assertSame(303, $response->status);
        self::assertSame('/admin', $response->headers['Location']);
        self::assertSame('admin', $this->session->get('user'));
        self::assertSame(1, $this->session->regenerations);
        self::assertStringContainsString('login_ok user=admin ip=203.0.113.7', $this->authLog());

        $dashboard = $this->get('/admin');
        self::assertStringContainsString('id="commands"', $dashboard->body);
        self::assertStringContainsString('Pages: 2 (2 published, 0 drafts)', $dashboard->body);
        self::assertStringContainsString('/admin/preview/2', $dashboard->body);
    }

    public function testRejectsWrongPasswordsAndUnknownUsers(): void
    {
        self::assertSame(401, $this->login('admin', 'wrong password')->status);
        self::assertSame(401, $this->login('nobody', self::PASSWORD)->status);
        self::assertNull($this->session->get('user'));
        self::assertStringContainsString('login_failed user=nobody', $this->authLog());
    }

    public function testRejectsALoginWithoutTheCsrfToken(): void
    {
        $this->get('/admin');
        $response = $this->controller()->handle(new Request('POST', '/admin/login', ['username' => 'admin', 'password' => self::PASSWORD], clientAddress: '203.0.113.7'));

        self::assertSame(400, $response->status);
        self::assertNull($this->session->get('user'));
    }

    public function testBlocksAnAddressAfterTooManyFailures(): void
    {
        for ($i = 0; $i < LoginThrottle::MAX_FAILURES; $i++) {
            self::assertSame(401, $this->login('admin', 'wrong ' . $i)->status);
        }

        $blocked = $this->login();
        self::assertSame(429, $blocked->status);
        self::assertSame('900', $blocked->headers['Retry-After']);
        self::assertNull($this->session->get('user'));

        self::assertSame(303, $this->login('admin', self::PASSWORD, '198.51.100.1')->status, 'Other addresses are not blocked.');

        $this->now += LoginThrottle::WINDOW + 1;
        self::assertSame(303, $this->login()->status, 'The block ends after the window.');
    }

    public function testRunsCommandsAsTheLoggedInUser(): void
    {
        $this->login();
        $response = $this->runBatch("set site.name \"Kovács Kft.\"\nlist pages");
        $data = $this->json($response);

        self::assertSame(200, $response->status);
        self::assertTrue($data['ok']);
        self::assertSame('applied', $data['outcome']);
        self::assertStringContainsString('site.name = Kovács Kft.', (string) $data['html']);
        self::assertStringContainsString('<th scope="col">path</th>', (string) $data['html']);
        self::assertStringContainsString('[1] Ok. site.name = Kovács Kft.', (string) $data['text']);
        self::assertStringContainsString('data-batch="set site.name', (string) $data['history_html']);
        self::assertSame('Kovács Kft.', $this->project()->site->name);
    }

    public function testRunsDryRuns(): void
    {
        $this->login();
        $data = $this->json($this->runBatch('set site.name Changed', dryRun: true));

        self::assertSame('dry_run', $data['outcome']);
        self::assertSame('Example Ltd.', $this->project()->site->name);
    }

    public function testEscapesOutput(): void
    {
        $this->login();
        $data = $this->json($this->runBatch('get page.<script>'));

        self::assertStringNotContainsString('<script>', (string) $data['html']);
        self::assertStringContainsString('&lt;script&gt;', (string) $data['html']);
    }

    public function testRunNeedsALoginAndTheCsrfToken(): void
    {
        self::assertSame(401, $this->runBatch('get site.name')->status);

        $this->login();
        $response = $this->controller()->handle(new Request('POST', '/admin/run', headers: ['content-type' => 'application/json'], body: '{"commands":"set site.name X"}'));

        self::assertSame(403, $response->status);
        self::assertSame('Example Ltd.', $this->project()->site->name);
    }

    public function testSetsTheUsersLocale(): void
    {
        $this->login();

        $data = $this->json($this->runBatch('user set locale de'));
        self::assertFalse($data['ok']);
        self::assertStringContainsString('There are no messages in &quot;de&quot;', (string) $data['html']);

        $data = $this->json($this->runBatch('user set locale en'));
        self::assertTrue($data['ok']);
        self::assertSame('en', $this->application->users()->find('admin')?->locale);
    }

    public function testSpeaksTheUsersLanguage(): void
    {
        $this->login();
        $data = $this->json($this->runBatch('user set locale hu'));
        self::assertStringContainsString('Az üzenetek nyelve mostantól: hu.', (string) $data['html'], 'The answer is already in the new language.');

        $dashboard = $this->get('/admin');
        self::assertStringContainsString('<html lang="hu">', $dashboard->body);
        self::assertStringContainsString('Futtatás', $dashboard->body);
        self::assertStringContainsString('Oldalak: 2 (publikált: 2, vázlat: 0)', $dashboard->body);

        $data = $this->json($this->runBatch("set site.name Kovács\nget page.99"));
        self::assertStringContainsString('<strong>Rendben.</strong>', (string) $data['html']);
        self::assertStringContainsString('Nincs oldal ezzel az azonosítóval vagy aliasszal', (string) $data['html']);
        self::assertStringContainsString('A köteg nem futott le, semmi nem változott.', (string) $data['text']);
    }

    public function testPreviewsDraftsForLoggedInUsersOnly(): void
    {
        $this->apply('page unpublish 2');

        self::assertSame(303, $this->get('/admin/preview/2')->status);

        $this->login();
        $preview = $this->get('/admin/preview/2');
        self::assertSame(200, $preview->status);
        self::assertStringContainsString('<title>About us – Example Ltd.</title>', $preview->body);
        self::assertSame(404, $this->get('/admin/preview/99')->status);
        self::assertSame(404, $this->get('/admin/preview/2/de')->status);
        self::assertSame(404, $this->get('/admin/preview/x')->status);
    }

    public function testEndsIdleAndOldSessions(): void
    {
        $this->login();
        $this->now += AdminController::IDLE_TIMEOUT - 1;
        self::assertSame('admin', $this->sessionUserAfter('/admin'));

        $this->now += AdminController::IDLE_TIMEOUT + 1;
        self::assertNull($this->sessionUserAfter('/admin'));

        $this->login();

        for ($i = 0; $i < 30; $i++) {
            $this->now += AdminController::IDLE_TIMEOUT - 60;
            $this->get('/admin');
        }

        self::assertNull($this->session->get('user'), 'Sessions end after MAX_LIFETIME even when active.');
    }

    public function testLogsOut(): void
    {
        $this->login();
        $response = $this->controller()->handle(new Request('POST', '/admin/logout', ['_csrf' => (string) $this->session->get('csrf')], clientAddress: '203.0.113.7'));

        self::assertSame(303, $response->status);
        self::assertTrue($this->session->destroyed);
        self::assertNull($this->session->get('user'));
        self::assertStringContainsString('logout user=admin', $this->authLog());
    }

    public function testLogoutNeedsTheCsrfToken(): void
    {
        $this->login();
        $this->controller()->handle(new Request('POST', '/admin/logout', ['_csrf' => 'forged']));

        self::assertSame('admin', $this->session->get('user'));
    }

    public function testUnknownAdminAddressesAreNotFound(): void
    {
        self::assertSame(404, $this->get('/admin/secret')->status);
        self::assertSame(404, $this->controller()->handle(new Request('GET', '/admin/login'))->status);
        self::assertSame(200, $this->get('/admin/index.php')->status);
    }

    private function controller(): AdminController
    {
        return $this->application->adminController($this->session, fn (): int => $this->now);
    }

    private function get(string $path): Response
    {
        return $this->controller()->handle(new Request('GET', $path, clientAddress: '203.0.113.7'));
    }

    private function login(string $user = 'admin', string $password = self::PASSWORD, string $address = '203.0.113.7'): Response
    {
        $this->get('/admin');

        return $this->controller()->handle(new Request('POST', '/admin/login', [
            '_csrf' => (string) $this->session->get('csrf'),
            'username' => $user,
            'password' => $password,
        ], clientAddress: $address));
    }

    private function runBatch(string $commands, bool $dryRun = false): Response
    {
        return $this->controller()->handle(new Request(
            'POST',
            '/admin/run',
            headers: ['content-type' => 'application/json', 'x-csrf-token' => (string) $this->session->get('csrf')],
            body: (string) json_encode(['commands' => $commands, 'dry_run' => $dryRun]),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        $data = json_decode($response->body, true);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    private function sessionUserAfter(string $path): mixed
    {
        $this->get($path);

        return $this->session->get('user');
    }

    private function authLog(): string
    {
        return (string) @file_get_contents($this->application->paths->var() . '/log/auth.log');
    }
}
