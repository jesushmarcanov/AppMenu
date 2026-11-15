<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;

/**
 * Fábrica de alto nivel para la verificación en dos pasos: secretos TOTP,
 * cifrado en reposo y códigos de recuperación de un solo uso.
 */
final class TwoFactor
{
    public static function generateSecret(): string
    {
        return Totp::generateSecret();
    }

    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        return Totp::provisioningUri($secret, $account, $issuer);
    }

    public static function encryptSecret(string $secret): string
    {
        return Crypto::encrypt($secret);
    }

    public static function verifyCode(?string $encryptedSecret, string $code): bool
    {
        $secret = Crypto::decrypt($encryptedSecret);
        if ($secret === null || $secret === '') {
            return false;
        }

        return Totp::verify($secret, $code);
    }

    /** @return array<int, string> */
    public static function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $raw = strtoupper(bin2hex(random_bytes(5)));
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5);
        }

        return $codes;
    }

    public static function normalizeRecoveryCode(string $code): string
    {
        return strtolower(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }

    /** @param array<int, string> $codes */
    public static function hashRecoveryCodes(array $codes): string
    {
        $hashes = array_map(
            static fn (string $code): string => password_hash(self::normalizeRecoveryCode($code), PASSWORD_DEFAULT),
            $codes,
        );

        return json_encode($hashes, JSON_THROW_ON_ERROR);
    }

    /** @return array<int, string> */
    public static function decodeRecoveryCodes(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /**
     * Devuelve el índice del código consumido o null si no coincide.
     */
    public static function verifyRecoveryCode(?string $json, string $code): ?int
    {
        $normalized = self::normalizeRecoveryCode($code);
        if ($normalized === '') {
            return null;
        }

        foreach (self::decodeRecoveryCodes($json) as $index => $hash) {
            if (password_verify($normalized, $hash)) {
                return $index;
            }
        }

        return null;
    }
}
