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

        $result = Auth::attempt($email, (string) $data['password']);
        if ($result === 'invalid') {
            AuditLog::record(null, 'auth.login_failed', 'users', null, ['email' => $email]);
            throw new ApiException('INVALID_CREDENTIALS', 'Correo o contraseña incorrectos.', 401);
        }

        RateLimit::clear($rateKey);

        if ($result === '2fa_required') {
            $pending = Auth::twoFactorPending();
            AuditLog::record(null, 'auth.2fa_challenge', 'users', (int) ($pending['user_id'] ?? 0), ['email' => $email]);

            return Response::success([
                'two_factor_required' => true,
                'expires_in' => Auth::TWO_FACTOR_TTL,
                'csrf_token' => Csrf::token(),
            ]);
        }

        audit('auth.login', 'users', Auth::id(), ['via' => 'password']);

        return Response::success([
            'user' => Auth::publicUser(),
            'csrf_token' => Csrf::token(),
            'must_change_password' => Auth::isPasswordChangeRequired(),
        ]);
    }

    public function twoFactor(Request $request, array $params = []): Response
    {
        $data = Validator::validate($request->body(), [
            'code' => 'required|string|maxlen:20',
        ]);

        $pending = Auth::twoFactorPending();
        if ($pending === null) {
            throw new ApiException(
                'TWO_FACTOR_NOT_STARTED',
                'El reto de verificación expiró. Inicia sesión de nuevo.',
                401,
            );
        }

        $userId = (int) $pending['user_id'];
        $rateKey = '2fa:' . $request->ip() . ':' . $userId;
        RateLimit::hit($rateKey, 5, 900);

        $status = Auth::completeTwoFactor((string) $data['code']);

        if ($status === 'missing') {
            throw new ApiException(
                'TWO_FACTOR_EXPIRED',
                'El reto de verificación expiró. Inicia sesión de nuevo.',
                401,
            );
        }

        if ($status === 'invalid') {
            AuditLog::record(null, 'auth.2fa_failed', 'users', $userId, []);
            throw new ApiException('INVALID_2FA_CODE', 'El código no es válido o ya expiró.', 401, [
                'code' => 'Introduce un código válido de 6 dígitos.',
            ]);
        }

        RateLimit::clear($rateKey);

        if ($status === 'ok_recovery') {
            audit('auth.2fa_recovery_used', 'users', Auth::id());
        }

        audit('auth.login', 'users', Auth::id(), ['via' => $status === 'ok_recovery' ? 'recovery' : 'totp']);

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
