<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    /**
     * @param array<string, string> $headers
     */
    private function __construct(
        private readonly string $content,
        private readonly int $status,
        private readonly array $headers,
    ) {
    }

    /** @param array<string, string> $headers */
    public static function success(mixed $data = null, array $meta = [], int $status = 200, array $headers = []): self
    {
        $payload = ['ok' => true];

        if ($data !== null) {
            $payload['data'] = $data;
        }

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return self::json($payload, $status, $headers);
    }

    /** @param array<string, string> $headers */
    public static function json(mixed $payload, int $status = 200, array $headers = []): self
    {
        $content = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new self(
            $content === false ? '{"ok":false,"error":{"code":"JSON_ENCODE_ERROR","message":"No se pudo serializar la respuesta."}}' : $content,
            $status,
            array_merge(['Content-Type' => 'application/json; charset=utf-8'], $headers),
        );
    }

    /** @param array<string, string> $fields */
    public static function error(string $code, string $message, int $status, array $fields = []): self
    {
        $error = [
            'code' => $code,
            'message' => $message,
            'request_id' => App::requestId(),
        ];

        if ($fields !== []) {
            $error['fields'] = $fields;
        }

        return self::json(['ok' => false, 'error' => $error], $status);
    }

    public static function noContent(): self
    {
        return new self('', 204, []);
    }

    /** @param array<string, string> $headers */
    public static function raw(string $content, string $contentType, int $status = 200, array $headers = []): self
    {
        return new self($content, $status, array_merge(['Content-Type' => $contentType], $headers));
    }

    public function status(): int
    {
        return $this->status;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function send(): void
    {
        if (headers_sent()) {
            echo $this->content;

            return;
        }

        http_response_code($this->status);

        $this->securityHeaders();
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        if ($this->status !== 204) {
            echo $this->content;
        }
    }

    private function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    }
}
