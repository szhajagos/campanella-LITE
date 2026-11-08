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

    /**
     * @param array<string, mixed> $data
     */
    public static function json(array $data, int $status = 200): self
    {
        return new self($status, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function seeOther(string $location): self
    {
        return new self(303, '', ['Location' => $location]);
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self($this->status, $this->body, $headers + $this->headers);
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
