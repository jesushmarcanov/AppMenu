<?php

declare(strict_types=1);

use App\Core\Config;

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}

if (!function_exists('app_url')) {
    function app_url(string $path = ''): string
    {
        $configured = trim((string) config('app.url', ''));

        if ($configured !== '') {
            return rtrim($configured, '/') . ($path === '' ? '' : '/' . ltrim($path, '/'));
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8080';
        $base = (string) config('app.base_path', '');

        return $scheme . '://' . $host . rtrim($base, '/') . ($path === '' ? '/' : '/' . ltrim($path, '/'));
    }
}

if (!function_exists('app_is_local_host')) {
    /**
     * Indica si un host solo es accesible desde el propio equipo.
     * Un QR con "localhost" no se puede abrir desde un teléfono.
     */
    function app_is_local_host(string $host): bool
    {
        $host = strtolower(trim($host));

        if ($host === '') {
            return true;
        }

        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');
            $host = $end === false ? $host : substr($host, 1, $end - 1);
        } elseif (substr_count($host, ':') === 1) {
            $host = (string) strstr($host, ':', true);
        }

        return $host === 'localhost'
            || $host === '::1'
            || $host === '0.0.0.0'
            || str_starts_with($host, '127.')
            || str_ends_with($host, '.localhost');
    }
}

if (!function_exists('local_network_ip')) {
    /**
     * Devuelve la IPv4 de la interfaz con salida a la red, sin enviar tráfico.
     * Así el QR apunta a una dirección que el teléfono puede alcanzar.
     */
    function local_network_ip(): ?string
    {
        static $resolved = false;
        static $ip = null;

        if ($resolved) {
            return $ip;
        }
        $resolved = true;

        $stream = @stream_socket_client('udp://8.8.8.8:53', $errno, $errstr, 1);
        if ($stream !== false) {
            $name = (string) stream_socket_get_name($stream, false);
            fclose($stream);
            $candidate = str_contains($name, ']:')
                ? substr($name, 1, (int) strpos($name, ']') - 1)
                : explode(':', $name)[0];

            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && !str_starts_with($candidate, '127.')) {
                return $ip = $candidate;
            }
        }

        $candidates = @gethostbynamel((string) gethostname());
        foreach ((array) $candidates as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && !str_starts_with($candidate, '127.')) {
                return $ip = $candidate;
            }
        }

        return null;
    }
}

if (!function_exists('app_public_url')) {
    /**
     * URL pensada para compartir (QR y enlaces públicos). Si la petición llega
     * por localhost/127.0.0.1, la sustituye por la IPv4 de la red local para
     * que el enlace funcione al escanearlo desde un teléfono.
     */
    function app_public_url(string $path = ''): string
    {
        $url = app_url($path);
        $parts = parse_url($url);

        if (!is_array($parts)) {
            return $url;
        }

        $host = (string) ($parts['host'] ?? '');
        if (!app_is_local_host($host)) {
            return $url;
        }

        $ip = local_network_ip();
        if ($ip === null) {
            return $url;
        }

        $scheme = (string) ($parts['scheme'] ?? 'http');
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $basePath = (string) ($parts['path'] ?? '/');
        if ($basePath === '') {
            $basePath = '/';
        }

        return $scheme . '://' . $ip . $port . $basePath;
    }
}

if (!function_exists('asset_url')) {
    function asset_url(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        return app_url(ltrim($path, '/'));
    }
}

if (!function_exists('public_path')) {
    function public_path(string $path = ''): string
    {
        $base = rtrim((string) config('paths.public'), '/');

        return $path === '' ? $base : $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        $base = rtrim((string) config('paths.storage'), '/');

        return $path === '' ? $base : $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('now')) {
    function now(string $format = 'Y-m-d H:i:s'): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone((string) config('app.timezone'))))->format($format);
    }
}

if (!function_exists('slugify')) {
    function slugify(string $text): string
    {
        $text = trim($text);
        if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if ($converted !== false) {
                $text = $converted;
            }
        }

        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
        $text = trim($text, '-');

        return $text !== '' ? $text : 'item';
    }
}

if (!function_exists('ip_in_range')) {
    /**
     * Comprueba si una IP coincide con una IP exacta o con un rango CIDR.
     */
    function ip_in_range(string $ip, string $range): bool
    {
        if (!str_contains($range, '/')) {
            return $ip === $range;
        }

        [$subnet, $bits] = explode('/', $range, 2);
        $bits = (int) $bits;

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $maxBits = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $wholeBytes = intdiv($bits, 8);
        if ($wholeBytes > 0 && substr($ipBin, 0, $wholeBytes) !== substr($subnetBin, 0, $wholeBytes)) {
            return false;
        }

        $remainingBits = $bits % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($ipBin[$wholeBytes]) & $mask) === (ord($subnetBin[$wholeBytes]) & $mask);
    }
}

if (!function_exists('trusted_proxies')) {
    /** @return array<int, string> */
    function trusted_proxies(): array
    {
        $configured = Config::get('app.trusted_proxies', []);

        return is_array($configured) ? array_values(array_filter($configured, 'is_string')) : [];
    }
}

if (!function_exists('client_ip')) {
    /**
     * Devuelve la IP del cliente sin confiar en cabeceras falsificables.
     * X-Forwarded-For y CF-Connecting-IP solo se leen cuando REMOTE_ADDR
     * pertenece a un proxy declarado en TRUSTED_PROXIES.
     */
    function client_ip(): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        $remote = is_string($remote) && filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';

        $trusted = trusted_proxies();
        $isTrusted = false;
        foreach ($trusted as $proxy) {
            if (ip_in_range($remote, $proxy)) {
                $isTrusted = true;
                break;
            }
        }

        if (!$isTrusted) {
            return $remote;
        }

        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR'] as $header) {
            $candidate = $_SERVER[$header] ?? null;
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            // De derecha a izquierda: el valor más cercano al proxy de
            // confianza es el único que no puede haber falsificado el cliente.
            foreach (array_reverse(explode(',', $candidate)) as $ip) {
                $ip = trim($ip);
                if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                    continue;
                }

                foreach ($trusted as $proxy) {
                    if (ip_in_range($ip, $proxy)) {
                        continue 2;
                    }
                }

                return $ip;
            }
        }

        return $remote;
    }
}

if (!function_exists('audit')) {
    function audit(string $action, ?string $entity = null, ?int $entityId = null, array $meta = []): void
    {
        try {
            \App\Models\AuditLog::record(\App\Core\Auth::id(), $action, $entity, $entityId, $meta);
        } catch (Throwable $e) {
            \App\Core\Logger::error('No se pudo registrar la auditoría: ' . $e->getMessage());
        }
    }
}

if (!function_exists('request_id')) {
    function request_id(): string
    {
        return \App\Core\App::requestId();
    }
}

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('decimal_string')) {
    function decimal_string(mixed $value, int $decimals = 2): string
    {
        if (!is_numeric($value)) {
            return number_format(0, $decimals, '.', '');
        }

        return number_format((float) $value, $decimals, '.', '');
    }
}
