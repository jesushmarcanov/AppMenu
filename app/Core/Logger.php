<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

final class Logger
{
    /** @param array<string, mixed> $context */
    public static function write(string $level, string $message, array $context = []): void
    {
        $directory = (string) Config::get('paths.logs', sys_get_temp_dir());
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $entry = [
            'time' => date('c'),
            'level' => $level,
            'request_id' => function_exists('request_id') ? request_id() : null,
            'message' => $message,
        ];

        if ($context !== []) {
            $entry['context'] = $context;
        }

        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            $line = $message;
        }

        $file = $directory . '/app-' . date('Y-m-d') . '.log';
        if (@file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            error_log('[' . $level . '] ' . $message);
        }
    }

    /** @param array<string, mixed> $context */
    public static function error(string|Throwable $message, array $context = []): void
    {
        if ($message instanceof Throwable) {
            $context = array_merge($context, [
                'exception' => $message::class,
                'file' => $message->getFile(),
                'line' => $message->getLine(),
            ]);
            $message = $message->getMessage();
        }

        self::write('error', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }
}
