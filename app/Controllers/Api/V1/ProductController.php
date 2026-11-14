<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Exceptions\ApiException;
use App\Exceptions\ValidationException;
use App\Models\Product;
use App\Services\ImageService;

final class ProductController
{
    public function index(Request $request, array $params = []): Response
    {
        $filters = [
            'page' => $request->queryParam('page', 1),
            'per_page' => $request->queryParam('per_page', 20),
            'category_id' => $request->queryParam('category_id'),
            'q' => $request->queryParam('q'),
            'status' => $request->queryParam('status'),
            'is_active' => $request->queryParam('is_active'),
        ];

        $result = Product::adminList($filters);

        return Response::success($result['items'], $result['meta']);
    }

    public function show(Request $request, array $params = []): Response
    {
        $product = Product::findAdmin((int) ($params['id'] ?? 0));
        if (!$product) {
            throw new ApiException('NOT_FOUND', 'El producto no existe.', 404);
        }

        return Response::success($product);
    }

    public function store(Request $request, array $params = []): Response
    {
        $body = $request->body();
        $data = Validator::validate($body, [
            'category_id' => 'required|integer|exists:categories,id',
            'price' => 'required|decimal:2|min:0|max:999999',
            'is_available' => 'nullable|boolean',
            'is_featured' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:1',
            'emoji' => 'nullable|string|maxlen:16',
            'slug' => 'nullable|string|maxlen:120|slug',
        ]);

        $data['translations'] = $this->translations($body['translations'] ?? null);
        $id = Product::createProduct($data);
        audit('product.created', 'products', $id, ['category_id' => $data['category_id']]);

        return Response::success(Product::findAdmin($id), [], 201);
    }

    public function update(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);
        if (Product::findAdmin($id) === null) {
            throw new ApiException('NOT_FOUND', 'El producto no existe.', 404);
        }

        $body = $request->body();
        $data = Validator::validate($body, [
            'category_id' => 'nullable|integer|exists:categories,id',
            'price' => 'nullable|decimal:2|min:0|max:999999',
            'is_available' => 'nullable|boolean',
            'is_featured' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:1',
            'emoji' => 'nullable|string|maxlen:16',
            'slug' => 'nullable|string|maxlen:120|slug',
        ]);

        if (array_key_exists('translations', $body)) {
            $data['translations'] = $this->translations($body['translations']);
        }

        Product::updateProduct($id, $data);
        audit('product.updated', 'products', $id);

        return Response::success(Product::findAdmin($id));
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);
        Product::deleteProduct($id);
        audit('product.deleted', 'products', $id);

        return Response::noContent();
    }

    public function availability(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $data = Validator::validate($request->body(), ['is_available' => 'required|boolean']);
        if (Product::findAdmin($id) === null) {
            throw new ApiException('NOT_FOUND', 'El producto no existe.', 404);
        }

        Product::setAvailability($id, (bool) $data['is_available']);
        audit('product.availability_changed', 'products', $id, ['is_available' => (bool) $data['is_available']]);

        return Response::success(Product::findAdmin($id));
    }

    public function featured(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $data = Validator::validate($request->body(), ['is_featured' => 'required|boolean']);
        if (Product::findAdmin($id) === null) {
            throw new ApiException('NOT_FOUND', 'El producto no existe.', 404);
        }

        Product::setFeatured($id, (bool) $data['is_featured']);
        audit('product.featured_changed', 'products', $id, ['is_featured' => (bool) $data['is_featured']]);

        return Response::success(Product::findAdmin($id));
    }

    public function reorder(Request $request, array $params = []): Response
    {
        $ids = $request->body()['ids'] ?? null;
        if (!is_array($ids) || $ids === []) {
            throw new ValidationException(['ids' => 'Envía una lista de productos.']);
        }

        $ids = array_values(array_map('intval', $ids));
        Product::reorder($ids);
        audit('product.reordered', 'products', null, ['ids' => $ids]);

        return Response::success(['ids' => $ids]);
    }

    public function uploadImage(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $product = Product::findAdmin($id);
        if (!$product) {
            throw new ApiException('NOT_FOUND', 'El producto no existe.', 404);
        }

        $file = $request->file('image');
        if ($file === null) {
            throw new ApiException('UPLOAD_ERROR', 'Selecciona una imagen.', 422, ['image' => 'Selecciona una imagen.']);
        }

        $service = new ImageService();
        $paths = $service->store($file, 'products');
        Product::setImage($id, $paths);

        $service->delete($product['image_path'] ?? null);
        if (($product['image_thumb_path'] ?? null) !== ($product['image_path'] ?? null)) {
            $service->delete($product['image_thumb_path'] ?? null);
        }

        audit('product.image_uploaded', 'products', $id);

        return Response::success(Product::findAdmin($id), [], 201);
    }

    public function deleteImage(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $product = Product::findAdmin($id);
        if (!$product) {
            throw new ApiException('NOT_FOUND', 'El producto no existe.', 404);
        }

        $service = new ImageService();
        $service->delete($product['image_path'] ?? null);
        $service->delete($product['image_thumb_path'] ?? null);
        Product::clearImage($id);
        audit('product.image_deleted', 'products', $id);

        return Response::noContent();
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
                'name' => mb_substr($name, 0, 140),
                'description' => mb_substr(trim((string) ($entry['description'] ?? '')), 0, 1000),
                'unit_label' => mb_substr(trim((string) ($entry['unit_label'] ?? '')), 0, 60),
            ];
        }

        if (!isset($result['es'])) {
            throw new ValidationException(['translations.es.name' => 'El nombre en español es obligatorio.']);
        }

        return $result;
    }
}
