<?php

declare(strict_types=1);

namespace App\Core;

use App\Exceptions\ApiException;
use App\Models\User;

final class Auth
{
    /** @var array<string, mixed>|null */
    private static ?array $user = null;

    public static function attempt(string $email, string $password): bool
    {
        $user = User::findByEmail($email);
        if (!$user || (int) $user['is_active'] !== 1 || !password_verify($password, (string) $user['password_hash'])) {
            return false;
        }

        Session::regenerate();
        Session::set('_user_id', (int) $user['id']);
        Session::set('_last_activity', time());
        Csrf::rotate();

        User::touchLogin((int) $user['id']);
        $user['last_login_at'] = now();
        self::$user = $user;

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            User::updatePassword((int) $user['id'], $password);
        }

        return true;
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
