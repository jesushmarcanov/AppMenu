<?php

declare(strict_types=1);

namespace App\Core;

use App\Exceptions\ApiException;

final class Csrf
{
    public static function token(): string
    {
        $token = Session::get('_csrf_token');
        if (!is_string($token) || strlen($token) < 32) {
            $token = bin2hex(random_bytes(32));
            Session::set('_csrf_token', $token);
        }

        return $token;
    }

    public static function rotate(): string
    {
        Session::forget('_csrf_token');

        return self::token();
    }

    public static function validate(?string $provided = null): void
    {
        $expected = Session::get('_csrf_token');
        $provided ??= $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        $provided ??= $_POST['_csrf'] ?? null;
        $provided ??= $_POST['csrf_token'] ?? null;

        if (!is_string($expected) || $expected === '' || !is_string($provided) || $provided === '') {
            throw new ApiException('CSRF_TOKEN_MISSING', 'Falta el token de seguridad. Recarga la página.', 403);
        }

        if (!hash_equals($expected, $provided)) {
            throw new ApiException('CSRF_TOKEN_INVALID', 'El token de seguridad no es válido. Recarga la página.', 403);
        }
    }
}
