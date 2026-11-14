<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class AuditLog extends Model
{
    protected static string $table = 'audit_log';

    /** @param array<string, mixed> $meta */
    public static function record(?int $userId, string $action, ?string $entity = null, ?int $entityId = null, array $meta = []): void
    {
        self::create([
            'user_id' => $userId,
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'meta' => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => client_ip(),
            'created_at' => now(),
        ]);
    }

    /** @return array{items: array<int, array<string, mixed>>, meta: array<string, int>} */
    public static function paginate(int $page = 1, int $perPage = 30): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $countRow = Database::fetch('SELECT COUNT(*) AS total FROM audit_log');
        $total = (int) ($countRow['total'] ?? 0);

        $items = Database::fetchAll(
            'SELECT a.*, u.name AS user_name, u.email AS user_email
             FROM audit_log a
             LEFT JOIN users u ON u.id = a.user_id
             ORDER BY a.id DESC
             LIMIT ' . $perPage . ' OFFSET ' . $offset,
        );

        foreach ($items as &$item) {
            if (is_string($item['meta']) && $item['meta'] !== '') {
                $item['meta'] = json_decode($item['meta'], true);
            }
        }
        unset($item);

        return [
            'items' => $items,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => (int) ceil($total / $perPage),
            ],
        ];
    }
}
