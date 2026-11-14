<?php

declare(strict_types=1);

/**
 * Configuración central. Lee variables desde .env (sin Composer) y variables
 * de entorno reales. Las credenciales nunca deben quedar dentro de public/.
 */

$root = dirname(__DIR__);
$envFile = $root . '/.env';
$env = [];

if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        $env[$key] = $value;
    }
}

$get = static function (string $key, string $default = '') use ($env): string {
    if (array_key_exists($key, $env)) {
        return $env[$key];
    }

    $value = getenv($key);

    return $value === false ? $default : $value;
};

$bool = static fn (string $value): bool => filter_var($value, FILTER_VALIDATE_BOOLEAN);
$basePath = trim($get('APP_BASE_PATH', ''), '/');

return [
    'app' => [
        'name' => $get('APP_NAME', 'AppMenu'),
        'env' => $get('APP_ENV', 'local'),
        'debug' => $bool($get('APP_DEBUG', 'true')),
        'url' => rtrim($get('APP_URL', ''), '/'),
        'base_path' => $basePath === '' ? '' : '/' . $basePath,
        'timezone' => $get('APP_TIMEZONE', 'America/Caracas'),
        'session_lifetime' => (int) $get('SESSION_LIFETIME', '7200'),
        'upload_max_mb' => max(1, (int) $get('UPLOAD_MAX_MB', '5')),
    ],
    'db' => [
        'host' => $get('DB_HOST', '127.0.0.1'),
        'port' => (int) $get('DB_PORT', '3306'),
        'database' => $get('DB_DATABASE', 'appmenu'),
        'username' => $get('DB_USERNAME', 'root'),
        'password' => $get('DB_PASSWORD', ''),
        'charset' => 'utf8mb4',
    ],
    'paths' => [
        'root' => $root,
        'app' => $root . '/app',
        'public' => $root . '/public',
        'uploads' => $root . '/public/uploads',
        'storage' => $root . '/storage',
        'logs' => $root . '/storage/logs',
    ],
    'seed' => [
        'admin_email' => $get('DEFAULT_ADMIN_EMAIL', 'admin@restaurante.local'),
        'admin_password' => $get('DEFAULT_ADMIN_PASSWORD', 'Admin123!'),
    ],
];
