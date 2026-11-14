<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Exceptions\ApiException;
use App\Exceptions\ValidationException;
use App\Models\Category;

final class CategoryController
{
    public function index(Request $request, array $params = []): Response
    {
        return Response::success(Category::allAdmin());
    }

    public function show(Request $request, array $params = []): Response
    {
        $category = Category::findAdmin((int) ($params['id'] ?? 0));
        if (!$category) {
            throw new ApiException('NOT_FOUND', 'La categoría no existe.', 404);
        }

        return Response::success($category);
    }

    public function store(Request $request, array $params = []): Response
    {
        $body = $request->body();
        $data = Validator::validate($body, [
            'slug' => 'nullable|string|maxlen:120|slug',
            'sort_order' => 'nullable|integer|min:1',
            'is_active' => 'nullable|boolean',
        ]);

        $data['translations'] = $this->translations($body['translations'] ?? null);
        $id = Category::createCategory($data);
        audit('category.created', 'categories', $id, ['slug' => $data['slug'] ?? null]);

        return Response::success(Category::findAdmin($id), [], 201);
    }

    public function update(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);
        if (Category::findAdmin($id) === null) {
            throw new ApiException('NOT_FOUND', 'La categoría no existe.', 404);
        }

        $body = $request->body();
        $data = Validator::validate($body, [
            'slug' => 'nullable|string|maxlen:120|slug',
            'sort_order' => 'nullable|integer|min:1',
            'is_active' => 'nullable|boolean',
        ]);

        if (array_key_exists('translations', $body)) {
            $data['translations'] = $this->translations($body['translations']);
        }

        Category::updateCategory($id, $data);
        audit('category.updated', 'categories', $id);

        return Response::success(Category::findAdmin($id));
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);
        Category::deleteCategory($id);
        audit('category.deleted', 'categories', $id);

        return Response::noContent();
    }

    public function reorder(Request $request, array $params = []): Response
    {
        $body = $request->body();
        $ids = $body['ids'] ?? null;

        if (!is_array($ids) || $ids === []) {
            throw new ValidationException(['ids' => 'Envía una lista de categorías.']);
        }

        $ids = array_values(array_map('intval', $ids));
        Category::reorder($ids);
        audit('category.reordered', 'categories', null, ['ids' => $ids]);

        return Response::success(Category::allAdmin());
    }

    /** @return array<string, array<string, string>> */
    private function translations(mixed $input): array
    {
        if (!is_array($input)) {
            throw new ValidationException(['translations' => 'Las traducciones son obligatorias.']);
        }

        $result = [];
        foreach (['es', 'en'] as $locale) {
            $entry = $input[$locale] ?? null;
            if (!is_array($entry)) {
                continue;
            }

            $name = trim((string) ($entry['name'] ?? ''));
            if ($locale === 'es' && $name === '') {
                throw new ValidationException(['translations.es.name' => 'El nombre en español es obligatorio.']);
            }
            if ($name === '') {
                continue;
            }

            $result[$locale] = [
                'name' => mb_substr($name, 0, 120),
                'description' => mb_substr(trim((string) ($entry['description'] ?? '')), 0, 500),
            ];
        }

        if (!isset($result['es'])) {
            throw new ValidationException(['translations.es.name' => 'El nombre en español es obligatorio.']);
        }

        return $result;
    }
}
