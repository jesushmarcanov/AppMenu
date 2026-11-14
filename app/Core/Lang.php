<?php

declare(strict_types=1);

namespace App\Core;

final class Lang
{
    /** @var array<int, string> */
    public const SUPPORTED = ['es', 'en'];

    public static function resolve(?string $requested = null): string
    {
        $default = (string) Config::get('app.default_lang', 'es');
        $candidate = strtolower(substr(trim((string) $requested), 0, 2));

        if (in_array($candidate, self::SUPPORTED, true)) {
            return $candidate;
        }

        return in_array($default, self::SUPPORTED, true) ? $default : 'es';
    }

    public static function fallback(): string
    {
        return 'es';
    }

    /** @return array<int, string> */
    public static function supported(): array
    {
        return self::SUPPORTED;
    }
}
