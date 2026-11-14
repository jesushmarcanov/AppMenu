<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Models\RateLimit;
use App\Models\Setting;
use App\Services\MenuService;

final class MenuController
{
    public function index(Request $request, array $params = []): Response
    {
        $this->limitPublic($request);
        $locale = $this->locale($request);
        $payload = (new MenuService())->publicMenu($locale);

        $etag = '"' . md5((string) json_encode($payload)) . '"';
        if ($request->header('if-none-match') === $etag) {
            return Response::raw('', 'application/json; charset=utf-8', 304, ['ETag' => $etag]);
        }

        return Response::success($payload, [], 200, [
            'ETag' => $etag,
            'Cache-Control' => 'public, max-age=60, stale-while-revalidate=300',
        ]);
    }

    public function categories(Request $request, array $params = []): Response
    {
        $this->limitPublic($request);
        $locale = $this->locale($request);

        return Response::success((new MenuService())->publicCategories($locale));
    }

    public function products(Request $request, array $params = []): Response
    {
        $this->limitPublic($request);
        $locale = $this->locale($request);
        $category = $request->queryParam('category');
        $category = is_string($category) ? $category : null;

        return Response::success((new MenuService())->publicProducts($locale, $category));
    }

    public function settings(Request $request, array $params = []): Response
    {
        $this->limitPublic($request);
        $locale = $this->locale($request);

        return Response::success(Setting::public($locale));
    }

    private function locale(Request $request): string
    {
        $lang = $request->queryParam('lang');
        if (!is_string($lang) || $lang === '') {
            $accept = $request->header('accept-language', 'es') ?? 'es';
            $lang = substr($accept, 0, 2);
        }

        return Lang::resolve($lang);
    }

    private function limitPublic(Request $request): void
    {
        RateLimit::hit('public:' . $request->ip(), 120, 60);
    }
}
