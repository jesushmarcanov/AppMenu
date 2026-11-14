<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;
use App\Exceptions\ApiException;
use App\Services\ImageService;

final class Product extends Model
{
    protected static string $table = 'products';

    /** @return array<int, array<string, mixed>> */
    public static function menu(string $locale): array
    {
        return Database::fetchAll(
            'SELECT p.*, c.slug AS category_slug,
                    COALESCE(pt.name, ptes.name) AS name,
                    COALESCE(pt.description, ptes.description) AS description,
                    COALESCE(pt.unit_label, ptes.unit_label, p.unit) AS unit_label,
                    COALESCE(ct.name, ctes.name) AS category_name
             FROM products p
             INNER JOIN categories c ON c.id = p.category_id AND c.is_active = 1
             LEFT JOIN product_translations pt ON pt.product_id = p.id AND pt.locale = :locale_product
             LEFT JOIN product_translations ptes ON ptes.product_id = p.id AND ptes.locale = :fallback_product
             LEFT JOIN category_translations ct ON ct.category_id = c.id AND ct.locale = :locale_category
             LEFT JOIN category_translations ctes ON ctes.category_id = c.id AND ctes.locale = :fallback_category
             WHERE p.is_active = 1
             ORDER BY c.sort_order ASC, p.sort_order ASC, p.id ASC',
            [
                'locale_product' => $locale,
                'fallback_product' => 'es',
                'locale_category' => $locale,
                'fallback_category' => 'es',
            ],
        );
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public static function adminList(array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

        $where = ['1 = 1'];
        $params = [];

        if (!empty($filters['category_id'])) {
            $where[] = 'p.category_id = :category_id';
            $params['category_id'] = (int) $filters['category_id'];
        }

        if (!empty($filters['q'])) {
            $where[] = '(pte.name LIKE :search OR p.slug LIKE :search)';
            $params['search'] = '%' . trim((string) $filters['q']) . '%';
        }

        $status = (string) ($filters['status'] ?? '');
        if ($status === 'sold_out') {
            $where[] = 'p.is_available = 0';
        } elseif ($status === 'featured') {
            $where[] = 'p.is_featured = 1';
        } elseif ($status === 'available') {
            $where[] = 'p.is_available = 1';
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $where[] = 'p.is_active = :is_active';
            $params['is_active'] = (int) (bool) $filters['is_active'];
        }

        $whereSql = implode(' AND ', $where);

        $countRow = Database::fetch(
            'SELECT COUNT(*) AS total
             FROM products p
             LEFT JOIN product_translations pte ON pte.product_id = p.id AND pte.locale = :fallback
             WHERE ' . $whereSql,
            array_merge(['fallback' => 'es'], $params),
        );
        $total = (int) ($countRow['total'] ?? 0);

        $rows = Database::fetchAll(
            'SELECT p.*, c.slug AS category_slug,
                    COALESCE(ct.name, cte.name, c.slug) AS category_name
             FROM products p
             INNER JOIN categories c ON c.id = p.category_id
             LEFT JOIN category_translations ct ON ct.category_id = c.id AND ct.locale = :locale
             LEFT JOIN category_translations cte ON cte.category_id = c.id AND cte.locale = :fallback_category
             LEFT JOIN product_translations pte ON pte.product_id = p.id AND pte.locale = :fallback_product
             WHERE ' . $whereSql . '
             ORDER BY p.sort_order ASC, p.id DESC
             LIMIT ' . $perPage . ' OFFSET ' . $offset,
            array_merge([
                'locale' => 'es',
                'fallback_category' => 'es',
                'fallback_product' => 'es',
            ], $params),
        );

        $rows = self::attachTranslations($rows, 'es');

        return [
            'items' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function findAdmin(int $id): ?array
    {
        $row = Database::fetch(
            'SELECT p.*, c.slug AS category_slug,
                    COALESCE(ct.name, cte.name, c.slug) AS category_name
             FROM products p
             INNER JOIN categories c ON c.id = p.category_id
             LEFT JOIN category_translations ct ON ct.category_id = c.id AND ct.locale = :locale
             LEFT JOIN category_translations cte ON cte.category_id = c.id AND cte.locale = :fallback
             WHERE p.id = :id LIMIT 1',
            ['id' => $id, 'locale' => 'es', 'fallback' => 'es'],
        );

        if (!$row) {
            return null;
        }

        $rows = self::attachTranslations([$row], 'es');

        return $rows[0] ?? null;
    }

    /** @param array<string, mixed> $data */
    public static function createProduct(array $data): int
    {
        $translations = is_array($data['translations'] ?? null) ? $data['translations'] : [];
        $baseName = $translations['es']['name'] ?? $translations['en']['name'] ?? 'Producto';
        $slug = self::uniqueSlug((string) ($data['slug'] ?? $baseName));

        $id = self::create([
            'category_id' => (int) $data['category_id'],
            'slug' => $slug,
            'price' => decimal_string($data['price'] ?? 0),
            'unit' => trim((string) ($translations['es']['unit_label'] ?? $data['unit'] ?? '')),
            'is_available' => (int) ($data['is_available'] ?? 1),
            'is_featured' => (int) ($data['is_featured'] ?? 0),
            'is_active' => (int) ($data['is_active'] ?? 1),
            'sort_order' => (int) ($data['sort_order'] ?? self::nextSortOrder((int) $data['category_id'])),
            'emoji' => trim((string) ($data['emoji'] ?? '')),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        self::syncTranslations($id, $translations);

        return $id;
    }

    /** @param array<string, mixed> $data */
    public static function updateProduct(int $id, array $data): void
    {
        $current = self::find($id);
        if (!$current) {
            throw new ApiException('NOT_FOUND', 'El producto no existe.', 404);
        }

        $translations = is_array($data['translations'] ?? null) ? $data['translations'] : [];
        $baseName = $translations['es']['name'] ?? $translations['en']['name'] ?? (string) $current['slug'];

        $payload = ['updated_at' => now()];

        if (array_key_exists('category_id', $data)) {
            $payload['category_id'] = (int) $data['category_id'];
        }
        if (array_key_exists('price', $data)) {
            $payload['price'] = decimal_string($data['price']);
        }
        if (array_key_exists('is_available', $data)) {
            $payload['is_available'] = (int) (bool) $data['is_available'];
        }
        if (array_key_exists('is_featured', $data)) {
            $payload['is_featured'] = (int) (bool) $data['is_featured'];
        }
        if (array_key_exists('is_active', $data)) {
            $payload['is_active'] = (int) (bool) $data['is_active'];
        }
        if (array_key_exists('sort_order', $data)) {
            $payload['sort_order'] = (int) $data['sort_order'];
        }
        if (array_key_exists('emoji', $data)) {
            $payload['emoji'] = trim((string) $data['emoji']);
        }
        if (array_key_exists('slug', $data) && trim((string) $data['slug']) !== '') {
            $payload['slug'] = self::uniqueSlug((string) $data['slug'], $id);
        }
        if (isset($translations['es']['unit_label'])) {
            $payload['unit'] = trim((string) $translations['es']['unit_label']);
        }

        self::update($id, $payload);

        if ($translations !== []) {
            self::syncTranslations($id, $translations);
        }
    }

    public static function deleteProduct(int $id): void
    {
        $product = self::find($id);
        if (!$product) {
            throw new ApiException('NOT_FOUND', 'El producto no existe.', 404);
        }

        $imageService = new ImageService();
        $imageService->delete($product['image_path'] ?? null);
        $imageService->delete($product['image_thumb_path'] ?? null);

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

    public static function setAvailability(int $id, bool $available): void
    {
        self::update($id, [
            'is_available' => (int) $available,
            'updated_at' => now(),
        ]);
    }

    public static function setFeatured(int $id, bool $featured): void
    {
        self::update($id, [
            'is_featured' => (int) $featured,
            'updated_at' => now(),
        ]);
    }

    /** @param array{image_path: string, image_thumb_path: string} $paths */
    public static function setImage(int $id, array $paths): void
    {
        self::update($id, [
            'image_path' => $paths['image_path'],
            'image_thumb_path' => $paths['image_thumb_path'],
            'updated_at' => now(),
        ]);
    }

    public static function clearImage(int $id): void
    {
        self::update($id, [
            'image_path' => null,
            'image_thumb_path' => null,
            'updated_at' => now(),
        ]);
    }

    public static function nextSortOrder(int $categoryId): int
    {
        $row = Database::fetch(
            'SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_order FROM products WHERE category_id = :category_id',
            ['category_id' => $categoryId],
        );

        return (int) ($row['next_order'] ?? 1);
    }

    public static function uniqueSlug(string $base, ?int $exceptId = null): string
    {
        $base = slugify($base);
        $slug = $base;
        $suffix = 2;

        while (true) {
            $sql = 'SELECT COUNT(*) AS total FROM products WHERE slug = :slug';
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

    /** @return array<string, int> */
    public static function counts(): array
    {
        $row = Database::fetch(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(is_active = 1), 0) AS active,
                    COALESCE(SUM(is_active = 1 AND is_available = 0), 0) AS sold_out,
                    COALESCE(SUM(is_active = 1 AND is_featured = 1), 0) AS featured
             FROM products",
        );

        return [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active'] ?? 0),
            'sold_out' => (int) ($row['sold_out'] ?? 0),
            'featured' => (int) ($row['featured'] ?? 0),
        ];
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
            $row['unit_label'] = $row['translations'][$locale]['unit_label']
                ?? $row['translations']['es']['unit_label']
                ?? (string) ($row['unit'] ?? '');
            $row['image_url'] = asset_url($row['image_path'] ?? null);
            $row['image_thumb_url'] = asset_url($row['image_thumb_path'] ?? null);
            $row['price'] = decimal_string($row['price'] ?? 0);
            $row['is_available'] = (int) ($row['is_available'] ?? 1) === 1;
            $row['is_featured'] = (int) ($row['is_featured'] ?? 0) === 1;
            $row['is_active'] = (int) ($row['is_active'] ?? 1) === 1;
            $row['status'] = !$row['is_available']
                ? 'sold_out'
                : ($row['is_featured'] ? 'featured' : 'available');
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
            'SELECT product_id, locale, name, description, unit_label
             FROM product_translations WHERE product_id IN (' . $placeholders . ')',
            $ids,
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['product_id']][(string) $row['locale']] = [
                'name' => (string) $row['name'],
                'description' => (string) ($row['description'] ?? ''),
                'unit_label' => (string) ($row['unit_label'] ?? ''),
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
                    'product_translations',
                    'product_id = :product_id AND locale = :locale',
                    ['product_id' => $id, 'locale' => $locale],
                );
                continue;
            }

            Database::execute(
                'INSERT INTO product_translations (product_id, locale, name, description, unit_label)
                 VALUES (:product_id, :locale, :name, :description, :unit_label)
                 ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    description = VALUES(description),
                    unit_label = VALUES(unit_label)',
                [
                    'product_id' => $id,
                    'locale' => $locale,
                    'name' => $name,
                    'description' => trim((string) ($entry['description'] ?? '')),
                    'unit_label' => trim((string) ($entry['unit_label'] ?? '')),
                ],
            );
        }
    }
}
