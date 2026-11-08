<?php

declare(strict_types=1);

namespace Campanella\Lite;

use InvalidArgumentException;

/**
 * Knows where the parts of an installation live.
 *
 * Only the public directory is meant to be exposed by the web server;
 * everything else stays outside the web root.
 */
final class Paths
{
    private readonly string $root;

    public function __construct(string $root)
    {
        $root = rtrim($root, '/\\');

        if ($root === '' || !is_dir($root)) {
            throw new InvalidArgumentException(sprintf('The installation root does not exist: "%s".', $root));
        }

        $this->root = $root;
    }

    public function root(): string
    {
        return $this->root;
    }

    /** The website description: YAML and Markdown files changed by commands. */
    public function project(): string
    {
        return $this->root . '/project';
    }

    /** Runtime data: logs, locks and undo snapshots. */
    public function var(): string
    {
        return $this->root . '/var';
    }

    /** The web root: front controller, admin entry point, assets and generated HTML. */
    public function public(): string
    {
        return $this->root . '/public';
    }

    /** Installation settings that are not part of the website: admin users. */
    public function config(): string
    {
        return $this->root . '/config';
    }

    /** The file whose lock serialises every write to the project. */
    public function writeLock(): string
    {
        return $this->var() . '/lock/write.lock';
    }
}
