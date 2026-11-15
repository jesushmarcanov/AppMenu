<?php

declare(strict_types=1);

namespace App\Core;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\TwoFactor;

final class Auth
{
    public const TWO_FACTOR_TTL = 300;

    /** @var array<string, mixed>|null */
    private static ?array $user = null;

    /**
     * Intenta iniciar sesión. Devuelve:
     * - invalid: credenciales incorrectas
     * - 2fa_required: contraseña correcta, falta el código TOTP
     * - ok: sesión iniciada
     */
    public static function attempt(string $email, string $password): string
    {
        $user = User::findByEmail($email);
        if (!$user || (int) $user['is_active'] !== 1 || !password_verify($password, (string) $user['password_hash'])) {
            return 'invalid';
        }

        if (!empty($user['two_factor_enabled_at']) && !empty($user['two_factor_secret'])) {
            Session::regenerate();
            // Un login a medias no debe conservar una identidad previa.
            Session::forget('_user_id');
            Session::forget('_2fa_setup');
            self::$user = null;
            Session::set('_2fa_pending', [
                'user_id' => (int) $user['id'],
                'started' => time(),
                'attempts' => 0,
            ]);
            Session::set('_last_activity', time());
            Csrf::rotate();

            return '2fa_required';
        }

        self::establishSession($user, $password);

        return 'ok';
    }

    /** @return array<string, mixed>|null */
    public static function twoFactorPending(): ?array
    {
        $pending = Session::get('_2fa_pending');
        if (!is_array($pending) || !isset($pending['user_id'], $pending['started'])) {
            return null;
        }

        if (time() - (int) $pending['started'] > self::TWO_FACTOR_TTL) {
            Session::forget('_2fa_pending');

            return null;
        }

        return $pending;
    }

    /**
     * Valida el código TOTP o de recuperación del reto pendiente.
     * Devuelve ok, ok_recovery, invalid o missing.
     */
    public static function completeTwoFactor(string $code): string
    {
        $pending = self::twoFactorPending();
        if ($pending === null) {
            return 'missing';
        }

        $user = User::find((int) $pending['user_id']);
        if (
            !$user
            || (int) $user['is_active'] !== 1
            || empty($user['two_factor_enabled_at'])
            || empty($user['two_factor_secret'])
        ) {
            Session::forget('_2fa_pending');

            return 'invalid';
        }

        if (TwoFactor::verifyCode((string) $user['two_factor_secret'], $code)) {
            Session::forget('_2fa_pending');
            self::establishSession($user);

            return 'ok';
        }

        if (User::consumeRecoveryCode((int) $user['id'], $code)) {
            Session::forget('_2fa_pending');
            self::establishSession($user);

            return 'ok_recovery';
        }

        $pending['attempts'] = (int) ($pending['attempts'] ?? 0) + 1;
        Session::set('_2fa_pending', $pending);

        return 'invalid';
    }

    /** @param array<string, mixed> $user */
    private static function establishSession(array $user, ?string $plainPassword = null): void
    {
        Session::regenerate();
        Session::set('_user_id', (int) $user['id']);
        Session::set('_last_activity', time());
        Session::forget('_2fa_pending');
        Session::forget('_2fa_setup');
        Csrf::rotate();

        User::touchLogin((int) $user['id']);
        $user['last_login_at'] = now();
        self::$user = $user;

        if ($plainPassword !== null && password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            User::updatePassword((int) $user['id'], $plainPassword);
        }
    }

    /** @return array<string, mixed>|null */
    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }

        $id = Session::get('_user_id');
        if (!is_int($id) && !is_numeric($id)) {
            return null;
        }

        $user = User::find((int) $id);
        if (!$user || (int) $user['is_active'] !== 1) {
            self::logout();

            return null;
        }

        self::$user = $user;

        return $user;
    }

    public static function id(): ?int
    {
        $user = self::user();

        return $user ? (int) $user['id'] : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function role(): ?string
    {
        $user = self::user();

        return $user ? (string) $user['role'] : null;
    }

    public static function isAdmin(): bool
    {
        return self::role() === 'admin';
    }

    /** @param array<int, string> $roles */
    public static function requireRole(array $roles): void
    {
        if (!self::check()) {
            throw new ApiException('UNAUTHENTICATED', 'Debes iniciar sesión.', 401);
        }

        if (!in_array(self::role(), $roles, true)) {
            throw new ApiException('FORBIDDEN', 'No tienes permisos para esta acción.', 403);
        }
    }

    public static function isPasswordChangeRequired(): bool
    {
        $user = self::user();

        return $user !== null && (int) $user['must_change_password'] === 1;
    }

    public static function changePassword(string $current, string $new): void
    {
        $user = self::user();
        if (!$user) {
            throw new ApiException('UNAUTHENTICATED', 'Debes iniciar sesión.', 401);
        }

        if (!password_verify($current, (string) $user['password_hash'])) {
            throw new ApiException('INVALID_PASSWORD', 'La contraseña actual no es correcta.', 422, [
                'current_password' => 'La contraseña actual no es correcta.',
            ]);
        }

        if (strlen($new) < 10) {
            throw new ApiException('WEAK_PASSWORD', 'La nueva contraseña debe tener al menos 10 caracteres.', 422, [
                'password' => 'Debe tener al menos 10 caracteres.',
            ]);
        }

        User::updatePassword((int) $user['id'], $new);
        User::clearPasswordChangeFlag((int) $user['id']);
        self::$user = User::find((int) $user['id']);
        audit('auth.password_changed', 'users', (int) $user['id']);
    }

    public static function logout(): void
    {
        self::$user = null;
        Session::destroy();
    }

    /** @return array<string, mixed> */
    public static function publicUser(): array
    {
        $user = self::user();
        if (!$user) {
            throw new ApiException('UNAUTHENTICATED', 'Debes iniciar sesión.', 401);
        }

        return [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'must_change_password' => (int) $user['must_change_password'] === 1,
            'last_login_at' => $user['last_login_at'],
        ];
    }
}
