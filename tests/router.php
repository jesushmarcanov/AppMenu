<?php

declare(strict_types=1);

/**
 * Router para el servidor embebido de PHP (sin Apache):
 *   php -S 127.0.0.1:8099 -t public tests/router.php
 *
 * No se usa en producción; imita las reglas de public/.htaccess.
 */

$uri = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$public = dirname(__DIR__) . '/public';
$file = realpath($public . $uri);

if ($uri !== '/' && $file !== false && is_file($file) && str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', realpath($public) ?: $public))) {
    return false;
}

if (str_starts_with($uri, '/api/v1')) {
    require $public . '/api/index.php';

    return true;
}

if ($uri === '/' || $uri === '/index.html') {
    $index = $public . '/index.html';
    if (is_file($index)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($index);

        return true;
    }
}

if (str_starts_with($uri, '/admin')) {
    $admin = $public . '/admin/index.html';
    if (is_file($admin)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($admin);

        return true;
    }
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'No encontrado';
return true;
