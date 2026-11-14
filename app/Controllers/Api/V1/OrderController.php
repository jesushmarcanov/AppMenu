<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\Request;
use App\Core\Response;
use App\Models\Setting;
use App\Services\OrderAvailability;

final class OrderController
{
    public function availability(Request $request, array $params = []): Response
    {
        $settings = Setting::public('es');
        $business = $settings['business'] ?? [];
        $enabled = (bool) ($settings['whatsapp_orders_enabled'] ?? true);
        $respectHours = (bool) ($settings['order_hours_enabled'] ?? true);

        $digits = preg_replace('/\D+/', '', (string) ($business['whatsapp'] ?? '')) ?? '';
        $validNumber = strlen($digits) >= 8 && strlen($digits) <= 15 && !str_starts_with($digits, '0');

        $hours = OrderAvailability::evaluate(
            (array) ($settings['opening_hours'] ?? []),
            (string) ($settings['timezone'] ?? 'America/Caracas'),
            $respectHours,
        );

        $reason = 'ok';
        if (!$enabled) {
            $reason = 'disabled';
        } elseif (!$validNumber) {
            $reason = 'invalid_number';
        } elseif (!$hours['open']) {
            $reason = 'closed';
        }

        return Response::success(array_merge($hours, [
            'enabled' => $enabled,
            'can_order' => $reason === 'ok',
            'reason' => $reason,
        ]));
    }
}
