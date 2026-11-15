<?php

declare(strict_types=1);

use App\Services\Totp;

/**
 * Vector oficial de la RFC 6238 (SHA-1, secreto ASCII "12345678901234567890").
 */
$secret = Totp::base32Encode('12345678901234567890');

return [
    'Totp: vectores RFC 6238 con 8 dígitos' => static function ($t) use ($secret): void {
        $vectors = [
            59 => '94287082',
            1111111109 => '07081804',
            1111111111 => '14050471',
            1234567890 => '89005924',
            2000000000 => '69279037',
            20000000000 => '65353130',
        ];

        foreach ($vectors as $timestamp => $expected) {
            $t->same($expected, Totp::codeAt($secret, $timestamp, 8), 'Vector en t=' . $timestamp);
        }
    },

    'Totp: los mismos vectores recortados a 6 dígitos' => static function ($t) use ($secret): void {
        $t->same('287082', Totp::codeAt($secret, 59));
        $t->same('081804', Totp::codeAt($secret, 1111111109));
        $t->same('050471', Totp::codeAt($secret, 1111111111));
        $t->same('005924', Totp::codeAt($secret, 1234567890));
        $t->same('279037', Totp::codeAt($secret, 2000000000));
        $t->same('353130', Totp::codeAt($secret, 20000000000));
    },

    'Totp: verifica con margen de reloj y rechaza códigos ajenos' => static function ($t) use ($secret): void {
        $code = Totp::codeAt($secret, 1700000000);

        $t->true(Totp::verify($secret, $code, 1, 1700000000));
        $t->true(Totp::verify($secret, $code, 1, 1700000015));
        $t->true(Totp::verify($secret, ' ' . $code . ' ', 1, 1700000000));
        $t->false(Totp::verify($secret, $code, 1, 1700000100));
        $t->false(Totp::verify($secret, '000000', 1, 1700000000));
        $t->false(Totp::verify($secret, '12345', 1, 1700000000));
        $t->false(Totp::verify($secret, 'abcdef', 1, 1700000000));
    },

    'Totp: base32 ida y vuelta y secreto generado' => static function ($t): void {
        $binary = '12345678901234567890';
        $encoded = Totp::base32Encode($binary);

        $t->same($binary, Totp::base32Decode($encoded));
        $t->same(strlen($encoded), strlen(Totp::generateSecret()));
        $t->true((bool) preg_match('/^[A-Z2-7]+$/', Totp::generateSecret()));
    },

    'Totp: URI de aprovisionamiento para autenticadores' => static function ($t) use ($secret): void {
        $uri = Totp::provisioningUri($secret, 'admin@example.com', 'JM Burguers');

        $t->contains('otpauth://totp/', $uri);
        $t->contains('JM%20Burguers', $uri);
        $t->contains('admin%40example.com', $uri);
        $t->contains('secret=' . $secret, $uri);
        $t->contains('digits=6', $uri);
        $t->contains('period=30', $uri);
    },
];
