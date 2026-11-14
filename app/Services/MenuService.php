<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;

final class MenuService
{
    /** @return array<string, mixed> */
    public function publicMenu(string $locale): array
    {
        $settings = Setting::public($locale);
        $categories = Category::activeWithTranslations($locale);
        $products = Product::menu($locale);

        $showSoldOut = (bool) ($settings['show_sold_out'] ?? true);
        $bySlug = [];

        foreach ($categories as $category) {
            $bySlug[(string) $category['slug']] = [
                'id' => (int) $category['id'],
                'slug' => $category['slug'],
                'name' => $category['name'],
                'description' => $category['description'],
                'products' => [],
            ];
        }

        foreach ($products as $product) {
            if (!$showSoldOut && (int) $product['is_available'] !== 1) {
                continue;
            }

            $slug = (string) $product['category_slug'];
            if (!isset($bySlug[$slug])) {
                $bySlug[$slug] = [
                    'id' => (int) $product['category_id'],
                    'slug' => $slug,
                    'name' => $product['category_name'] ?? $slug,
                    'description' => '',
                    'products' => [],
                ];
            }

            $bySlug[$slug]['products'][] = $this->formatProduct($product);
        }

        $categories = array_values(array_filter(
            $bySlug,
            static fn (array $category): bool => $category['products'] !== [],
        ));

        $featured = [];
        foreach ($categories as $category) {
            foreach ($category['products'] as $product) {
                if ($product['is_featured'] && $product['is_available']) {
                    $featured[] = $product;
                }
            }
        }

        return [
            'settings' => $settings,
            'categories' => $categories,
            'featured' => array_slice($featured, 0, 6),
            'locales' => ['es', 'en'],
            'generated_at' => now('c'),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function publicCategories(string $locale): array
    {
        return Category::activeWithTranslations($locale);
    }

    /** @return array<int, array<string, mixed>> */
    public function publicProducts(string $locale, ?string $categorySlug = null): array
    {
        $products = Product::menu($locale);
        $result = [];

        foreach ($products as $product) {
            if ($categorySlug !== null && $categorySlug !== '' && $product['category_slug'] !== $categorySlug) {
                continue;
            }
            $result[] = $this->formatProduct($product);
        }

        return $result;
    }

    /** @param array<string, mixed> $product @return array<string, mixed> */
    private function formatProduct(array $product): array
    {
        $available = (int) ($product['is_available'] ?? 1) === 1;
        $featured = (int) ($product['is_featured'] ?? 0) === 1;

        return [
            'id' => (int) $product['id'],
            'slug' => $product['slug'],
            'category_id' => (int) $product['category_id'],
            'category_slug' => $product['category_slug'],
            'category_name' => $product['category_name'] ?? '',
            'name' => $product['name'],
            'description' => $product['description'] ?? '',
            'price' => decimal_string($product['price'] ?? 0),
            'unit' => $product['unit_label'] ?? ($product['unit'] ?? ''),
            'image_url' => asset_url($product['image_path'] ?? null),
            'image_thumb_url' => asset_url($product['image_thumb_path'] ?? null),
            'emoji' => (string) ($product['emoji'] ?? ''),
            'is_available' => $available,
            'is_featured' => $featured,
            'status' => !$available ? 'sold_out' : ($featured ? 'featured' : 'available'),
        ];
    }
}
