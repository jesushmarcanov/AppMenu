<?php

declare(strict_types=1);

namespace App\Core;

use App\Exceptions\ApiException;

final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @param array<string, mixed> $files
     */
    private function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $body,
        private readonly array $headers,
        private readonly array $files,
        private readonly string $ip,
    ) {
    }

    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';

        $basePath = (string) Config::get('app.base_path', '');
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }

        $path = '/' . trim($path, '/');
        if ($path === '/') {
            $path = '/';
        }

        $headers = [];
        foreach (self::readHeaders() as $name => $value) {
            $headers[strtolower((string) $name)] = (string) $value;
        }

        $body = [];
        $contentType = $headers['content-type'] ?? '';

        if (str_contains(strtolower($contentType), 'application/json')) {
            $raw = file_get_contents('php://input') ?: '';
            if (trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    throw new ApiException('INVALID_JSON', 'El cuerpo de la petición no es JSON válido.', 400);
                }
                $body = $decoded;
            }
        } elseif ($_POST !== []) {
            $body = $_POST;
        }

        /** @var array<string, mixed> $query */
        $query = $_GET;
        /** @var array<string, mixed> $files */
        $files = $_FILES;

        return new self($method, $path, $query, $body, $headers, $files, client_ip());
    }

    /** @return array<string, string> */
    private static function readHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                return $headers;
            }
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[$name] = (string) $value;
            }
        }

        return $headers;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    /** @return array<string, mixed> */
    public function query(): array
    {
        return $this->query;
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return $this->body;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    public function queryParam(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body) || array_key_exists($key, $this->query);
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /** @return array<string, mixed> */
    public function files(): array
    {
        return $this->files;
    }

    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;

        return is_array($file) ? $file : null;
    }

    public function ip(): string
    {
        return $this->ip;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }
}
