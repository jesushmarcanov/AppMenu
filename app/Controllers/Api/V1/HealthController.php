<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

final class HealthController
{
    public function index(Request $request, array $params = []): Response
    {
        $database = 'ok';
        try {
            Database::fetch('SELECT 1 AS ok');
        } catch (\Throwable $e) {
            $database = 'error';
        }

        return Response::success([
            'status' => $database === 'ok' ? 'ok' : 'degraded',
            'database' => $database,
            'php' => PHP_VERSION,
            'time' => now('c'),
        ]);
    }
}
