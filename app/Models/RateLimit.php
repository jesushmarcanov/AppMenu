<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Exceptions\ApiException;

final class RateLimit
{
    public static function hit(string $key, int $maxAttempts, int $windowSeconds): void
    {
        $expires = date('Y-m-d H:i:s', time() + $windowSeconds);

        Database::execute(
            'INSERT INTO rate_limits (rate_key, hits, expires_at)
             VALUES (:rate_key, 1, :expires_at)
             ON DUPLICATE KEY UPDATE
                hits = IF(expires_at < NOW(), 1, hits + 1),
                expires_at = IF(expires_at < NOW(), VALUES(expires_at), expires_at)',
            ['rate_key' => $key, 'expires_at' => $expires],
        );

        $row = Database::fetch('SELECT hits FROM rate_limits WHERE rate_key = :rate_key', ['rate_key' => $key]);
        $hits = (int) ($row['hits'] ?? 0);

        if ($hits > $maxAttempts) {
            throw new ApiException(
                'RATE_LIMITED',
                'Demasiados intentos. Espera unos minutos antes de volver a intentarlo.',
                429,
            );
        }
    }

    public static function clear(string $key): void
    {
        Database::delete('rate_limits', 'rate_key = :rate_key', ['rate_key' => $key]);
    }
}
