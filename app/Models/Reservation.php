<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;
use App\Exceptions\ApiException;

final class Reservation extends Model
{
    protected static string $table = 'reservations';

    /** @param array<string, mixed> $data */
    public static function createReservation(array $data): int
    {
        return self::create([
            'name' => trim((string) $data['name']),
            'phone' => trim((string) $data['phone']),
            'email' => isset($data['email']) && trim((string) $data['email']) !== ''
                ? mb_strtolower(trim((string) $data['email']))
                : null,
            'reservation_date' => (string) $data['reservation_date'],
            'reservation_time' => (string) $data['reservation_time'],
            'party_size' => (int) $data['party_size'],
            'notes' => isset($data['notes']) ? trim((string) $data['notes']) : null,
            'admin_notes' => null,
            'status' => 'pending',
            'locale' => (string) ($data['locale'] ?? 'es'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public static function paginate(array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

        $where = ['1 = 1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'reservation_date >= :date_from';
            $params['date_from'] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'reservation_date <= :date_to';
            $params['date_to'] = (string) $filters['date_to'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(name LIKE :search OR phone LIKE :search OR email LIKE :search)';
            $params['search'] = '%' . trim((string) $filters['q']) . '%';
        }

        $whereSql = implode(' AND ', $where);

        $countRow = Database::fetch('SELECT COUNT(*) AS total FROM reservations WHERE ' . $whereSql, $params);
        $total = (int) ($countRow['total'] ?? 0);

        $items = Database::fetchAll(
            'SELECT * FROM reservations WHERE ' . $whereSql . '
             ORDER BY reservation_date ASC, reservation_time ASC, id ASC
             LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params,
        );

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

    /** @return array<string, mixed>|null */
    public static function findReservation(int $id): ?array
    {
        return self::find($id);
    }

    /** @param array<string, mixed> $data */
    public static function updateReservation(int $id, array $data): void
    {
        if (self::find($id) === null) {
            throw new ApiException('NOT_FOUND', 'La reservación no existe.', 404);
        }

        $payload = ['updated_at' => now()];
        if (array_key_exists('status', $data)) {
            $payload['status'] = (string) $data['status'];
        }
        if (array_key_exists('admin_notes', $data)) {
            $payload['admin_notes'] = trim((string) $data['admin_notes']);
        }

        self::update($id, $payload);
    }

    public static function deleteReservation(int $id): void
    {
        if (self::find($id) === null) {
            throw new ApiException('NOT_FOUND', 'La reservación no existe.', 404);
        }

        self::delete($id);
    }

    /**
     * Reservas activas de una fecha, con las columnas necesarias para calcular
     * el aforo. `FOR UPDATE` bloquea las filas dentro de una transacción.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function seatsOnDate(string $date, bool $forUpdate = false): array
    {
        $sql = "SELECT reservation_time, party_size FROM reservations
                WHERE reservation_date = :reservation_date
                  AND status IN ('pending', 'confirmed')
                ORDER BY reservation_time ASC";

        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        return Database::fetchAll($sql, ['reservation_date' => $date]);
    }

    public static function todayCount(): int
    {
        $today = date('Y-m-d');

        return self::count('reservation_date = :today', ['today' => $today]);
    }

    public static function pendingCount(): int
    {
        return self::count("status = 'pending'");
    }

    /** @return array<int, array<string, mixed>> */
    public static function upcoming(int $limit = 5): array
    {
        $limit = max(1, min(20, $limit));

        return Database::fetchAll(
            "SELECT * FROM reservations
             WHERE reservation_date >= :today AND status IN ('pending', 'confirmed')
             ORDER BY reservation_date ASC, reservation_time ASC
             LIMIT " . $limit,
            ['today' => date('Y-m-d')],
        );
    }
}
