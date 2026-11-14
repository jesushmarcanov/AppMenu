<?php

declare(strict_types=1);

namespace App\Core;

abstract class Model
{
    protected static string $table = '';

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::fetch(
            sprintf('SELECT * FROM %s WHERE id = :id LIMIT 1', Database::quoteIdentifier(static::$table)),
            ['id' => $id],
        );
    }

    /** @return array<int, array<string, mixed>> */
    public static function all(string $orderBy = 'id ASC'): array
    {
        return Database::fetchAll(sprintf(
            'SELECT * FROM %s ORDER BY %s',
            Database::quoteIdentifier(static::$table),
            $orderBy,
        ));
    }

    /** @param array<string, mixed> $data */
    public static function create(array $data): int
    {
        return Database::insert(static::$table, $data);
    }

    /** @param array<string, mixed> $data */
    public static function update(int $id, array $data): int
    {
        return Database::update(static::$table, $data, 'id = :id', ['id' => $id]);
    }

    public static function delete(int $id): int
    {
        return Database::delete(static::$table, 'id = :id', ['id' => $id]);
    }

    public static function count(string $where = '1', array $params = []): int
    {
        $row = Database::fetch(
            sprintf('SELECT COUNT(*) AS total FROM %s WHERE %s', Database::quoteIdentifier(static::$table), $where),
            $params,
        );

        return (int) ($row['total'] ?? 0);
    }
}
