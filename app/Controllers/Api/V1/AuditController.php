<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\Request;
use App\Core\Response;
use App\Models\AuditLog;

final class AuditController
{
    public function index(Request $request, array $params = []): Response
    {
        $page = (int) $request->queryParam('page', 1);
        $perPage = (int) $request->queryParam('per_page', 30);
        $result = AuditLog::paginate($page, $perPage);

        return Response::success($result['items'], $result['meta']);
    }
}
