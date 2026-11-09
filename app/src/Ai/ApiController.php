<?php

declare(strict_types=1);

namespace Campanella\Lite\Ai;

use Campanella\Lite\Admin\ApiToken;
use Campanella\Lite\Admin\AuthLog;
use Campanella\Lite\Admin\LoginThrottle;
use Campanella\Lite\Admin\User;
use Campanella\Lite\Admin\UserRepository;
use Campanella\Lite\Command\Format\JsonFormatter;
use Campanella\Lite\Files\FileRejected;
use Campanella\Lite\Http\Request;
use Campanella\Lite\Http\Response;
use Campanella\Lite\I18n\Message;
use Campanella\Lite\I18n\Translator;

/**
 * The JSON API for AI users, authenticated with a token:
 *
 *     Authorization: Bearer cl_1a2b3c4d_…
 *
 *     GET  /api/v1/me                 the AI user, its rights and approval settings
 *     POST /api/v1/run                {"commands": "…", "dry_run": false, "session": "…"}
 *     GET  /api/v1/proposals          the AI user's proposals, newest first
 *     GET  /api/v1/proposals/{id}     one proposal
 *     POST /api/v1/files              multipart "files[]": add files to the file repository
 *
 * /api/v1/run answers 200 with the result when the batch ran (or was
 * refused), and 202 with the proposal when it waits for an administrator.
 * Failed sign-ins are throttled per address like admin logins.
 */
final class ApiController
{
    public const PREFIX = '/api/v1';

    private const HEADERS = [
        'Cache-Control' => 'no-store',
        'X-Content-Type-Options' => 'nosniff',
        'X-Robots-Tag' => 'noindex',
    ];

    public function __construct(
        private readonly UserRepository $users,
        private readonly AiGateway $gateway,
        private readonly JsonFormatter $formatter,
        private readonly Translator $translator,
        private readonly LoginThrottle $throttle,
        private readonly AuthLog $log,
        private readonly ?\Closure $clock = null,
    ) {
    }

    public function handle(Request $request): Response
    {
        return $this->dispatch($request)->withHeaders(self::HEADERS);
    }

    private function dispatch(Request $request): Response
    {
        $now = $this->clock !== null ? ($this->clock)() : time();
        $address = $request->clientAddress;
        $retryAfter = $this->throttle->retryAfter($address, $now);

        if ($retryAfter > 0) {
            return $this->error(429, 'api.too_many_attempts', ['minutes' => (int) ceil($retryAfter / 60)], User::DEFAULT_LOCALE)
                ->withHeaders(['Retry-After' => (string) $retryAfter]);
        }

        $header = $request->header('authorization') ?? '';
        $found = str_starts_with($header, 'Bearer ')
            ? $this->users->findByToken(substr($header, 7), (new \DateTimeImmutable())->setTimestamp($now))
            : null;

        if ($found === null) {
            if ($header !== '') {
                $this->throttle->recordFailure($address, $now);
                $this->log->record('api_token_failed', ApiToken::parse(substr($header, 7))[0] ?? '-', $address, $now);
            }

            return $this->error(401, 'api.unauthorized', [], User::DEFAULT_LOCALE)
                ->withHeaders(['WWW-Authenticate' => 'Bearer realm="Campanella Lite"']);
        }

        [$user, $token] = $found;
        $path = rtrim((string) parse_url($request->path, PHP_URL_PATH), '/');
        $route = substr($path, strlen(self::PREFIX));

        return match (true) {
            $route === '/me' && $request->method === 'GET' => $this->me($user),
            $route === '/run' && $request->method === 'POST' => $this->run($request, $user, $token, $now),
            $route === '/proposals' && $request->method === 'GET' => $this->proposals($user),
            preg_match('#^/proposals/([0-9-]+)$#', $route, $match) === 1 && $request->method === 'GET' => $this->proposal($user, $match[1]),
            $route === '/files' && $request->method === 'POST' => $this->files($request, $user),
            default => $this->error(404, 'api.not_found', ['path' => $path], $user->locale),
        };
    }

    private function me(User $user): Response
    {
        return Response::json([
            'user' => $user->name,
            'role' => $user->role,
            'locale' => $user->locale,
            'rights' => $user->rights,
            'approval' => $user->approval,
            'auto_approve' => $user->autoApprove,
        ]);
    }

    private function run(Request $request, User $user, ApiToken $token, int $now): Response
    {
        $body = $request->json();
        $commands = $body['commands'] ?? null;

        if (!is_string($commands) || trim($commands) === '') {
            return $this->error(400, 'api.missing_commands', [], $user->locale);
        }

        $session = is_string($body['session'] ?? null) ? $body['session'] : $token->label . ' ' . date('Y-m-d', $now);
        $submission = $this->gateway->submit($user, $commands, ($body['dry_run'] ?? false) === true, $session);
        $result = $this->formatter->toArray($submission->result, $user->locale);

        if ($submission->proposal !== null) {
            return Response::json([
                'status' => 'pending',
                'message' => $this->translator->translate(new Message('api.proposed', ['id' => $submission->proposal->id]), $user->locale),
                'proposal' => $this->proposalArray($submission->proposal, $user->locale),
                'preview' => $result,
            ], 202);
        }

        return Response::json(['status' => 'done', 'result' => $result]);
    }

    private function proposals(User $user): Response
    {
        return Response::json(['proposals' => array_map(
            fn (Proposal $proposal): array => $this->proposalArray($proposal, $user->locale),
            $this->gateway->proposals()->list($user->name),
        )]);
    }

    private function proposal(User $user, string $id): Response
    {
        $proposal = $this->gateway->proposals()->find($id);

        if ($proposal === null || $proposal->user !== $user->name) {
            return $this->error(404, 'api.proposal_not_found', ['id' => $id], $user->locale);
        }

        return Response::json(['proposal' => $this->proposalArray($proposal, $user->locale)]);
    }

    private function files(Request $request, User $user): Response
    {
        if ($request->files === []) {
            return $this->error(400, 'upload.nothing', [], $user->locale);
        }

        $files = [];

        foreach ($request->files as $upload) {
            try {
                if ($upload->error !== UPLOAD_ERR_OK) {
                    throw new FileRejected('upload.server_error', ['code' => $upload->error]);
                }

                $file = $this->gateway->upload($user, $upload->temporary, $upload->name);
                $files[] = ['ok' => true, 'id' => $file->id, 'filename' => $file->filename, 'kind' => $file->kind(), 'reference' => $file->reference()];
            } catch (FileRejected $rejected) {
                $files[] = ['ok' => false, 'filename' => $upload->name, 'error' => $rejected->reason->key,
                    'message' => $this->translator->translate($rejected->reason, $user->locale)];
            }
        }

        return Response::json(['files' => $files]);
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
     * @param array<string, string|int> $params
     */
    private function error(int $status, string $key, array $params, string $locale): Response
    {
        return Response::json([
            'error' => $key,
            'message' => $this->translator->translate(new Message($key, $params), $locale),
        ], $status);
    }
}
