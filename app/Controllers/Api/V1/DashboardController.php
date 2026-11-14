<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\Request;
use App\Core\Response;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\Reservation;

final class DashboardController
{
    public function index(Request $request, array $params = []): Response
    {
        $products = Product::counts();
        $upcoming = Reservation::upcoming(6);
        $audit = AuditLog::paginate(1, 6);

        return Response::success([
            'stats' => [
                'products_active' => $products['active'],
                'products_total' => $products['total'],
                'products_sold_out' => $products['sold_out'],
                'products_featured' => $products['featured'],
                'categories_active' => Category::count('is_active = 1'),
                'reservations_today' => Reservation::todayCount(),
                'reservations_pending' => Reservation::pendingCount(),
            ],
            'upcoming_reservations' => $upcoming,
            'recent_activity' => $audit['items'],
        ]);
    }
}
