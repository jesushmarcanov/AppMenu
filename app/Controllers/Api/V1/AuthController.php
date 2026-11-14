<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Exceptions\ApiException;
use App\Models\AuditLog;
use App\Models\RateLimit;

final class AuthController
{
    public function csrf(Request $request, array $params = []): Response
    {
        return Response::success(['csrf_token' => Csrf::token()]);
    }

    public function login(Request $request, array $params = []): Response
    {
        $data = Validator::validate($request->body(), [
            'email' => 'required|string|email|maxlen:190',
            'password' => 'required|string|minlen:1',
        ]);

        $email = mb_strtolower(trim((string) $data['email']));
        $rateKey = 'login:' . $request->ip() . ':' . $email;
        RateLimit::hit($rateKey, 5, 900);

        if (!Auth::attempt($email, (string) $data['password'])) {
            AuditLog::record(null, 'auth.login_failed', 'users', null, ['email' => $email]);
            throw new ApiException('INVALID_CREDENTIALS', 'Correo o contraseña incorrectos.', 401);
        }

        RateLimit::clear($rateKey);
        audit('auth.login', 'users', Auth::id());

        return Response::success([
            'user' => Auth::publicUser(),
            'csrf_token' => Csrf::token(),
            'must_change_password' => Auth::isPasswordChangeRequired(),
        ]);
    }

    public function me(Request $request, array $params = []): Response
    {
        return Response::success([
            'user' => Auth::publicUser(),
            'csrf_token' => Csrf::token(),
            'must_change_password' => Auth::isPasswordChangeRequired(),
        ]);
    }

    public function logout(Request $request, array $params = []): Response
    {
        audit('auth.logout', 'users', Auth::id());
        Auth::logout();

        return Response::noContent();
    }

    public function changePassword(Request $request, array $params = []): Response
    {
        $data = Validator::validate($request->body(), [
            'current_password' => 'required|string',
            'password' => 'required|string|minlen:10|maxlen:120',
            'password_confirmation' => 'required|string|minlen:10|maxlen:120',
        ]);

        if ($data['password'] !== $data['password_confirmation']) {
            throw new ApiException('PASSWORD_MISMATCH', 'Las contraseñas no coinciden.', 422, [
                'password_confirmation' => 'Las contraseñas no coinciden.',
            ]);
        }

        Auth::changePassword((string) $data['current_password'], (string) $data['password']);

        return Response::success([
            'user' => Auth::publicUser(),
            'csrf_token' => Csrf::token(),
            'must_change_password' => false,
        ]);
    }
}
