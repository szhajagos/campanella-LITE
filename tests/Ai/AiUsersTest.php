<?php

declare(strict_types=1);

namespace Campanella\Lite\Tests\Ai;

use Campanella\Lite\Admin\AdminController;
use Campanella\Lite\Admin\ApiToken;
use Campanella\Lite\Admin\Session\ArraySession;
use Campanella\Lite\Admin\User;
use Campanella\Lite\Command\Log\LogEntry;
use Campanella\Lite\Command\Result\Outcome;
use Campanella\Lite\Http\Request;
use Campanella\Lite\Http\Response;
use Campanella\Lite\Http\UploadedFile;
use Campanella\Lite\Tests\Command\CommandTestCase;
use Campanella\Lite\Tests\Support\SampleFiles;

/**
 * AI users: managing them, their rights, the API, proposals and approval.
 */
final class AiUsersTest extends CommandTestCase
{
    private const PASSWORD = 'correct horse battery';
    private const NOW = 1_800_000_000;

    private ArraySession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new ArraySession();
        $this->application->users()->save(User::create('admin', self::PASSWORD));
    }

    public function testCreatingAnAiUserAndAToken(): void
    {
        $this->manage('user ai create assistant --locale=hu');
        $assistant = $this->application->users()->find('assistant');
        self::assertNotNull($assistant);
        self::assertTrue($assistant->isAi());
        self::assertSame(User::DEFAULT_AI_RIGHTS, $assistant->rights);
        self::assertTrue($assistant->approval);
        self::assertFalse($assistant->verify(''), 'AI users have no password.');

        $token = $this->createToken('--label=Claude --days=30');
        self::assertMatchesRegularExpression('/^cl_[0-9a-f]{8}_[0-9a-f]{64}$/', $token);
        $stored = (string) file_get_contents($this->application->paths->config() . '/users.yaml');
        self::assertStringNotContainsString(substr($token, 12), $stored, 'Only a hash of the token is stored.');
        self::assertStringContainsString('label: Claude', $stored);

        $found = $this->application->users()->findByToken($token, new \DateTimeImmutable());
        self::assertSame('assistant', $found[0]->name ?? null);
        self::assertNull($this->application->users()->findByToken($token, new \DateTimeImmutable('+31 days')), 'Tokens expire.');
        self::assertNull($this->application->users()->findByToken($token . 'x', new \DateTimeImmutable()));

        $this->manage('user token revoke assistant ' . substr($token, 3, 8));
        self::assertNull($this->application->users()->findByToken($token, new \DateTimeImmutable()));
    }

    public function testDryRunsDoNotCreateAnything(): void
    {
        $result = $this->execute("@dry-run\nuser ai create assistant");

        self::assertSame('ok.user_ai_created', $result->lines[0]->message->key);
        self::assertNull($this->application->users()->find('assistant'));
    }

    public function testRightsAndApprovalSettings(): void
    {
        $this->manage('user ai create assistant');
        $this->manage("user allow assistant layout\nuser deny assistant page\nuser allow assistant \"page publish\"");
        $assistant = $this->application->users()->find('assistant');
        self::assertNotNull($assistant);
        self::assertContains('layout', $assistant->rights);
        self::assertContains('page publish', $assistant->rights);
        self::assertNotContains('page', $assistant->rights);

        $this->assertFails('user allow assistant "user delete"', 'user.never_for_ai');
        $this->assertFails('user deny assistant "content edit"', 'user.deny_part');
        $this->assertFails('user allow admin content', 'user.not_ai');
        $this->assertFails('user allow assistant "Page!"', 'user.invalid_command');

        $this->manage('user approval assistant off --for="content edit"');
        $assistant = $this->application->users()->find('assistant');
        self::assertNotNull($assistant);
        self::assertTrue($assistant->approval);
        self::assertSame(['content edit'], $assistant->autoApprove);

        $this->manage('user approval assistant off');
        self::assertFalse($this->application->users()->find('assistant')?->approval);
        $this->manage('user approval assistant on');
        self::assertSame([], $this->application->users()->find('assistant')?->autoApprove);

        $list = $this->application->textFormatter()->format($this->execute('user list'));
        self::assertStringContainsString('assistant', $list);
        self::assertStringContainsString('layout', $list);
    }

    public function testUsersCannotBeLockedOut(): void
    {
        $this->assertFails('user delete admin', 'user.last_admin');
        $this->application->users()->save(User::create('second', self::PASSWORD));
        $this->assertFails('user delete second', 'user.delete_self', 'second');
        $this->manage('user delete second');
        self::assertNull($this->application->users()->find('second'));
    }

    public function testTheApiNeedsAValidToken(): void
    {
        self::assertSame(401, $this->api('GET', '/api/v1/me', token: null)->status);
        self::assertSame(401, $this->api('GET', '/api/v1/me', token: 'cl_00000000_' . str_repeat('0', 64))->status);

        for ($i = 0; $i < 4; $i++) {
            $this->api('GET', '/api/v1/me', token: 'wrong');
        }

        self::assertSame(429, $this->api('GET', '/api/v1/me', token: 'wrong')->status);
        self::assertStringContainsString('api_token_failed', (string) file_get_contents($this->application->paths->var() . '/log/auth.log'));
    }

    public function testReadingRunsAtOnce(): void
    {
        $token = $this->aiWithToken();

        $me = $this->json($this->api('GET', '/api/v1/me', token: $token));
        self::assertSame('assistant', $me['user']);
        self::assertTrue($me['approval']);

        $response = $this->api('POST', '/api/v1/run', ['commands' => "list pages\nget site.name"], $token);
        $data = $this->json($response);
        self::assertSame(200, $response->status);
        self::assertSame('done', $data['status']);
        self::assertIsArray($data['result']);
        self::assertSame('unchanged', $data['result']['outcome']);
        self::assertSame('no-store', $response->headers['Cache-Control']);
    }

    public function testChangesWaitForApproval(): void
    {
        $token = $this->aiWithToken();
        $response = $this->api('POST', '/api/v1/run', ['commands' => 'page create --title=News', 'session' => 'news page'], $token);
        $data = $this->json($response);

        self::assertSame(202, $response->status);
        self::assertSame('pending', $data['status']);
        self::assertIsArray($data['proposal']);
        self::assertSame('news page', $data['proposal']['session']);
        self::assertStringContainsString('pages/3.yaml', (string) json_encode($data['preview'], JSON_UNESCAPED_SLASHES));
        self::assertNull($this->project()->page(3), 'Nothing is written before approval.');

        $id = (string) $data['proposal']['id'];
        $listed = $this->json($this->api('GET', '/api/v1/proposals', token: $token));
        self::assertIsArray($listed['proposals']);
        self::assertCount(1, $listed['proposals']);
        self::assertSame(404, $this->api('GET', '/api/v1/proposals/20000101-000000-000000', token: $token)->status);

        // The admin approves it in the dashboard.
        $this->login();
        $dashboard = $this->get('/admin')->body;
        self::assertStringContainsString('data-proposal="' . $id . '" data-action="approve"', $dashboard);
        self::assertStringContainsString('Proposals waiting: 1', $dashboard);

        $approved = $this->json($this->decide($id, 'approve'));
        self::assertTrue($approved['ok']);
        self::assertSame('applied', $approved['outcome']);
        self::assertNotNull($this->project()->page(3));

        $entry = $this->application->commandLog()->recent(1)[0];
        self::assertSame('assistant', $entry->user);
        self::assertSame('admin', $entry->approvedBy);
        self::assertSame(LogEntry::APPLIED, $entry->action);
        self::assertStringContainsString('applied, approved by admin', $this->application->textFormatter()->format($this->execute('log --last=1')));

        $proposal = $this->json($this->api('GET', '/api/v1/proposals/' . $id, token: $token));
        self::assertIsArray($proposal['proposal']);
        self::assertSame('applied', $proposal['proposal']['status']);
        self::assertSame('admin', $proposal['proposal']['decided_by']);

        self::assertFalse($this->json($this->decide($id, 'reject'))['ok'], 'A decided proposal cannot be decided again.');
    }

    public function testRejectingAProposal(): void
    {
        $token = $this->aiWithToken();
        $data = $this->json($this->api('POST', '/api/v1/run', ['commands' => 'page create --title=News'], $token));
        self::assertIsArray($data['proposal']);
        $id = (string) $data['proposal']['id'];

        $this->login();
        $this->decide($id, 'reject');

        self::assertNull($this->project()->page(3));
        self::assertSame('rejected', $this->application->aiGateway()->proposals()->find($id)?->status);
    }

    public function testRightsAreEnforcedAndBadBatchesAreNotProposed(): void
    {
        $token = $this->aiWithToken();

        $refused = $this->json($this->api('POST', '/api/v1/run', ['commands' => "page create --title=News\nlayout create extra"], $token));
        self::assertSame('done', $refused['status']);
        self::assertIsArray($refused['result']);
        self::assertSame('rejected', $refused['result']['outcome']);
        self::assertStringContainsString('command.not_allowed', (string) json_encode($refused['result']));

        $this->assertNoUserCommands($token);
        self::assertSame('rejected', $this->json($this->api('POST', '/api/v1/run', ['commands' => 'undo'], $token))['result']['outcome'] ?? null);
        self::assertSame([], $this->application->aiGateway()->proposals()->list());
    }

    public function testApprovalCanBeSwitchedOff(): void
    {
        $token = $this->aiWithToken();
        $this->manage('user approval assistant off --for="page create"');

        $data = $this->json($this->api('POST', '/api/v1/run', ['commands' => 'page create --title=News'], $token));
        self::assertSame('done', $data['status']);
        self::assertNotNull($this->project()->page(3));
        self::assertNull($this->application->commandLog()->recent(1)[0]->approvedBy);

        self::assertSame('pending', $this->json($this->api('POST', '/api/v1/run', ['commands' => 'page publish 3'], $token))['status']);

        $dry = $this->json($this->api('POST', '/api/v1/run', ['commands' => 'page publish 3', 'dry_run' => true], $token));
        self::assertSame('done', $dry['status'], 'Dry runs never wait.');
    }

    public function testAiUsersCannotLogInToTheDashboard(): void
    {
        $this->aiWithToken();

        self::assertSame(401, $this->login('assistant', '')->status);
        self::assertSame(401, $this->login('assistant', 'anything at all')->status);
    }

    public function testFilesCanBeUploadedThroughTheApi(): void
    {
        $token = $this->aiWithToken();
        $samples = new SampleFiles($this->temporaryDirectory() . '/uploads');

        $response = $this->application->apiController(static fn (): int => self::NOW)->handle(new Request(
            'POST',
            '/api/v1/files',
            headers: ['authorization' => 'Bearer ' . $token],
            clientAddress: '198.51.100.9',
            files: [new UploadedFile('chart.png', $samples->image())],
        ));
        $data = $this->json($response);

        self::assertIsArray($data['files']);
        self::assertSame(['ok' => true, 'id' => 1, 'filename' => 'chart.png', 'kind' => 'image', 'reference' => 'file:1'], $data['files'][0]);
        self::assertSame('assistant', $this->project()->file(1)?->uploadedBy);

        $this->manage('user deny assistant "file upload"');
        $denied = $this->json($this->application->apiController(static fn (): int => self::NOW)->handle(new Request(
            'POST', '/api/v1/files', headers: ['authorization' => 'Bearer ' . $token], files: [new UploadedFile('b.png', $samples->image())],
        )));
        self::assertIsArray($denied['files']);
        self::assertSame('command.not_allowed', $denied['files'][0]['error'] ?? null);
    }

    /** Runs user commands, which write after the batch: the batch itself changes nothing. */
    private function manage(string $commands): void
    {
        $result = $this->execute($commands, 'admin');
        self::assertSame(Outcome::Unchanged, $result->outcome, $this->application->textFormatter()->format($result));
    }

    private function assertNoUserCommands(string $token): void
    {
        $data = $this->json($this->api('POST', '/api/v1/run', ['commands' => 'user list'], $token));
        self::assertIsArray($data['result']);
        self::assertSame('rejected', $data['result']['outcome']);
    }

    private function aiWithToken(): string
    {
        $this->manage('user ai create assistant');

        return $this->createToken();
    }

    private function createToken(string $options = ''): string
    {
        $result = $this->execute(trim('user token create assistant ' . $options), user: 'admin');
        self::assertSame(Outcome::Unchanged, $result->outcome);
        $token = $result->lines[0]->output?->value;
        self::assertIsString($token);

        return $token;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function api(string $method, string $path, ?array $body = null, ?string $token = 'none'): Response
    {
        return $this->application->apiController(static fn (): int => self::NOW)->handle(new Request(
            $method,
            $path,
            headers: ($token === null ? [] : ['authorization' => 'Bearer ' . $token]) + ['content-type' => 'application/json'],
            body: $body === null ? '' : (string) json_encode($body),
            clientAddress: '198.51.100.9',
        ));
    }

    private function controller(): AdminController
    {
        return $this->application->adminController($this->session, static fn (): int => self::NOW);
    }

    private function get(string $path): Response
    {
        return $this->controller()->handle(new Request('GET', $path, clientAddress: '203.0.113.7'));
    }

    private function login(string $user = 'admin', string $password = self::PASSWORD): Response
    {
        $this->get('/admin');

        return $this->controller()->handle(new Request('POST', '/admin/login', [
            '_csrf' => (string) $this->session->get('csrf'),
            'username' => $user,
            'password' => $password,
        ], clientAddress: '203.0.113.7'));
    }

    private function decide(string $id, string $action): Response
    {
        return $this->controller()->handle(new Request(
            'POST',
            '/admin/proposals/' . $id . '/' . $action,
            headers: ['content-type' => 'application/json', 'x-csrf-token' => (string) $this->session->get('csrf')],
            body: '{}',
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
}
