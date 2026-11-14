<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;
use App\Exceptions\ApiException;

final class Category extends Model
{
    protected static string $table = 'categories';

    /** @return array<int, array<string, mixed>> */
    public static function allAdmin(): array
    {
        $rows = Database::fetchAll(
            'SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS product_count
             FROM categories c
             ORDER BY c.sort_order ASC, c.id ASC',
        );

        return self::attachTranslations($rows, 'es');
    }

    /** @return array<string, mixed>|null */
    public static function findAdmin(int $id): ?array
    {
        $row = Database::fetch(
            'SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS product_count
             FROM categories c WHERE c.id = :id LIMIT 1',
            ['id' => $id],
        );

        if (!$row) {
            return null;
        }

        $rows = self::attachTranslations([$row], 'es');

        return $rows[0] ?? null;
    }

    /** @return array<int, array<string, mixed>> */
    public static function activeWithTranslations(string $locale): array
    {
        $rows = Database::fetchAll(
            'SELECT * FROM categories WHERE is_active = 1 ORDER BY sort_order ASC, id ASC',
        );

        return self::attachTranslations($rows, $locale);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function createCategory(array $data): int
    {
        $translations = is_array($data['translations'] ?? null) ? $data['translations'] : [];
        $baseName = $translations['es']['name'] ?? $translations['en']['name'] ?? 'Categoría';
        $slug = self::uniqueSlug((string) ($data['slug'] ?? $baseName));

        $id = self::create([
            'slug' => $slug,
            'sort_order' => (int) ($data['sort_order'] ?? self::nextSortOrder()),
            'is_active' => (int) ($data['is_active'] ?? 1),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        self::syncTranslations($id, $translations);

        return $id;
    }

    /** @param array<string, mixed> $data */
    public static function updateCategory(int $id, array $data): void
    {
        $current = self::find($id);
        if (!$current) {
            throw new ApiException('NOT_FOUND', 'La categoría no existe.', 404);
        }

        $translations = is_array($data['translations'] ?? null) ? $data['translations'] : [];
        $baseName = $translations['es']['name'] ?? $translations['en']['name'] ?? (string) $current['slug'];
        $slug = array_key_exists('slug', $data) && trim((string) $data['slug']) !== ''
            ? self::uniqueSlug((string) $data['slug'], $id)
            : (string) $current['slug'];

        if ($slug !== $current['slug'] && trim((string) ($data['slug'] ?? '')) === '') {
            $slug = self::uniqueSlug($baseName, $id);
        }

        $payload = [
            'slug' => $slug,
            'updated_at' => now(),
        ];

        if (array_key_exists('sort_order', $data)) {
            $payload['sort_order'] = (int) $data['sort_order'];
        }
        if (array_key_exists('is_active', $data)) {
            $payload['is_active'] = (int) (bool) $data['is_active'];
        }

        self::update($id, $payload);

        if ($translations !== []) {
            self::syncTranslations($id, $translations);
        }
    }

    public static function deleteCategory(int $id): void
    {
        if (self::find($id) === null) {
            throw new ApiException('NOT_FOUND', 'La categoría no existe.', 404);
        }

        $products = (int) (Database::fetch(
            'SELECT COUNT(*) AS total FROM products WHERE category_id = :id',
            ['id' => $id],
        )['total'] ?? 0);

        if ($products > 0) {
            throw new ApiException(
                'CATEGORY_HAS_PRODUCTS',
                'No puedes eliminar una categoría con productos. Reasigna o elimina sus productos primero.',
                409,
            );
        }

        self::delete($id);
    }

    /** @param array<int, int> $ids */
    public static function reorder(array $ids): void
    {
        Database::transaction(static function () use ($ids): void {
            foreach (array_values($ids) as $index => $id) {
                self::update((int) $id, [
                    'sort_order' => $index + 1,
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public static function nextSortOrder(): int
    {
        $row = Database::fetch('SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_order FROM categories');

        return (int) ($row['next_order'] ?? 1);
    }

    public static function uniqueSlug(string $base, ?int $exceptId = null): string
    {
        $base = slugify($base);
        $slug = $base;
        $suffix = 2;

        while (true) {
            $sql = 'SELECT COUNT(*) AS total FROM categories WHERE slug = :slug';
            $params = ['slug' => $slug];
            if ($exceptId !== null) {
                $sql .= ' AND id <> :id';
                $params['id'] = $exceptId;
            }

            $row = Database::fetch($sql, $params);
            if ((int) ($row['total'] ?? 0) === 0) {
                return $slug;
            }

            $slug = $base . '-' . $suffix;
            $suffix++;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function attachTranslations(array $rows, string $locale): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $translations = self::translationsFor($ids);

        foreach ($rows as &$row) {
            $id = (int) $row['id'];
            $row['translations'] = $translations[$id] ?? [];
            $row['name'] = $row['translations'][$locale]['name']
                ?? $row['translations']['es']['name']
                ?? $row['slug'];
            $row['description'] = $row['translations'][$locale]['description']
                ?? $row['translations']['es']['description']
                ?? '';
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, array<string, array<string, string>>>
     */
    public static function translationsFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $rows = Database::fetchAll(
            'SELECT category_id, locale, name, description FROM category_translations WHERE category_id IN (' . $placeholders . ')',
            $ids,
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['category_id']][(string) $row['locale']] = [
                'name' => (string) $row['name'],
                'description' => (string) ($row['description'] ?? ''),
            ];
        }

        return $result;
    }

    /** @param array<string, array<string, string>> $translations */
    private static function syncTranslations(int $id, array $translations): void
    {
        foreach (['es', 'en'] as $locale) {
            $entry = $translations[$locale] ?? null;
            $name = is_array($entry) ? trim((string) ($entry['name'] ?? '')) : '';

            if ($name === '') {
                Database::delete(
                    'category_translations',
                    'category_id = :category_id AND locale = :locale',
                    ['category_id' => $id, 'locale' => $locale],
                );
                continue;
            }

            Database::execute(
                'INSERT INTO category_translations (category_id, locale, name, description)
                 VALUES (:category_id, :locale, :name, :description)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description)',
                [
                    'category_id' => $id,
                    'locale' => $locale,
                    'name' => $name,
                    'description' => trim((string) ($entry['description'] ?? '')),
                ],
            );
        }
    }
}
