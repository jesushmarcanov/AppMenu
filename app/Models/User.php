<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;
use App\Services\TwoFactor;

final class User extends Model
{
    protected static string $table = 'users';

    /** @return array<string, mixed>|null */
    public static function findByEmail(string $email): ?array
    {
        return Database::fetch(
            'SELECT * FROM users WHERE email = :email LIMIT 1',
            ['email' => mb_strtolower(trim($email))],
        );
    }

    /** @return array<int, array<string, mixed>> */
    public static function allSafe(): array
    {
        return Database::fetchAll(
            'SELECT id, name, email, role, is_active, must_change_password, last_login_at, created_at
             FROM users ORDER BY id ASC',
        );
    }

    /** @param array<string, mixed> $data */
    public static function createUser(array $data): int
    {
        return self::create($data);
    }

    public static function updatePassword(int $id, string $plainPassword): void
    {
        self::update($id, [
            'password_hash' => password_hash($plainPassword, PASSWORD_DEFAULT),
            'updated_at' => now(),
        ]);
    }

    public static function clearPasswordChangeFlag(int $id): void
    {
        self::update($id, [
            'must_change_password' => 0,
            'updated_at' => now(),
        ]);
    }

    public static function enableTwoFactor(int $id, string $encryptedSecret, string $recoveryCodesJson): void
    {
        self::update($id, [
            'two_factor_secret' => $encryptedSecret,
            'two_factor_enabled_at' => now(),
            'two_factor_recovery_codes' => $recoveryCodesJson,
            'updated_at' => now(),
        ]);
    }

    public static function disableTwoFactor(int $id): void
    {
        self::update($id, [
            'two_factor_secret' => null,
            'two_factor_enabled_at' => null,
            'two_factor_recovery_codes' => null,
            'updated_at' => now(),
        ]);
    }

    public static function updateRecoveryCodes(int $id, string $recoveryCodesJson): void
    {
        self::update($id, [
            'two_factor_recovery_codes' => $recoveryCodesJson,
            'updated_at' => now(),
        ]);
    }

    /**
     * Verifica y consume un código de recuperación. Devuelve true si era válido.
     */
    public static function consumeRecoveryCode(int $id, string $code): bool
    {
        $user = self::find($id);
        if ($user === null) {
            return false;
        }

        $json = (string) ($user['two_factor_recovery_codes'] ?? '');
        $index = TwoFactor::verifyRecoveryCode($json, $code);
        if ($index === null) {
            return false;
        }

        $hashes = TwoFactor::decodeRecoveryCodes($json);
        unset($hashes[$index]);

        self::updateRecoveryCodes($id, json_encode(array_values($hashes), JSON_THROW_ON_ERROR));

        return true;
    }

    public static function touchLogin(int $id): void
    {
        self::update($id, [
            'last_login_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public static function countAdmins(bool $activeOnly = true): int
    {
        $where = "role = 'admin'";
        if ($activeOnly) {
            $where .= ' AND is_active = 1';
        }

        return self::count($where);
    }

    public static function emailExists(string $email, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) AS total FROM users WHERE email = :email';
        $params = ['email' => mb_strtolower(trim($email))];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }

        $row = Database::fetch($sql, $params);

        return (int) ($row['total'] ?? 0) > 0;
    }
}
