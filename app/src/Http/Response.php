<?php

declare(strict_types=1);

namespace Campanella\Lite\Http;

/**
 * A minimal HTTP response.
 */
final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body = '',
        public readonly array $headers = [],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function redirect(string $location): self
    {
        return new self(301, '', ['Location' => $location, 'Cache-Control' => 'max-age=3600']);
    }

    public static function text(string $body, int $status): self
    {
        return new self($status, $body, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function send(bool $withBody = true): void
    {
        http_response_code($this->status);

        foreach ($this->headers + ['X-Content-Type-Options' => 'nosniff'] as $name => $value) {
            header($name . ': ' . $value);
        }

        if ($withBody) {
            echo $this->body;
        }
    }
}
