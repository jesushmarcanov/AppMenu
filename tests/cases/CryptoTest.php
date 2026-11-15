<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Crypto;
use App\Services\TwoFactor;

/**
 * Ejecuta una comprobación con una APP_KEY temporal y restaura la configuración.
 */
$withKey = static function (string $key, callable $assert): void {
    $original = Config::get('app.key', '');
    Config::set('app.key', $key);

    try {
        $assert();
    } finally {
        Config::set('app.key', $original);
    }
};

return [
    'Crypto: cifra y descifra sin exponer el secreto' => static function ($t) use ($withKey): void {
        $withKey('base64:' . base64_encode(random_bytes(32)), static function () use ($t): void {
            $secret = 'JBSWY3DPEHPK3PXP';
            $encrypted = Crypto::encrypt($secret);

            $t->true(str_starts_with($encrypted, 'v1:'));
            $t->false(str_contains($encrypted, $secret));
            $t->same($secret, Crypto::decrypt($encrypted));
        });
    },

    'Crypto: detecta manipulación y entradas inválidas' => static function ($t) use ($withKey): void {
        $withKey('clave-de-prueba-suficientemente-larga', static function () use ($t): void {
            $encrypted = Crypto::encrypt('secreto');
            $tampered = substr($encrypted, 0, -4) . 'AAAA';

            $t->null(Crypto::decrypt($tampered));
            $t->null(Crypto::decrypt('v1:no-es-base64'));
            $t->null(Crypto::decrypt(null));
            $t->null(Crypto::decrypt('texto-plano'));
        });
    },

    'Crypto: sin APP_KEY no está disponible' => static function ($t) use ($withKey): void {
        $withKey('', static function () use ($t): void {
            $t->false(Crypto::isConfigured());
            $t->null(Crypto::decrypt('v1:AAAA'));
        });
    },

    'TwoFactor: cifra el secreto y verifica el código TOTP' => static function ($t) use ($withKey): void {
        $withKey('base64:' . base64_encode(random_bytes(32)), static function () use ($t): void {
            $secret = TwoFactor::generateSecret();
            $encrypted = TwoFactor::encryptSecret($secret);
            $code = \App\Services\Totp::codeAt($secret);

            $t->true(TwoFactor::verifyCode($encrypted, $code));
            $t->false(TwoFactor::verifyCode($encrypted, '000000'));
            $t->false(TwoFactor::verifyCode(null, $code));
        });
    },

    'TwoFactor: códigos de recuperación de un solo uso' => static function ($t): void {
        $codes = TwoFactor::generateRecoveryCodes();
        $json = TwoFactor::hashRecoveryCodes($codes);

        $t->same(8, count($codes));
        $t->same(8, count(TwoFactor::decodeRecoveryCodes($json)));
        $t->true((bool) preg_match('/^[A-F0-9]{5}-[A-F0-9]{5}$/', $codes[0]));

        $index = TwoFactor::verifyRecoveryCode($json, strtolower($codes[3]));
        $t->same(3, $index);
        $t->same(3, TwoFactor::verifyRecoveryCode($json, str_replace('-', ' ', $codes[3])));
        $t->null(TwoFactor::verifyRecoveryCode($json, 'AAAAA-AAAAA'));
        $t->null(TwoFactor::verifyRecoveryCode($json, ''));

        $hashes = TwoFactor::decodeRecoveryCodes($json);
        unset($hashes[$index]);
        $remaining = json_encode(array_values($hashes));

        $t->same(7, count(TwoFactor::decodeRecoveryCodes($remaining)));
        $t->null(TwoFactor::verifyRecoveryCode($remaining, $codes[3]));
    },
];
