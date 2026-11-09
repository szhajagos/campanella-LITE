<?php

declare(strict_types=1);

namespace Campanella\Lite\Tests\Ai;

use Campanella\Lite\Admin\User;
use Campanella\Lite\Ai\McpServer;
use Campanella\Lite\Command\Result\Outcome;
use Campanella\Lite\Http\Request;
use Campanella\Lite\Http\Response;
use Campanella\Lite\Tests\Command\CommandTestCase;
use Campanella\Lite\Tests\Support\SampleFiles;

/**
 * The MCP server: both protocol eras, header checks, and the tools.
 */
final class McpServerTest extends CommandTestCase
{
    private const NOW = 1_800_000_000;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->application->users()->save(User::create('admin', 'correct horse battery'));
        $this->manage('user ai create assistant');
        $result = $this->execute('user token create assistant --label=Claude', user: 'admin');
        $token = $result->lines[0]->output?->value;
        self::assertIsString($token);
        $this->token = $token;
    }

    public function testATokenIsNeeded(): void
    {
        $response = $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], token: null);
        self::assertSame(401, $response->status);
        self::assertStringStartsWith('Bearer', $response->headers['WWW-Authenticate'] ?? '');

        self::assertSame(401, $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], token: 'cl_00000000_' . str_repeat('0', 64))->status);
    }

    public function testOnlyPostFromThisSiteIsAccepted(): void
    {
        self::assertSame(405, $this->send(new Request('GET', '/mcp', headers: $this->auth()))->status);
        self::assertSame(403, $this->send(new Request('POST', '/mcp', headers: $this->auth() + ['origin' => 'https://evil.example', 'host' => 'example.com']))->status);

        $sameSite = $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], headers: ['origin' => 'https://example.com', 'host' => 'example.com']);
        self::assertSame(200, $sameSite->status);
    }

    public function testModernDiscovery(): void
    {
        $data = $this->modern('server/discover');

        self::assertSame('complete', $data['result']['resultType']);
        self::assertContains('2026-07-28', $data['result']['supportedVersions']);
        self::assertSame('campanella-lite', $data['result']['_meta']['io.modelcontextprotocol/serverInfo']['name']);
        self::assertArrayHasKey('tools', $data['result']['capabilities']);
        self::assertIsInt($data['result']['ttlMs']);

        $tools = $this->modern('tools/list')['result'];
        self::assertSame('complete', $tools['resultType']);
        self::assertSame('private', $tools['cacheScope']);
        $names = array_column($tools['tools'], 'name');
        self::assertSame(['campanella_me', 'campanella_help', 'campanella_read', 'campanella_dry_run', 'campanella_submit', 'campanella_proposals', 'campanella_upload_file'], $names);

        foreach ($tools['tools'] as $tool) {
            self::assertSame('object', $tool['inputSchema']['type']);
        }
    }

    public function testHeadersMustMatchTheBody(): void
    {
        $body = $this->modernBody('tools/list');

        $missing = $this->post($body);
        self::assertSame(400, $missing->status);
        self::assertSame(-32020, $this->json($missing)['error']['code']);

        $wrongMethod = $this->post($body, headers: ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'tools/call']);
        self::assertSame(-32020, $this->json($wrongMethod)['error']['code']);

        $call = $this->modernBody('tools/call', ['name' => 'campanella_me', 'arguments' => []]);
        $wrongName = $this->post($call, headers: ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'tools/call', 'mcp-name' => 'campanella_help']);
        self::assertSame(-32020, $this->json($wrongName)['error']['code']);
    }

    public function testUnknownVersionsAndMethods(): void
    {
        $response = $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], headers: ['mcp-protocol-version' => '1999-01-01']);
        self::assertSame(400, $response->status);
        $error = $this->json($response)['error'];
        self::assertSame(-32022, $error['code']);
        self::assertSame('1999-01-01', $error['data']['requested']);
        self::assertContains('2026-07-28', $error['data']['supported']);

        self::assertSame(-32601, $this->modern('resources/list')['error']['code']);
        self::assertSame(-32602, $this->call('campanella_nothing')['error']['code']);
        self::assertSame(-32700, $this->json($this->send(new Request('POST', '/mcp', headers: $this->auth(), body: '{oops')))['error']['code']);
    }

    public function testTheLegacyHandshake(): void
    {
        $init = $this->json($this->post(['jsonrpc' => '2.0', 'id' => 0, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test', 'version' => '1'],
        ]]));
        self::assertSame('2025-06-18', $init['result']['protocolVersion']);
        self::assertSame('campanella-lite', $init['result']['serverInfo']['name']);
        self::assertNotEmpty($init['result']['instructions']);

        $unknown = $this->json($this->post(['jsonrpc' => '2.0', 'id' => 0, 'method' => 'initialize', 'params' => ['protocolVersion' => '2030-01-01']]));
        self::assertSame(McpServer::LEGACY_VERSIONS[0], $unknown['result']['protocolVersion']);

        $notification = $this->post(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], headers: ['mcp-protocol-version' => '2025-06-18']);
        self::assertSame(202, $notification->status);
        self::assertSame('', $notification->body);

        $list = $this->json($this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], headers: ['mcp-protocol-version' => '2025-06-18']));
        self::assertCount(7, $list['result']['tools']);
        self::assertArrayNotHasKey('resultType', $list['result']);
    }

    public function testReading(): void
    {
        $me = $this->call('campanella_me')['result'];
        self::assertFalse($me['isError']);
        self::assertSame('assistant', $me['structuredContent']['user']);
        self::assertStringContainsString('User: assistant', $me['content'][0]['text']);

        $help = $this->call('campanella_help', ['command' => 'page create'])['result'];
        self::assertFalse($help['isError']);
        self::assertStringContainsString('page create', $help['content'][0]['text']);

        $read = $this->call('campanella_read', ['commands' => "list pages\nget site.name"])['result'];
        self::assertFalse($read['isError'], $read['content'][0]['text']);
        self::assertSame('unchanged', $read['structuredContent']['outcome']);

        $refused = $this->call('campanella_read', ['commands' => 'page create --title=News'])['result'];
        self::assertTrue($refused['isError']);
        self::assertSame('mcp.not_read_only', $refused['structuredContent']['error']);

        $syntax = $this->call('campanella_read', ['commands' => 'nonsense here'])['result'];
        self::assertTrue($syntax['isError']);

        self::assertTrue($this->call('campanella_read', [])['result']['isError']);
    }

    public function testChangesBecomeProposals(): void
    {
        $dry = $this->call('campanella_dry_run', ['commands' => 'page create --title=News'])['result'];
        self::assertFalse($dry['isError']);
        self::assertSame('dry_run', $dry['structuredContent']['outcome']);
        self::assertSame([], $this->application->aiGateway()->proposals()->list());

        $submitted = $this->call('campanella_submit', ['commands' => 'page create --title=News', 'session' => 'news'])['result'];
        self::assertFalse($submitted['isError']);
        self::assertSame('pending', $submitted['structuredContent']['status']);
        $id = $submitted['structuredContent']['proposal']['id'];
        self::assertSame('news', $submitted['structuredContent']['proposal']['session']);
        self::assertStringContainsString($id, $submitted['content'][0]['text']);
        self::assertNull($this->project()->page(3));

        $listed = $this->call('campanella_proposals')['result'];
        self::assertSame($id, $listed['structuredContent']['proposals'][0]['id']);
        self::assertStringContainsString('pending', $listed['content'][0]['text']);

        $one = $this->call('campanella_proposals', ['id' => $id])['result'];
        self::assertCount(1, $one['structuredContent']['proposals']);
        self::assertTrue($this->call('campanella_proposals', ['id' => '20000101-000000-000000'])['result']['isError']);
    }

    public function testChangesRunAtOnceWithoutApproval(): void
    {
        $this->manage('user approval assistant off');

        $done = $this->call('campanella_submit', ['commands' => 'page create --title=News'])['result'];
        self::assertFalse($done['isError'], $done['content'][0]['text']);
        self::assertSame('applied', $done['structuredContent']['outcome']);
        self::assertNotNull($this->project()->page(3));

        $refused = $this->call('campanella_submit', ['commands' => 'user list'])['result'];
        self::assertTrue($refused['isError']);
    }

    public function testUploadingAFile(): void
    {
        $samples = new SampleFiles($this->temporaryDirectory() . '/uploads');
        $bytes = (string) file_get_contents($samples->image());

        $uploaded = $this->call('campanella_upload_file', ['filename' => 'chart.png', 'content_base64' => base64_encode($bytes)])['result'];
        self::assertFalse($uploaded['isError'], $uploaded['content'][0]['text']);
        self::assertSame('file:1', $uploaded['structuredContent']['reference']);
        self::assertSame('assistant', $this->project()->file(1)?->uploadedBy);

        $bad = $this->call('campanella_upload_file', ['filename' => 'x.png', 'content_base64' => '%%%'])['result'];
        self::assertSame('mcp.bad_base64', $bad['structuredContent']['error']);

        $script = $this->call('campanella_upload_file', ['filename' => 'x.php', 'content_base64' => base64_encode('<?php echo 1;')])['result'];
        self::assertTrue($script['isError']);
        self::assertCount(0, glob($this->application->paths->project() . '/files/manifest/2.yaml') ?: []);
    }

    /** Runs user commands, which write after the batch: the batch itself changes nothing. */
    private function manage(string $commands): void
    {
        $result = $this->execute($commands, 'admin');
        self::assertSame(Outcome::Unchanged, $result->outcome, $this->application->textFormatter()->format($result));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function call(string $tool, array $arguments = []): array
    {
        return $this->json($this->post($this->modernBody('tools/call', ['name' => $tool, 'arguments' => $arguments]), headers: [
            'mcp-protocol-version' => McpServer::VERSION,
            'mcp-method' => 'tools/call',
            'mcp-name' => $tool,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function modern(string $method): array
    {
        return $this->json($this->post($this->modernBody($method), headers: [
            'mcp-protocol-version' => McpServer::VERSION,
            'mcp-method' => $method,
        ]));
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function modernBody(string $method, array $params = []): array
    {
        return ['jsonrpc' => '2.0', 'id' => 7, 'method' => $method, 'params' => $params + ['_meta' => [
            'io.modelcontextprotocol/protocolVersion' => McpServer::VERSION,
            'io.modelcontextprotocol/clientInfo' => ['name' => 'test', 'version' => '1'],
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
        ]]];
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    private function post(array $body, ?string $token = 'mine', array $headers = []): Response
    {
        $auth = $token === null ? [] : ['authorization' => 'Bearer ' . ($token === 'mine' ? $this->token : $token)];

        return $this->send(new Request('POST', '/mcp', headers: $auth + $headers + ['content-type' => 'application/json'], body: (string) json_encode($body), clientAddress: '198.51.100.9'));
    }

    private function send(Request $request): Response
    {
        return $this->application->mcpServer(static fn (): int => self::NOW)->handle($request);
    }

    /**
     * @return array<string, string>
     */
    private function auth(): array
    {
        return ['authorization' => 'Bearer ' . $this->token];
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        $data = json_decode($response->body, true);
        self::assertIsArray($data, $response->body);

        /** @var array<string, mixed> $data */
        return $data;
    }
}
