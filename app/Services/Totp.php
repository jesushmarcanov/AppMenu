<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * TOTP (RFC 6238) y base32 (RFC 4648) sin dependencias externas.
 * Compatible con Google Authenticator, Authy, 1Password y similares.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    /**
     * Código TOTP para un instante concreto.
     */
    public static function codeAt(string $secret, ?int $timestamp = null, int $digits = 6, int $period = 30): string
    {
        $timestamp ??= time();
        $counter = intdiv($timestamp, $period);
        $key = self::base32Decode($secret);

        $hash = hash_hmac('sha1', pack('J', $counter), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        $modulo = 10 ** $digits;

        return str_pad((string) ($binary % $modulo), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Verifica el código permitiendo un margen de reloj de ±$window pasos.
     */
    public static function verify(
        string $secret,
        string $code,
        int $window = 1,
        ?int $timestamp = null,
        int $digits = 6,
        int $period = 30,
    ): bool {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== $digits) {
            return false;
        }

        try {
            $timestamp ??= time();
            for ($offset = -$window; $offset <= $window; $offset++) {
                $candidate = self::codeAt($secret, $timestamp + ($offset * $period), $digits, $period);
                if (hash_equals($candidate, $code)) {
                    return true;
                }
            }
        } catch (InvalidArgumentException) {
            return false;
        }

        return false;
    }

    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);
        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30,
        ], '', '&', PHP_QUERY_RFC3986);

        return 'otpauth://totp/' . $label . '?' . $query;
    }

    public static function base32Encode(string $data): string
    {
        if ($data === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($data) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $encoded .= self::ALPHABET[bindec($chunk)];
        }

        return $encoded;
    }

    public static function base32Decode(string $encoded): string
    {
        $encoded = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $encoded) ?? '');
        if ($encoded === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($encoded) as $char) {
            $value = strpos(self::ALPHABET, $char);
            if ($value === false) {
                throw new InvalidArgumentException('Secreto base32 no válido.');
            }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {
            $decoded .= chr(bindec(substr($bits, $i, 8)));
        }

        return $decoded;
    }
}
