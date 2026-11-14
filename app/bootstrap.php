<?php

declare(strict_types=1);

/**
 * Arranque compartido por la API y los scripts de consola.
 * No inicia sesión ni despacha peticiones: eso lo hace App::run().
 */

require_once __DIR__ . '/Support/helpers.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

$config = require dirname(__DIR__) . '/config/config.php';
\App\Core\Config::load($config);
