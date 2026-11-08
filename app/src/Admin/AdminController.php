<?php

declare(strict_types=1);

namespace Campanella\Lite\Admin;

use Campanella\Lite\Admin\Session\Session;
use Campanella\Lite\Application;
use Campanella\Lite\Command\Format\TextFormatter;
use Campanella\Lite\Command\Output;
use Campanella\Lite\Command\Result\BatchResult;
use Campanella\Lite\Exception\InvalidDataException;
use Campanella\Lite\Http\Request;
use Campanella\Lite\Http\Response;
use Campanella\Lite\I18n\Message;
use Campanella\Lite\I18n\Translator;
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
        private readonly Environment $twig,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
        $this->twig->addFunction(new TwigFunction(
            't',
            fn (string $key, array $params = []): string => $this->translate('admin.' . $key, $params),
        ));
    }

    public function handle(Request $request): Response
    {
        $path = preg_replace('#/index\.php$#', '', rtrim($request->path, '/')) ?? '';
        $path = $path === '' ? '/admin' : $path;

        $response = match (true) {
            $path === '/admin' && in_array($request->method, ['GET', 'HEAD'], true) => $this->home(),
            $path === '/admin/login' && $request->method === 'POST' => $this->login($request),
            $path === '/admin/logout' && $request->method === 'POST' => $this->logout($request),
            $path === '/admin/run' && $request->method === 'POST' => $this->run($request),
            str_starts_with($path, '/admin/preview/') && in_array($request->method, ['GET', 'HEAD'], true) => $this->preview($path),
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
            'history_html' => $this->twig->render('history.html.twig', $panels),
            'site_name' => $panels['site_name'],
        ]);
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

        if ($user === null || !is_int($loginAt) || !is_int($seenAt)
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
            'no_users' => !$this->users->exists(),
        ]), $status);
    }

    /**
     * Status bar, page list and history.
     *
     * @return array<string, mixed>
     */
    private function panels(string $user): array
    {
        $panels = ['history' => $this->history->list($user), 'pages' => [], 'status' => null, 'project_error' => null, 'site_name' => 'Campanella Lite', 'locale' => $this->locale];

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
        ];
        $panels['pages'] = $this->pageList($project);

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
