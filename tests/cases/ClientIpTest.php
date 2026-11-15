<?php

declare(strict_types=1);

use App\Core\Config;

/**
 * Ejecuta una comprobación aislando $_SERVER y la lista de proxies de confianza.
 */
$withEnvironment = static function (array $server, array $proxies, callable $assert): void {
    $originalServer = $_SERVER;
    $originalProxies = Config::get('app.trusted_proxies', []);

    foreach (['REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR', 'HTTP_CF_CONNECTING_IP'] as $key) {
        unset($_SERVER[$key]);
    }

    $_SERVER = array_merge($_SERVER, $server);
    Config::set('app.trusted_proxies', $proxies);

    try {
        $assert();
    } finally {
        $_SERVER = $originalServer;
        Config::set('app.trusted_proxies', $originalProxies);
    }
};

return [
    'client_ip: sin proxies de confianza ignora X-Forwarded-For' => static function ($t) use ($withEnvironment): void {
        $withEnvironment(
            ['REMOTE_ADDR' => '203.0.113.10', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4'],
            [],
            static function () use ($t): void {
                $t->same('203.0.113.10', client_ip());
            },
        );
    },

    'client_ip: sin proxies de confianza ignora CF-Connecting-IP' => static function ($t) use ($withEnvironment): void {
        $withEnvironment(
            ['REMOTE_ADDR' => '203.0.113.10', 'HTTP_CF_CONNECTING_IP' => '1.2.3.4'],
            [],
            static function () use ($t): void {
                $t->same('203.0.113.10', client_ip());
            },
        );
    },

    'client_ip: proxy exacto de confianza usa X-Forwarded-For' => static function ($t) use ($withEnvironment): void {
        $withEnvironment(
            ['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7'],
            ['10.0.0.2'],
            static function () use ($t): void {
                $t->same('203.0.113.7', client_ip());
            },
        );
    },

    'client_ip: proxy dentro de un rango CIDR de confianza' => static function ($t) use ($withEnvironment): void {
        $withEnvironment(
            ['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7'],
            ['10.1.0.0/16'],
            static function () use ($t): void {
                $t->same('203.0.113.7', client_ip());
            },
        );
    },

    'client_ip: cadena X-Forwarded-For falsificada se resuelve de derecha a izquierda' => static function ($t) use ($withEnvironment): void {
        $withEnvironment(
            ['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 203.0.113.7'],
            ['10.0.0.2'],
            static function () use ($t): void {
                $t->same('203.0.113.7', client_ip());
            },
        );
    },

    'client_ip: proxy no confiable no puede inyectar una IP' => static function ($t) use ($withEnvironment): void {
        $withEnvironment(
            ['REMOTE_ADDR' => '198.51.100.9', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7'],
            ['10.0.0.2'],
            static function () use ($t): void {
                $t->same('198.51.100.9', client_ip());
            },
        );
    },

    'client_ip: REMOTE_ADDR inválida cae a 0.0.0.0' => static function ($t) use ($withEnvironment): void {
        $withEnvironment(
            ['REMOTE_ADDR' => 'no-es-una-ip'],
            [],
            static function () use ($t): void {
                $t->same('0.0.0.0', client_ip());
            },
        );
    },

    'ip_in_range: IP exacta, IPv4 CIDR e IPv6 CIDR' => static function ($t): void {
        $t->true(ip_in_range('203.0.113.7', '203.0.113.7'));
        $t->false(ip_in_range('203.0.113.8', '203.0.113.7'));
        $t->true(ip_in_range('10.1.2.3', '10.1.0.0/16'));
        $t->false(ip_in_range('10.2.2.3', '10.1.0.0/16'));
        $t->true(ip_in_range('2001:db8::1', '2001:db8::/32'));
        $t->false(ip_in_range('2001:db9::1', '2001:db8::/32'));
        $t->false(ip_in_range('10.0.0.1', '10.0.0.0/99'));
    },
];
