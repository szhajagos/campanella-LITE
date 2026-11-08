<?php

declare(strict_types=1);

namespace Campanella\Lite\Http;

use Campanella\Lite\Exception\InvalidDataException;
use Campanella\Lite\Exception\StorageException;
use Campanella\Lite\Project\ProjectRepository;
use Campanella\Lite\Render\PageCache;
use Campanella\Lite\Render\PageRenderer;
use Campanella\Lite\Routing\Route;
use Campanella\Lite\Routing\Router;
use Campanella\Lite\Routing\Urls;
use Campanella\Lite\Storage\WriteLock;

/**
 * Answers the requests the web server could not serve from a file.
 *
 * A published page is rendered, stored in the web root, and returned; from
 * then on the web server serves the stored file without PHP. Redirects and
 * "not found" answers are never stored.
 */
final class FrontController
{
    public function __construct(
        private readonly ProjectRepository $repository,
        private readonly WriteLock $lock,
        private readonly Router $router,
        private readonly PageRenderer $renderer,
        private readonly PageCache $cache,
    ) {
    }

    public function handle(string $method, string $uri): Response
    {
        if ($method !== 'GET' && $method !== 'HEAD') {
            return new Response(405, '', ['Allow' => 'GET, HEAD']);
        }

        $path = (string) parse_url($uri, PHP_URL_PATH);

        try {
            return $this->lock->synchronized(fn (): Response => $this->respond($path));
        } catch (InvalidDataException | StorageException) {
            return Response::text("The website is temporarily unavailable.\n", 503);
        }
    }

    private function respond(string $path): Response
    {
        $project = $this->repository->load();
        $route = $this->router->resolve($project, $path);

        if ($route->type === Route::REDIRECT && $route->location !== null) {
            return Response::redirect($route->location);
        }

        if ($route->type === Route::PAGE && $route->page !== null) {
            $html = $this->renderer->render($project, $route->page, $route->language);
            $this->cache->store(Urls::page($project->site, $route->page, $route->language), $html);

            return Response::html($html);
        }

        return Response::html($this->renderer->renderNotFound($project, $route->language), 404);
    }
}
