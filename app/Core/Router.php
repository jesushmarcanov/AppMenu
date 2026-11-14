<?php

declare(strict_types=1);

namespace App\Core;

use App\Exceptions\ApiException;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RoleMiddleware;

final class Router
{
    /**
     * @param array<int, array{0: string, 1: string, 2: array{0: class-string, 1: string}, 3?: array<int, string>}> $routes
     */
    public function __construct(private readonly array $routes)
    {
    }

    public function dispatch(Request $request): Response
    {
        $allowedMethods = [];

        foreach ($this->routes as $route) {
            [$method, $path, $handler] = $route;
            $middleware = $route[3] ?? [];
            $params = $this->match($path, $request->path());

            if ($params === null) {
                continue;
            }

            if ($method !== $request->method()) {
                $allowedMethods[] = $method;
                continue;
            }

            $middlewares = $this->resolveMiddleware($middleware);
            $core = function (Request $req) use ($handler, $params): Response {
                [$class, $action] = $handler;
                $controller = new $class();
                $response = $controller->{$action}($req, $params);

                if (!$response instanceof Response) {
                    throw new \RuntimeException('El controlador no devolvió una respuesta válida.');
                }

                return $response;
            };

            $pipeline = array_reduce(
                array_reverse($middlewares),
                static fn (callable $next, object $mw): callable => static fn (Request $req): Response => $mw->handle($req, $next),
                $core,
            );

            return $pipeline($request);
        }

        if ($allowedMethods !== []) {
            throw new ApiException('METHOD_NOT_ALLOWED', 'Método no permitido para esta ruta.', 405);
        }

        throw new ApiException('NOT_FOUND', 'La ruta solicitada no existe.', 404);
    }

    /** @return array<string, string>|null */
    private function match(string $routePath, string $requestPath): ?array
    {
        $pattern = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static fn (array $m): string => '(?P<' . $m[1] . '>[^/]+)',
            $routePath,
        );

        if ($pattern === null) {
            return null;
        }

        if (!preg_match('#^' . $pattern . '$#', $requestPath, $matches)) {
            return null;
        }

        $params = [];
        foreach ($matches as $key => $value) {
            if (is_string($key) && $value !== '') {
                $params[$key] = $value;
            }
        }

        return $params;
    }

    /** @param array<int, string> $names @return array<int, object> */
    private function resolveMiddleware(array $names): array
    {
        $middlewares = [];
        foreach ($names as $name) {
            $middlewares[] = match ($name) {
                'auth' => new AuthMiddleware(),
                'admin' => new RoleMiddleware('admin'),
                'editor' => new RoleMiddleware('editor'),
                'csrf' => new CsrfMiddleware(),
                default => throw new \RuntimeException('Middleware desconocido: ' . $name),
            };
        }

        return $middlewares;
    }
}
