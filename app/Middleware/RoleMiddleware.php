<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ApiException;

final class RoleMiddleware
{
    public function __construct(private readonly string $requiredRole)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        if (!Auth::check()) {
            throw new ApiException('UNAUTHENTICATED', 'Debes iniciar sesión.', 401);
        }

        $role = Auth::role();
        $allowed = $this->requiredRole === 'admin'
            ? ['admin']
            : ['admin', 'editor'];

        if (!in_array($role, $allowed, true)) {
            throw new ApiException('FORBIDDEN', 'No tienes permisos para esta acción.', 403);
        }

        return $next($request);
    }
}
