<?php

declare(strict_types=1);

namespace Campanella\Lite\Admin;

use Campanella\Lite\Admin\Session\Session;
use Campanella\Lite\Ai\Proposal;
use Campanella\Lite\Application;
use Campanella\Lite\Command\Format\TextFormatter;
use Campanella\Lite\Command\Handler\ListFilesCommand;
use Campanella\Lite\Command\Output;
use Campanella\Lite\Command\Result\BatchResult;
use Campanella\Lite\Exception\InvalidDataException;
use Campanella\Lite\Files\FileRejected;
use Campanella\Lite\Files\FileTypes;
use Campanella\Lite\Files\ImageResizer;
use Campanella\Lite\Http\Request;
use Campanella\Lite\Http\Response;
use Campanella\Lite\I18n\Message;
use Campanella\Lite\I18n\Translator;
use Campanella\Lite\Install\InstallError;
use Campanella\Lite\Install\Installer;
use Campanella\Lite\Model\File;
use Campanella\Lite\Model\PageStatus;
use Campanella\Lite\Model\Project;
use Campanella\Lite\Routing\Urls;
use Campanella\Lite\Storage\YamlCodec;
use Closure;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Twig\Environment;
use Twig\TwigFunction;

/**
 * The admin area under /admin:
 *
 *     GET  /admin                    login form, or the dashboard when logged in
 *     POST /admin/login              log in (form, CSRF token)
 *     POST /admin/logout             log out (form, CSRF token)
 *     POST /admin/run                run a command batch (JSON, X-CSRF-Token header)
 *     GET  /admin/preview/{id}[/{language}]  show any page, drafts included
 *     POST /admin/upload            add files to the file repository (multipart "files[]")
 *     GET  /admin/files/{id}/thumbnail  a small version of an image, archived ones included
 *     POST /admin/proposals/{id}/approve  run a batch an AI user proposed
 *     POST /admin/proposals/{id}/reject   turn it down (JSON: {"reason": "…"})
 *
 * While there is no admin user, /admin shows the first-time setup instead
 * (POST /admin/setup), protected by the setup key in config/setup-key.txt.
 *
 * Every state-changing request needs the session's CSRF token. Sessions end
 * after IDLE_TIMEOUT seconds without activity and after MAX_LIFETIME seconds
 * in any case. Failed logins are throttled per client address and logged.
 */
final class AdminController
{
    public const IDLE_TIMEOUT = 1800;
    public const MAX_LIFETIME = 43200;

    private const SECURITY_HEADERS = [
        'Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'",
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'same-origin',
        'Cache-Control' => 'no-store',
        'X-Robots-Tag' => 'noindex, nofollow',
    ];

    /** How many of the newest files the file panel shows. */
    private const FILE_PANEL_SIZE = 24;
    private const THUMBNAIL_WIDTH = 240;

    /** A valid hash to verify against when the user name is unknown, so timing does not reveal it. */
    private const DUMMY_HASH = '$2y$10$GgEEND9UhV22LTe9h9cF4.Am97PmGMhmlPtD7JgcvNCdbDTSSV.PW';

    private string $locale = Translator::FALLBACK_LOCALE;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param (Closure(): int)|null $clock current Unix time; replaceable in tests
     */
    public function __construct(
        private readonly Application $app,
        private readonly UserRepository $users,
        private readonly Session $session,
        private readonly LoginThrottle $throttle,
        private readonly AuthLog $log,
        private readonly History $history,
        private readonly Installer $installer,
        private readonly Environment $twig,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
        $this->twig->addFunction(new TwigFunction(
            't',
            fn (string $key, array $params = []): string => $this->translate('admin.' . $key, $params),
        ));
        $this->twig->addFunction(new TwigFunction('asset', fn (string $path): string => $this->app->renderer()->asset($path)));
    }

    public function handle(Request $request): Response
    {
        $path = preg_replace('#/index\.php$#', '', rtrim($request->path, '/')) ?? '';
        $path = $path === '' ? '/admin' : $path;

        if (!$this->users->exists()) {
            $response = match (true) {
                $path === '/admin' && in_array($request->method, ['GET', 'HEAD'], true) => $this->setupPage($request, null, 200),
                $path === '/admin/setup' && $request->method === 'POST' => $this->setup($request),
                default => Response::seeOther('/admin'),
            };

            return $response->withHeaders(self::SECURITY_HEADERS);
        }

        $response = match (true) {
            $path === '/admin' && in_array($request->method, ['GET', 'HEAD'], true) => $this->home(),
            $path === '/admin/login' && $request->method === 'POST' => $this->login($request),
            $path === '/admin/logout' && $request->method === 'POST' => $this->logout($request),
            $path === '/admin/run' && $request->method === 'POST' => $this->run($request),
            $path === '/admin/upload' && $request->method === 'POST' => $this->upload($request),
            str_starts_with($path, '/admin/preview/') && in_array($request->method, ['GET', 'HEAD'], true) => $this->preview($path),
            preg_match('#^/admin/proposals/([0-9-]+)/(approve|reject)$#', $path, $match) === 1 && $request->method === 'POST' => $this->decide($request, $match[1], $match[2]),
            preg_match('#^/admin/files/([1-9][0-9]{0,9})/thumbnail$#', $path, $match) === 1 && in_array($request->method, ['GET', 'HEAD'], true) => $this->thumbnail((int) $match[1]),
            default => Response::text("Not found.\n", 404),
        };

        return $response->withHeaders(self::SECURITY_HEADERS);
    }

    private function home(): Response
    {
        $user = $this->currentUser();

        if ($user === null) {
            return $this->loginPage(null, 200);
        }

        return Response::html($this->twig->render('dashboard.html.twig', [
            'csrf' => $this->csrfToken(),
            'user' => $user->name,
            'locale' => $this->locale,
        ] + $this->panels($user->name)));
    }

    private function login(Request $request): Response
    {
        $now = ($this->clock)();
        $address = $request->clientAddress;
        $name = trim($request->form['username'] ?? '');

        if (!$this->validCsrf($request)) {
            return $this->loginPage('session_expired', 400);
        }

        $retryAfter = $this->throttle->retryAfter($address, $now);

        if ($retryAfter > 0) {
            $this->log->record('login_blocked', $name, $address, $now);

            return $this->loginPage('too_many_attempts', 429, ['minutes' => (int) ceil($retryAfter / 60)])
                ->withHeaders(['Retry-After' => (string) $retryAfter]);
        }

        $user = $this->users->find($name);
        $password = $request->form['password'] ?? '';
        if ($user === null) {
            password_verify($password, self::DUMMY_HASH);
        }

        if ($user === null || !$user->verify($password)) {
            $this->throttle->recordFailure($address, $now);
            $this->log->record('login_failed', $name, $address, $now);

            return $this->loginPage('invalid_credentials', 401);
        }

        if ($user->needsRehash()) {
            $this->users->save($user->withPassword($password));
        }

        $this->throttle->reset($address, $now);
        $this->session->regenerate();
        $this->session->set('user', $user->name);
        $this->session->set('login_at', $now);
        $this->session->set('seen_at', $now);
        $this->session->set('csrf', bin2hex(random_bytes(32)));
        $this->log->record('login_ok', $user->name, $address, $now);

        return Response::seeOther('/admin');
    }

    private function logout(Request $request): Response
    {
        $user = $this->currentUser();

        if ($user !== null && $this->validCsrf($request)) {
            $this->log->record('logout', $user->name, $request->clientAddress, ($this->clock)());
            $this->session->destroy();
        }

        return Response::seeOther('/admin');
    }

    private function run(Request $request): Response
    {
        $user = $this->currentUser();

        if ($user === null) {
            return Response::json(['error' => 'not_logged_in'], 401);
        }

        if (!$this->validCsrf($request)) {
            return Response::json(['error' => 'invalid_csrf_token'], 403);
        }

        $data = $request->json();
        $commands = is_string($data['commands'] ?? null) ? $data['commands'] : '';
        $dryRun = ($data['dry_run'] ?? false) === true;

        $result = $this->app->interpreter()->run($commands, $dryRun, $user->name);
        $this->history->add($user->name, $commands);

        // The batch may have changed the admin's language.
        $this->locale = $this->users->find($user->name)->locale ?? $this->locale;
        $panels = $this->panels($user->name);

        return Response::json([
            'ok' => $result->isSuccess(),
            'outcome' => $result->outcome->value,
            'html' => $this->twig->render('response.html.twig', $this->responseView($result) + ['locale' => $this->locale]),
            'text' => (new TextFormatter($this->app->translator()))->format($result, $this->locale),
            'status_html' => $this->twig->render('status.html.twig', $panels),
            'pages_html' => $this->twig->render('pages.html.twig', $panels),
            'files_html' => $this->twig->render('files.html.twig', $panels),
            'history_html' => $this->twig->render('history.html.twig', $panels),
            'proposals_html' => $this->twig->render('proposals.html.twig', $panels),
            'site_name' => $panels['site_name'],
        ]);
    }

    /**
     * Approves or rejects a batch an AI user proposed.
     */
    private function decide(Request $request, string $id, string $action): Response
    {
        $admin = $this->currentUser();

        if ($admin === null) {
            return Response::json(['error' => 'not_logged_in'], 401);
        }

        if (!$this->validCsrf($request)) {
            return Response::json(['error' => 'invalid_csrf_token'], 403);
        }

        $gateway = $this->app->aiGateway();
        $proposal = $gateway->proposals()->find($id);
        $ai = $proposal === null ? null : $this->users->find($proposal->user);
        $error = match (true) {
            $proposal === null => new Message('proposal.not_found', ['id' => $id]),
            !$proposal->isPending() => new Message('proposal.decided', ['id' => $id, 'status' => $proposal->status]),
            $ai === null || !$ai->isAi() => new Message('proposal.user_gone', ['id' => $id, 'user' => $proposal->user]),
            default => null,
        };

        if ($error !== null || $proposal === null || $ai === null) {
            $html = '<div class="response-line line-error">' . htmlspecialchars($this->translateMessage($error ?? new Message('proposal.not_found', ['id' => $id]))) . '</div>';
            $result = null;
        } elseif ($action === 'approve') {
            $result = $gateway->approve($proposal, $ai, $admin);
            $html = $this->twig->render('response.html.twig', $this->responseView($result) + ['locale' => $this->locale]);
        } else {
            $reason = $request->json()['reason'] ?? null;
            $gateway->reject($proposal, $admin, is_string($reason) && trim($reason) !== '' ? mb_substr(trim($reason), 0, 500) : null);
            $html = '<div class="response-line line-ok">' . htmlspecialchars($this->translateMessage(new Message('proposal.rejected', ['id' => $id, 'user' => $proposal->user]))) . '</div>';
            $result = null;
        }

        $panels = $this->panels($admin->name);

        return Response::json([
            'ok' => $error === null && ($result === null || $result->isSuccess()),
            'outcome' => $result?->outcome->value,
            'html' => $html,
            'status_html' => $this->twig->render('status.html.twig', $panels),
            'pages_html' => $this->twig->render('pages.html.twig', $panels),
            'files_html' => $this->twig->render('files.html.twig', $panels),
            'history_html' => $this->twig->render('history.html.twig', $panels),
            'proposals_html' => $this->twig->render('proposals.html.twig', $panels),
            'site_name' => $panels['site_name'],
        ]);
    }

    /**
     * Pending proposals (all) and the latest decided ones, for the dashboard.
     *
     * @return array{pending: list<array<string, mixed>>, decided: list<array<string, mixed>>, ai_users: bool}
     */
    private function proposalList(): array
    {
        $store = $this->app->aiGateway()->proposals();
        $view = fn (Proposal $proposal): array => [
            'id' => $proposal->id,
            'user' => $proposal->user,
            'session' => $proposal->session,
            'created' => substr(str_replace('T', ' ', $proposal->createdAt), 0, 16),
            'batch' => $proposal->batch,
            'preview' => $this->translateMessage($proposal->preview['summary']),
            'status' => $proposal->status,
            'decided_by' => $proposal->decidedBy,
            'result' => $proposal->result === null ? null : $this->translateMessage($proposal->result['summary']),
        ];

        return [
            'pending' => array_map($view, $store->list(pending: true, limit: 100)),
            'decided' => array_map($view, $store->list(pending: false, limit: 5)),
            'ai_users' => array_filter($this->users->all(), static fn (User $user): bool => $user->isAi()) !== [],
        ];
    }

    /**
     * The newest files, for the file panel.
     *
     * @return list<array<string, mixed>>
     */
    private function fileList(Project $project): array
    {
        $files = array_reverse(array_values(array_filter($project->files, static fn (File $file): bool => !$file->isArchived())));
        $list = [];

        foreach (array_slice($files, 0, self::FILE_PANEL_SIZE) as $file) {
            $list[] = [
                'id' => $file->id,
                'filename' => $file->filename,
                'kind' => $file->kind(),
                'extension' => $file->extension,
                'size' => ListFilesCommand::size($file),
                'reference' => self::reference($file),
                'thumbnail' => $file->kind() === FileTypes::IMAGE ? '/admin/files/' . $file->id . '/thumbnail' : null,
            ];
        }

        return $list;
    }

    /** What to write in content to use a file. */
    private static function reference(File $file): string
    {
        return in_array($file->kind(), [FileTypes::IMAGE, FileTypes::AUDIO, FileTypes::VIDEO], true)
            ? '![](' . $file->reference() . ')'
            : '[' . str_replace(['[', ']'], '', $file->filename) . '](' . $file->reference() . ')';
    }

    /**
     * A small version of an image for the file panel, kept in var/thumbnails/.
     */
    private function thumbnail(int $id): Response
    {
        if ($this->currentUser() === null) {
            return Response::text("Not logged in.\n", 401);
        }

        $file = $this->app->repository()->load()->file($id);
        $source = $file === null ? null : $this->app->fileRepository()->pathOf($file);

        if ($file === null || $source === null || $file->kind() !== FileTypes::IMAGE) {
            return Response::text("Not found.\n", 404);
        }

        $width = $file->width ?? 0;
        $height = $file->height ?? 0;

        if ($file->extension === 'gif' || !ImageResizer::canResize($width, $height, $file->size)) {
            return Response::file($source, $file->mimeType);
        }

        $directory = $this->app->paths->var() . '/thumbnails';
        $target = $directory . '/' . $file->id . '-' . substr(sha1($file->storedFilename), 0, 12) . '.' . $file->extension;

        if (!is_file($target)) {
            if (!is_dir($directory)) {
                @mkdir($directory, 0775, true);
            }

            try {
                (new ImageResizer())->resize($source, $target, self::THUMBNAIL_WIDTH, $file->extension);
            } catch (FileRejected) {
                return Response::text("Not found.\n", 404);
            }
        }

        return Response::file($target, $file->mimeType);
    }

    /**
     * Adds the uploaded files to the file repository. Uploading changes
     * nothing on the website; the response gives each file's id, to be
     * used by commands afterwards.
     */
    private function upload(Request $request): Response
    {
        $user = $this->currentUser();

        if ($user === null) {
            return Response::json(['error' => 'not_logged_in'], 401);
        }

        if (!$this->validCsrf($request)) {
            return Response::json(['error' => 'invalid_csrf_token'], 403);
        }

        $repository = $this->app->fileRepository();
        $results = [];

        if ($request->files === []) {
            $tooLarge = (int) ($request->header('content-length') ?? 0) > 0;
            $results[] = ['ok' => false, 'filename' => '', 'message' => $tooLarge
                ? $this->translateMessage(new Message('upload.server_limit', ['limit' => (string) ini_get('post_max_size')]))
                : $this->translateMessage(new Message('upload.nothing'))];
        }

        foreach ($request->files as $upload) {
            $name = mb_substr(mb_scrub($upload->name, 'UTF-8'), 0, 200);

            try {
                if ($upload->error !== UPLOAD_ERR_OK) {
                    throw new FileRejected(...self::uploadError($upload->error));
                }

                $file = $repository->add($upload->temporary, $upload->name, $user->name);
                $results[] = [
                    'ok' => true,
                    'filename' => $file->filename,
                    'message' => $this->translateMessage(new Message('upload.ok', ['filename' => $file->filename, 'id' => $file->id])),
                    'id' => $file->id,
                    'kind' => $file->kind(),
                    'size' => ListFilesCommand::size($file),
                    'reference' => self::reference($file),
                ];
            } catch (FileRejected $rejected) {
                $results[] = ['ok' => false, 'filename' => $name, 'message' => $this->translateMessage(
                    new Message('upload.failed', ['filename' => $name, 'reason' => $this->translateMessage($rejected->reason)]),
                )];
            }
        }

        $panels = $this->panels($user->name);
        $ok = array_filter($results, static fn (array $result): bool => $result['ok']) !== [];

        return Response::json([
            'ok' => $ok,
            'files' => array_values(array_map(
                static fn (array $result): array => ['id' => $result['id'], 'filename' => $result['filename'], 'reference' => $result['reference']],
                array_filter($results, static fn (array $result): bool => $result['ok']),
            )),
            'html' => $this->twig->render('upload.html.twig', ['results' => $results, 'locale' => $this->locale]),
            'text' => implode("\n", array_column($results, 'message')),
            'status_html' => $this->twig->render('status.html.twig', $panels),
            'files_html' => $this->twig->render('files.html.twig', $panels),
            'proposals_html' => $this->twig->render('proposals.html.twig', $panels),
        ]);
    }

    /**
     * @return array{0: string, 1: array<string, string|int>}
     */
    private static function uploadError(int $error): array
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => ['upload.server_limit', ['limit' => (string) ini_get('upload_max_filesize')]],
            UPLOAD_ERR_PARTIAL => ['upload.partial', []],
            default => ['upload.server_error', ['code' => $error]],
        };
    }

    /**
     * The first-time setup form: setup key, starter project, first admin user.
     *
     * @param array<string, string> $values the submitted values to show again
     */
    private function setupPage(Request $request, ?string $error, int $status, array $values = []): Response
    {
        $this->locale = $this->setupLocale($request);
        $fatal = null;

        try {
            $this->installer->setupKey();
        } catch (InstallError $failure) {
            $fatal = $this->translateMessage($failure->reason);
        }

        return Response::html($this->twig->render('setup.html.twig', [
            'csrf' => $this->csrfToken(),
            'locale' => $this->locale,
            'locales' => $this->app->translator()->locales(),
            'error' => $error,
            'fatal' => $fatal,
            'key_location' => $this->installer->setupKeyLocation(),
            'has_project' => $this->installer->hasProject(),
            'starters' => Installer::STARTERS,
            'values' => $values + [
                'starter' => in_array($this->locale, Installer::STARTERS, true) ? $this->locale : Installer::STARTERS[0],
                'site_name' => '',
                'username' => 'admin',
            ],
        ]), $status);
    }

    private function setup(Request $request): Response
    {
        $this->locale = $this->setupLocale($request);
        $now = ($this->clock)();
        $address = $request->clientAddress;
        $values = [
            'starter' => (string) ($request->form['starter'] ?? ''),
            'site_name' => trim((string) ($request->form['site_name'] ?? '')),
            'username' => trim((string) ($request->form['username'] ?? '')),
        ];
        /** @param array<string, string|int> $params */
        $fail = fn (string $key, array $params = [], int $status = 422): Response => $this->setupPage(
            $request,
            $this->translate($key, $params),
            $status,
            $values,
        );

        if (!$this->validCsrf($request)) {
            return $fail('admin.login.session_expired', [], 400);
        }

        $retryAfter = $this->throttle->retryAfter($address, $now);

        if ($retryAfter > 0) {
            $this->log->record('setup_blocked', $values['username'], $address, $now);

            return $fail('admin.login.too_many_attempts', ['minutes' => (int) ceil($retryAfter / 60)], 429)
                ->withHeaders(['Retry-After' => (string) $retryAfter]);
        }

        if (!$this->installer->checkSetupKey((string) ($request->form['setup_key'] ?? ''))) {
            $this->throttle->recordFailure($address, $now);
            $this->log->record('setup_failed', $values['username'], $address, $now);

            return $fail('admin.setup.wrong_key', ['file' => $this->installer->setupKeyLocation()], 403);
        }

        $password = (string) ($request->form['password'] ?? '');

        if (!hash_equals($password, (string) ($request->form['password_repeat'] ?? ''))) {
            return $fail('admin.setup.passwords_differ');
        }

        $locale = $this->setupLocale($request);

        try {
            if (!$this->installer->hasProject()) {
                $host = $request->header('host') ?? '';
                $baseUrl = preg_match('/^[A-Za-z0-9.-]+(?::[0-9]{1,5})?$/', $host) === 1 ? ($request->secure ? 'https://' : 'http://') . $host : '';
                // Validate the user before copying the starter, so a typo does not leave a half-done setup behind.
                $this->installer->validateUser($values['username'], $password);
                $this->installer->createProject($values['starter'], $values['site_name'], $baseUrl);
            }

            $user = $this->installer->createUser($values['username'], $password, $locale);
        } catch (InstallError $failure) {
            return $this->setupPage($request, $this->translateMessage($failure->reason), 422, $values);
        }

        $this->throttle->reset($address, $now);
        $this->session->regenerate();
        $this->session->set('user', $user->name);
        $this->session->set('login_at', $now);
        $this->session->set('seen_at', $now);
        $this->session->set('csrf', bin2hex(random_bytes(32)));
        $this->log->record('setup_ok', $user->name, $address, $now);

        return Response::seeOther('/admin');
    }

    /** The language of the setup page: ?lang=, the form, the browser, or English. */
    private function setupLocale(Request $request): string
    {
        $locales = $this->app->translator()->locales();
        $candidates = [(string) ($request->query['lang'] ?? ''), (string) ($request->form['locale'] ?? '')];

        foreach (explode(',', $request->header('accept-language') ?? '') as $part) {
            $candidates[] = strtolower(substr(trim(explode(';', $part)[0]), 0, 2));
        }

        foreach ($candidates as $candidate) {
            if (in_array($candidate, $locales, true)) {
                return $candidate;
            }
        }

        return Translator::FALLBACK_LOCALE;
    }

    private function preview(string $path): Response
    {
        if ($this->currentUser() === null) {
            return Response::seeOther('/admin');
        }

        if (preg_match('#^/admin/preview/([1-9][0-9]*)(?:/([a-z]{2,3}(?:-[A-Z]{2})?))?$#', $path, $match) !== 1) {
            return Response::text("Not found.\n", 404);
        }

        try {
            $project = $this->app->repository()->load();
        } catch (InvalidDataException $exception) {
            return Response::text($exception->getMessage() . "\n", 503);
        }

        $page = $project->page((int) $match[1]);
        $language = $match[2] ?? $project->site->defaultLanguage;

        if ($page === null || !in_array($language, $project->site->languages, true)) {
            return Response::text("Not found.\n", 404);
        }

        return Response::html($this->app->renderer()->render($project, $page, $language));
    }

    /**
     * The logged-in user, or null. Ends sessions that are idle or too old.
     */
    private function currentUser(): ?User
    {
        $name = $this->session->get('user');

        if (!is_string($name)) {
            return null;
        }

        $now = ($this->clock)();
        $loginAt = $this->session->get('login_at');
        $seenAt = $this->session->get('seen_at');
        $user = $this->users->find($name);

        if ($user === null || !$user->isAdmin() || !is_int($loginAt) || !is_int($seenAt)
            || $now - $seenAt > self::IDLE_TIMEOUT || $now - $loginAt > self::MAX_LIFETIME) {
            $this->session->destroy();

            return null;
        }

        $this->session->set('seen_at', $now);
        $this->locale = in_array($user->locale, $this->app->translator()->locales(), true) ? $user->locale : Translator::FALLBACK_LOCALE;

        return $user;
    }

    private function csrfToken(): string
    {
        $token = $this->session->get('csrf');

        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $this->session->set('csrf', $token);
        }

        return $token;
    }

    private function validCsrf(Request $request): bool
    {
        $expected = $this->session->get('csrf');
        $given = $request->header('x-csrf-token') ?? $request->form['_csrf'] ?? '';

        return is_string($expected) && strlen($expected) === 64 && hash_equals($expected, $given);
    }

    /**
     * @param array<string, string|int> $params
     */
    private function loginPage(?string $error, int $status, array $params = []): Response
    {
        return Response::html($this->twig->render('login.html.twig', [
            'csrf' => $this->csrfToken(),
            'locale' => $this->locale,
            'error' => $error === null ? null : $this->translate('admin.login.' . $error, $params),
        ]), $status);
    }

    /**
     * Status bar, page list and history.
     *
     * @return array<string, mixed>
     */
    private function panels(string $user): array
    {
        $panels = ['history' => $this->history->list($user), 'pages' => [], 'files' => [], 'status' => null, 'project_error' => null, 'site_name' => 'Campanella Lite', 'locale' => $this->locale,
            'proposals' => $this->proposalList()];

        try {
            $project = $this->app->repository()->load();
        } catch (InvalidDataException $exception) {
            $panels['project_error'] = $exception->getMessage();

            return $panels;
        }

        $published = count(array_filter($project->pages, static fn ($page): bool => $page->status === PageStatus::Published));
        $panels['site_name'] = $project->site->name;
        $panels['status'] = [
            'pages' => count($project->pages),
            'published' => $published,
            'drafts' => count($project->pages) - $published,
            'generated' => count($this->app->pageCache()->files()),
            'languages' => implode(', ', $project->site->languages),
            'changed' => $this->lastChange(),
            'proposals' => count($panels['proposals']['pending']),
        ];
        $panels['pages'] = $this->pageList($project);
        $panels['files'] = $this->fileList($project);

        return $panels;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pageList(Project $project): array
    {
        $language = $project->site->defaultLanguage;
        $pages = [];

        foreach ($project->pages as $page) {
            $pages[] = [
                'id' => $page->id,
                'title' => $page->title->get($language) ?? '',
                'url' => Urls::page($project->site, $page, $language),
                'published' => $page->status === PageStatus::Published,
                'preview' => '/admin/preview/' . $page->id,
            ];
        }

        return $pages;
    }

    private function lastChange(): ?string
    {
        $directory = $this->app->paths->project();
        $latest = 0;

        if (is_dir($directory)) {
            /** @var iterable<\SplFileInfo> $files */
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));

            foreach ($files as $file) {
                $latest = max($latest, $file->getMTime());
            }
        }

        return $latest === 0 ? null : date('Y-m-d H:i', $latest);
    }

    /**
     * The batch result as plain arrays with translated texts, for response.html.twig.
     *
     * @return array<string, mixed>
     */
    private function responseView(BatchResult $result): array
    {
        $lines = [];

        foreach ($result->lines as $line) {
            $lines[] = [
                'line' => $line->line,
                'status' => $line->status->value,
                'source' => $line->source,
                'message' => $this->translateMessage($line->message),
                'output' => $line->output === null ? null : $this->outputView($line->output),
            ];
        }

        return [
            'ok' => $result->isSuccess(),
            'outcome' => $result->outcome->value,
            'lines' => $lines,
            'violations' => array_map(fn ($violation): array => [
                'text' => $this->app->translator()->translateViolation($violation, $this->locale),
                'path' => $violation->path,
            ], $result->violations),
            'summary' => $this->translateMessage($result->summary),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function outputView(Output $output): array
    {
        if ($output->type === Output::TABLE) {
            /** @var array{columns: list<string>, rows: list<list<string|Message>>} $table */
            $table = $output->value;

            return ['type' => 'table', 'columns' => $table['columns'], 'rows' => array_map(
                fn (array $row): array => array_map(fn (string|Message $cell): string => $cell instanceof Message ? $this->translateMessage($cell) : $cell, $row),
                $table['rows'],
            )];
        }

        if ($output->type === Output::LINES) {
            /** @var list<string|Message> $lines */
            $lines = $output->value;

            return ['type' => 'lines', 'lines' => array_map(fn (string|Message $line): string => $line instanceof Message ? $this->translateMessage($line) : $line, $lines)];
        }

        return ['type' => 'value', 'text' => is_array($output->value) ? YamlCodec::encode($output->value) : null];
    }

    private function translateMessage(Message $message): string
    {
        return $this->app->translator()->translate($message, $this->locale);
    }

    /**
     * @param array<string, string|int> $params
     */
    private function translate(string $key, array $params = []): string
    {
        return $this->app->translator()->translate(new Message($key, $params), $this->locale);
    }
}
