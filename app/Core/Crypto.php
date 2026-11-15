<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Cifrado simétrico para secretos en reposo (por ejemplo, la clave TOTP).
 * Usa AES-256-GCM con una clave derivada de APP_KEY.
 */
final class Crypto
{
    private const PREFIX = 'v1:';
    private const CIPHER = 'aes-256-gcm';
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;

    public static function isConfigured(): bool
    {
        return self::key() !== null;
    }

    public static function encrypt(string $plaintext): string
    {
        $key = self::key();
        if ($key === null) {
            throw new RuntimeException('APP_KEY no está configurada.');
        }

        $iv = random_bytes(self::IV_BYTES);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_BYTES);

        if ($cipher === false) {
            throw new RuntimeException('No se pudo cifrar el secreto.');
        }

        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(?string $payload): ?string
    {
        if ($payload === null || !str_starts_with($payload, self::PREFIX)) {
            return null;
        }

        $key = self::key();
        if ($key === null) {
            return null;
        }

        $raw = base64_decode(substr($payload, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < self::IV_BYTES + self::TAG_BYTES + 1) {
            return null;
        }

        $iv = substr($raw, 0, self::IV_BYTES);
        $tag = substr($raw, self::IV_BYTES, self::TAG_BYTES);
        $cipher = substr($raw, self::IV_BYTES + self::TAG_BYTES);
        $plain = openssl_decrypt($cipher, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);

        return $plain === false ? null : $plain;
    }

    private static function key(): ?string
    {
        $configured = trim((string) Config::get('app.key', ''));
        if ($configured === '') {
            return null;
        }

        $material = $configured;
        if (str_starts_with($configured, 'base64:')) {
            $decoded = base64_decode(substr($configured, 7), true);
            if ($decoded === false || strlen($decoded) < 32) {
                return null;
            }
            $material = $decoded;
        }

        return hash('sha256', $material, true);
    }
}
