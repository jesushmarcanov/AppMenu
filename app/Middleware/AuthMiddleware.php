<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ApiException;

final class AuthMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        if (!Auth::check()) {
            throw new ApiException('UNAUTHENTICATED', 'Debes iniciar sesión.', 401);
        }

        $allowedWhileChanging = [
            '/api/v1/auth/change-password',
            '/api/v1/auth/logout',
            '/api/v1/auth/me',
        ];

        if (Auth::isPasswordChangeRequired() && !in_array($request->path(), $allowedWhileChanging, true)) {
            throw new ApiException(
                'PASSWORD_CHANGE_REQUIRED',
                'Debes cambiar la contraseña temporal antes de continuar.',
                403,
            );
        }

        return $next($request);
    }
}
