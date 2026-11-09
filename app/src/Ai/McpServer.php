<?php

declare(strict_types=1);

namespace Campanella\Lite\Ai;

use Campanella\Lite\Admin\ApiToken;
use Campanella\Lite\Admin\User;
use Campanella\Lite\Application;
use Campanella\Lite\Command\Format\JsonFormatter;
use Campanella\Lite\Command\Format\TextFormatter;
use Campanella\Lite\Command\Result\BatchResult;
use Campanella\Lite\Files\FileRejected;
use Campanella\Lite\Files\FileTypes;
use Campanella\Lite\Http\Request;
use Campanella\Lite\Http\Response;
use Campanella\Lite\I18n\Message;
use Campanella\Lite\I18n\Translator;

/**
 * The MCP server for AI users (POST /mcp, Streamable HTTP, JSON responses).
 *
 * It speaks the stateless protocol of MCP 2026-07-28 (server/discover,
 * tools/list, tools/call with the version in every request) and, for older
 * clients, the handshake of 2025-03-26 to 2025-11-25 (initialize,
 * notifications/initialized). It needs no sessions and never streams.
 *
 * The tools work through the AiGateway, so the AI user's rights and
 * approval settings apply exactly as in the JSON API. The client sends the
 * user's token: "Authorization: Bearer cl_…".
 */
final class McpServer
{
    public const PATH = '/mcp';

    public const VERSION = '2026-07-28';

    /** Versions that use initialize; the first is offered when a client asks for an unknown one. */
    public const LEGACY_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];

    /** The version a request without any version is taken to speak. */
    private const ASSUMED_VERSION = '2025-03-26';

    /** The largest request body read, enough for a base64 file of FileTypes::MAX_SIZE. */
    public const MAX_BODY = 16 * 1024 * 1024;

    private const TTL_MS = 3_600_000;

    private const PARSE_ERROR = -32700;
    private const INVALID_REQUEST = -32600;
    private const METHOD_NOT_FOUND = -32601;
    private const INVALID_PARAMS = -32602;
    private const HEADER_MISMATCH = -32020;
    private const UNSUPPORTED_VERSION = -32022;

    private const HEADERS = [
        'Cache-Control' => 'no-store',
        'X-Content-Type-Options' => 'nosniff',
        'X-Robots-Tag' => 'noindex',
    ];

    private const INSTRUCTIONS = <<<'TEXT'
        Campanella Lite is a CMS that is changed only with commands, one per line, run as an all-or-nothing batch.
        Start with campanella_help (the command index, then "help <command>"), and read the site with
        campanella_read ("list pages", "get page 3", "check"). Try changes with campanella_dry_run, then send
        them with campanella_submit. Batches the administrator has to approve become proposals: tell the user,
        and look at them later with campanella_proposals. Add images and documents with campanella_upload_file;
        it returns the "file:ID" reference to use in content. campanella_me shows what you may do.
        TEXT;

    public function __construct(
        private readonly TokenAuthenticator $authenticator,
        private readonly AiGateway $gateway,
        private readonly Translator $translator,
        private readonly TextFormatter $text,
        private readonly JsonFormatter $json,
    ) {
    }

    public function handle(Request $request): Response
    {
        return $this->dispatch($request)->withHeaders(self::HEADERS);
    }

    private function dispatch(Request $request): Response
    {
        if (!$this->originAllowed($request)) {
            return Response::text('Forbidden origin', 403);
        }

        if ($request->method !== 'POST') {
            return Response::text('Send JSON-RPC requests with POST.', 405)->withHeaders(['Allow' => 'POST']);
        }

        $retryAfter = $this->authenticator->retryAfter($request);

        if ($retryAfter > 0) {
            return $this->failure(null, -32001, $this->translator->translate(
                new Message('api.too_many_attempts', ['minutes' => (int) ceil($retryAfter / 60)]),
            ), 429)->withHeaders(['Retry-After' => (string) $retryAfter]);
        }

        $found = $this->authenticator->authenticate($request);

        if ($found === null) {
            return $this->failure(null, -32001, $this->translator->translate(new Message('api.unauthorized')), 401)
                ->withHeaders(['WWW-Authenticate' => 'Bearer realm="Campanella Lite"']);
        }

        $message = json_decode($request->body, true);

        if (!is_array($message)) {
            return $this->failure(null, self::PARSE_ERROR, 'Parse error: the body is not a JSON-RPC message.', 400);
        }

        $id = $message['id'] ?? null;
        $id = is_string($id) || is_int($id) ? $id : null;
        $method = $message['method'] ?? null;

        if (($message['jsonrpc'] ?? null) !== '2.0' || !is_string($method)) {
            // Responses from the client (to requests this server never sends) are accepted and ignored.
            if (array_key_exists('result', $message) || array_key_exists('error', $message)) {
                return new Response(202);
            }

            return $this->failure($id, self::INVALID_REQUEST, 'Invalid request: expected one JSON-RPC 2.0 request.', 400);
        }

        if (!array_key_exists('id', $message)) {
            return new Response(202);
        }

        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        return $this->request($request, $found[0], $found[1], $id, $method, $params);
    }

    /**
     * @param array<mixed> $params
     */
    private function request(Request $request, User $user, ApiToken $token, string|int|null $id, string $method, array $params): Response
    {
        if ($method === 'initialize') {
            $requested = is_string($params['protocolVersion'] ?? null) ? $params['protocolVersion'] : '';
            $version = in_array($requested, self::LEGACY_VERSIONS, true) ? $requested : self::LEGACY_VERSIONS[0];

            return $this->success($id, [
                'protocolVersion' => $version,
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => $this->serverInfo(),
                'instructions' => self::INSTRUCTIONS,
            ], false);
        }

        $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
        $header = $request->header('mcp-protocol-version');
        $version = $meta['io.modelcontextprotocol/protocolVersion'] ?? $header ?? self::ASSUMED_VERSION;

        if (!is_string($version) || ($version !== self::VERSION && !in_array($version, self::LEGACY_VERSIONS, true))) {
            return $this->failure($id, self::UNSUPPORTED_VERSION, 'Unsupported protocol version.', 400, [
                'supported' => [self::VERSION, ...self::LEGACY_VERSIONS],
                'requested' => $version,
            ]);
        }

        $modern = $version === self::VERSION;

        if ($modern && ($mismatch = $this->headerMismatch($request, $version, $method, $params)) !== null) {
            return $this->failure($id, self::HEADER_MISMATCH, $mismatch, 400);
        }

        return match ($method) {
            'server/discover' => $this->success($id, [
                'supportedVersions' => [self::VERSION, ...self::LEGACY_VERSIONS],
                'capabilities' => ['tools' => new \stdClass()],
                'instructions' => self::INSTRUCTIONS,
                'ttlMs' => self::TTL_MS,
                'cacheScope' => 'private',
            ], true),
            'ping' => $this->success($id, [], $modern),
            'tools/list' => $this->success($id, ['tools' => self::tools()] + ($modern ? ['ttlMs' => self::TTL_MS, 'cacheScope' => 'private'] : []), $modern),
            'tools/call' => $this->call($id, $user, $token, $params, $modern),
            default => $this->failure($id, self::METHOD_NOT_FOUND, sprintf('Method not found: %s', $method), 200),
        };
    }

    /**
     * The headers of a 2026-07-28 request must repeat what its body says.
     *
     * @param array<mixed> $params
     */
    private function headerMismatch(Request $request, string $version, string $method, array $params): ?string
    {
        if ($request->header('mcp-protocol-version') !== $version) {
            return 'Header mismatch: MCP-Protocol-Version must match the protocol version of the request.';
        }

        if ($request->header('mcp-method') !== $method) {
            return 'Header mismatch: Mcp-Method must match the method of the request.';
        }

        if ($method === 'tools/call' && $request->header('mcp-name') !== ($params['name'] ?? null)) {
            return 'Header mismatch: Mcp-Name must match the name of the tool.';
        }

        return null;
    }

    /**
     * @param array<mixed> $params
     */
    private function call(string|int|null $id, User $user, ApiToken $token, array $params, bool $modern): Response
    {
        $name = $params['name'] ?? null;
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $tools = array_column(self::tools(), 'name');

        if (!is_string($name) || !in_array($name, $tools, true)) {
            return $this->failure($id, self::INVALID_PARAMS, sprintf('Unknown tool: %s', is_string($name) ? $name : '?'), 200);
        }

        $result = match ($name) {
            'campanella_me' => $this->me($user),
            'campanella_help' => $this->help($user, $arguments),
            'campanella_read' => $this->read($user, $arguments),
            'campanella_dry_run' => $this->submit($user, $token, $arguments, true),
            'campanella_submit' => $this->submit($user, $token, $arguments, false),
            'campanella_proposals' => $this->proposals($user, $arguments),
            default => $this->upload($user, $arguments),
        };

        return $this->success($id, $result, $modern);
    }

    /**
     * @return array<string, mixed>
     */
    private function me(User $user): array
    {
        $data = [
            'user' => $user->name,
            'role' => $user->role,
            'locale' => $user->locale,
            'rights' => $user->rights,
            'approval' => $user->approval,
            'auto_approve' => $user->autoApprove,
        ];

        $none = '-';
        $text = $this->translator->translate(new Message($user->approval ? 'mcp.me' : 'mcp.me_no_approval', [
            'user' => $user->name,
            'rights' => $user->rights === [] ? $none : implode(', ', $user->rights),
            'auto' => $user->autoApprove === [] ? $none : implode(', ', $user->autoApprove),
        ]), $user->locale);

        return self::content($text, $data);
    }

    /**
     * @param array<mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function help(User $user, array $arguments): array
    {
        $topic = $arguments['command'] ?? '';

        if (!is_string($topic) || preg_match('/[\r\n]/', $topic) === 1) {
            return $this->toolError($user, 'mcp.bad_argument', ['argument' => 'command']);
        }

        return $this->batch($user, $this->gateway->submit($user, trim('help ' . trim($topic)))->result);
    }

    /**
     * @param array<mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function read(User $user, array $arguments): array
    {
        $commands = $this->commands($arguments);

        if ($commands === null) {
            return $this->toolError($user, 'mcp.missing_commands');
        }

        if ($this->gateway->isReadOnly($commands) === false) {
            return $this->toolError($user, 'mcp.not_read_only');
        }

        // A batch with a syntax error is only dry-run, to show the error.
        $dryRun = $this->gateway->isReadOnly($commands) === null;

        return $this->batch($user, $this->gateway->submit($user, $commands, $dryRun)->result);
    }

    /**
     * @param array<mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function submit(User $user, ApiToken $token, array $arguments, bool $dryRun): array
    {
        $commands = $this->commands($arguments);

        if ($commands === null) {
            return $this->toolError($user, 'mcp.missing_commands');
        }

        $session = is_string($arguments['session'] ?? null) && trim($arguments['session']) !== ''
            ? $arguments['session']
            : $token->label . ' ' . date('Y-m-d', $this->authenticator->now());
        $submission = $this->gateway->submit($user, $commands, $dryRun, $session);

        if ($submission->proposal === null) {
            return $this->batch($user, $submission->result);
        }

        $proposal = $submission->proposal;
        $text = $this->translator->translate(new Message('api.proposed', ['id' => $proposal->id]), $user->locale)
            . "\n\n" . $this->text->format($submission->result, $user->locale);

        return self::content($text, [
            'status' => 'pending',
            'proposal' => $this->proposalArray($proposal, $user->locale),
            'preview' => $this->json->toArray($submission->result, $user->locale),
        ]);
    }

    /**
     * @param array<mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function proposals(User $user, array $arguments): array
    {
        $id = $arguments['id'] ?? null;

        if ($id !== null && $id !== '') {
            $proposal = is_string($id) ? $this->gateway->proposals()->find($id) : null;

            if ($proposal === null || $proposal->user !== $user->name) {
                return $this->toolError($user, 'api.proposal_not_found', ['id' => is_scalar($id) ? (string) $id : '?']);
            }

            $proposals = [$proposal];
        } else {
            $proposals = $this->gateway->proposals()->list($user->name, null, 20);
        }

        if ($proposals === []) {
            return self::content($this->translator->translate(new Message('mcp.no_proposals'), $user->locale), ['proposals' => []]);
        }

        $lines = array_map(fn (Proposal $proposal): string => $this->proposalLine($proposal, $user->locale), $proposals);

        return self::content(implode("\n\n", $lines), ['proposals' => array_map(
            fn (Proposal $proposal): array => $this->proposalArray($proposal, $user->locale),
            $proposals,
        )]);
    }

    /**
     * @param array<mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function upload(User $user, array $arguments): array
    {
        $filename = $arguments['filename'] ?? null;
        $content = $arguments['content_base64'] ?? null;

        if (!is_string($filename) || trim($filename) === '') {
            return $this->toolError($user, 'mcp.bad_argument', ['argument' => 'filename']);
        }

        $bytes = is_string($content) ? base64_decode((string) preg_replace('/\s+/', '', $content), true) : false;

        if ($bytes === false || $bytes === '') {
            return $this->toolError($user, 'mcp.bad_base64');
        }

        if (strlen($bytes) > FileTypes::MAX_SIZE) {
            return $this->toolError($user, 'file.too_large', ['max' => (int) (FileTypes::MAX_SIZE / 1024 / 1024)]);
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'cl-mcp-');

        try {
            file_put_contents($path, $bytes);
            $file = $this->gateway->upload($user, $path, $filename);
        } catch (FileRejected $rejected) {
            return $this->toolError($user, $rejected->reason->key, $rejected->reason->params);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $data = ['id' => $file->id, 'filename' => $file->filename, 'kind' => $file->kind(), 'reference' => $file->reference()];

        return self::content(
            $this->translator->translate(new Message('mcp.uploaded', ['id' => $file->id, 'filename' => $file->filename, 'reference' => $file->reference()]), $user->locale),
            $data,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function batch(User $user, BatchResult $result): array
    {
        return self::content(
            $this->text->format($result, $user->locale),
            $this->json->toArray($result, $user->locale),
            !$result->isSuccess(),
        );
    }

    /**
     * @param array<mixed> $arguments
     */
    private function commands(array $arguments): ?string
    {
        $commands = $arguments['commands'] ?? null;

        return is_string($commands) && trim($commands) !== '' ? $commands : null;
    }

    private function proposalLine(Proposal $proposal, string $locale): string
    {
        $line = sprintf("#%s · %s · %s · %s\n%s", $proposal->id, $proposal->status, $proposal->createdAt, $proposal->session, rtrim($proposal->batch));

        if ($proposal->decidedBy !== null) {
            $line .= sprintf("\n→ %s (%s)", $proposal->decidedBy, (string) $proposal->decidedAt);
        }

        if ($proposal->reason !== null) {
            $line .= ': ' . $proposal->reason;
        }

        $summary = $proposal->result['summary'] ?? $proposal->preview['summary'];

        return $line . "\n→ " . $this->translator->translate($summary, $locale);
    }

    /**
     * @return array<string, mixed>
     */
    private function proposalArray(Proposal $proposal, string $locale): array
    {
        $data = $proposal->toArray();
        $data['preview']['text'] = $this->translator->translate($proposal->preview['summary'], $locale);

        if ($proposal->result !== null) {
            $data['result']['text'] = $this->translator->translate($proposal->result['summary'], $locale);
        }

        return $data;
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function toolError(User $user, string $key, array $params = []): array
    {
        $strings = [];

        foreach ($params as $name => $value) {
            if (is_string($name) && (is_string($value) || is_int($value))) {
                $strings[$name] = $value;
            }
        }

        $text = $this->translator->translate(new Message($key, $strings), $user->locale);

        return self::content($text, ['error' => $key, 'message' => $text], true);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function content(string $text, array $data, bool $isError = false): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'structuredContent' => $data === [] ? new \stdClass() : $data,
            'isError' => $isError,
        ];
    }

    /**
     * @return array{name: string, version: string, title: string}
     */
    private function serverInfo(): array
    {
        return ['name' => 'campanella-lite', 'version' => Application::VERSION, 'title' => 'Campanella Lite'];
    }

    /**
     * @param array<string, mixed> $result
     */
    private function success(string|int|null $id, array $result, bool $modern): Response
    {
        if ($modern) {
            $result = ['resultType' => 'complete'] + $result;
            $result['_meta'] = ['io.modelcontextprotocol/serverInfo' => $this->serverInfo()];
        }

        return Response::json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result === [] ? new \stdClass() : $result]);
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private function failure(string|int|null $id, int $code, string $message, int $status, ?array $data = null): Response
    {
        $error = ['code' => $code, 'message' => $message];

        if ($data !== null) {
            $error['data'] = $data;
        }

        return Response::json(['jsonrpc' => '2.0', 'id' => $id, 'error' => $error], $status);
    }

    /**
     * Browsers send an Origin; it has to be this site (DNS rebinding protection).
     * Other clients send none.
     */
    private function originAllowed(Request $request): bool
    {
        $origin = $request->header('origin');

        if ($origin === null || $origin === '') {
            return true;
        }

        $host = parse_url($origin, PHP_URL_HOST);
        $port = parse_url($origin, PHP_URL_PORT);
        $expected = strtolower((string) $request->header('host'));

        return is_string($host) && strtolower($host . (is_int($port) ? ':' . $port : '')) === $expected;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function tools(): array
    {
        $commands = [
            'type' => 'string',
            'description' => 'Campanella commands, one per line. Lines may continue with an indented text block, as the help shows.',
        ];
        $reading = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];

        return [
            [
                'name' => 'campanella_me',
                'title' => 'Who am I',
                'description' => 'Shows the AI user this connection signs in as: the commands it may run and which changes wait for an administrator\'s approval.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
                'annotations' => ['title' => 'Who am I'] + $reading,
            ],
            [
                'name' => 'campanella_help',
                'title' => 'Command help',
                'description' => 'Without arguments: the index of all Campanella commands. With a command name (e.g. "page create", "set"): its usage, arguments and examples. Read this before writing commands.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['command' => ['type' => 'string', 'description' => 'A command name, e.g. "content edit". Leave out for the index.']],
                    'additionalProperties' => false,
                ],
                'annotations' => ['title' => 'Command help'] + $reading,
            ],
            [
                'name' => 'campanella_read',
                'title' => 'Read the site',
                'description' => 'Runs commands that only read: list, get, check, log, help, list files. Example: "list pages" or "get page 3". Commands that change something are refused; use campanella_dry_run or campanella_submit for those.',
                'inputSchema' => ['type' => 'object', 'properties' => ['commands' => $commands], 'required' => ['commands'], 'additionalProperties' => false],
                'annotations' => ['title' => 'Read the site'] + $reading,
            ],
            [
                'name' => 'campanella_dry_run',
                'title' => 'Try changes',
                'description' => 'Runs a batch without writing anything and shows what would change, including broken rules. Nothing needs approval. Use it before campanella_submit.',
                'inputSchema' => ['type' => 'object', 'properties' => ['commands' => $commands], 'required' => ['commands'], 'additionalProperties' => false],
                'annotations' => ['title' => 'Try changes'] + $reading,
            ],
            [
                'name' => 'campanella_submit',
                'title' => 'Change the site',
                'description' => 'Runs a batch of commands, all or nothing. If the administrator has to approve these changes, the batch becomes a proposal and waits; the answer then says so and gives its id. Tell the user, and check it later with campanella_proposals.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'commands' => $commands,
                        'session' => ['type' => 'string', 'description' => 'Optional label that groups proposals for the administrator, e.g. the task at hand.'],
                    ],
                    'required' => ['commands'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['title' => 'Change the site', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'campanella_proposals',
                'title' => 'Proposals',
                'description' => 'Shows this AI user\'s proposals, newest first, with their status (pending, applied, failed, rejected) and the administrator\'s reason; or one proposal by its id.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'string', 'description' => 'A proposal id. Leave out for the latest 20.']],
                    'additionalProperties' => false,
                ],
                'annotations' => ['title' => 'Proposals'] + $reading,
            ],
            [
                'name' => 'campanella_upload_file',
                'title' => 'Upload a file',
                'description' => 'Adds a file (image, video, audio, PDF, office document, archive; at most 10 MB) to the file repository and returns its id and the "file:ID" reference to use in content. Adding a file changes nothing on the website and needs no approval.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'filename' => ['type' => 'string', 'description' => 'The file name with its extension, e.g. "team-photo.jpg".'],
                        'content_base64' => ['type' => 'string', 'description' => 'The file\'s bytes, base64-encoded.'],
                    ],
                    'required' => ['filename', 'content_base64'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['title' => 'Upload a file', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false],
            ],
        ];
    }
}
