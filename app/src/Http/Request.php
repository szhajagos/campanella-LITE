<?php

declare(strict_types=1);

namespace Campanella\Lite\Http;

/**
 * A minimal HTTP request, built from PHP's globals or by tests.
 */
final class Request
{
    /**
     * @param array<string, string> $form    form fields (application/x-www-form-urlencoded)
     * @param array<string, string> $headers lowercase names
     * @param array<string, string> $query
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $form = [],
        public readonly array $headers = [],
        public readonly string $body = '',
        public readonly string $clientAddress = '',
        public readonly bool $secure = false,
        public readonly array $query = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = [];

        foreach ($_SERVER as $name => $value) {
            if (is_string($name) && str_starts_with($name, 'HTTP_') && is_string($value)) {
                $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = $value;
            }
        }

        if (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }

        $method = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        $uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
        $https = $_SERVER['HTTPS'] ?? '';

        return new self(
            method: $method,
            path: (string) parse_url($uri, PHP_URL_PATH),
            form: self::strings($_POST),
            headers: $headers,
            body: (string) file_get_contents('php://input', length: 1_048_576),
            clientAddress: is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '',
            secure: is_string($https) && $https !== '' && strtolower($https) !== 'off',
            query: self::strings($_GET),
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * The request body decoded as a JSON object; an empty array if it is not one.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $data = json_decode($this->body, true);

        if (!is_array($data)) {
            return [];
        }

        $object = [];

        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $object[$key] = $value;
            }
        }

        return $object;
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<string, string>
     */
    private static function strings(array $values): array
    {
        $strings = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $strings[$key] = $value;
            }
        }

        return $strings;
    }
}
