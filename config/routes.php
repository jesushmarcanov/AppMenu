<?php

declare(strict_types=1);

use App\Controllers\Api\V1\AuditController;
use App\Controllers\Api\V1\AuthController;
use App\Controllers\Api\V1\CategoryController;
use App\Controllers\Api\V1\DashboardController;
use App\Controllers\Api\V1\HealthController;
use App\Controllers\Api\V1\MenuController;
use App\Controllers\Api\V1\OrderController;
use App\Controllers\Api\V1\ProductController;
use App\Controllers\Api\V1\QrController;
use App\Controllers\Api\V1\ReservationController;
use App\Controllers\Api\V1\SettingsController;
use App\Controllers\Api\V1\TwoFactorController;
use App\Controllers\Api\V1\UserController;

/**
 * Tabla de rutas. Cada entrada: [método, ruta, [controlador, acción], middleware?].
 * Las rutas con segmentos fijos (reorder, availability...) van antes que {id}.
 */
return [
    // Públicas
    ['GET', '/api/v1/health', [HealthController::class, 'index']],
    ['GET', '/api/v1/csrf', [AuthController::class, 'csrf']],
    ['GET', '/api/v1/menu', [MenuController::class, 'index']],
    ['GET', '/api/v1/categories', [MenuController::class, 'categories']],
    ['GET', '/api/v1/products', [MenuController::class, 'products']],
    ['GET', '/api/v1/settings/public', [MenuController::class, 'settings']],
    ['GET', '/api/v1/reservations/availability', [ReservationController::class, 'availability']],
    ['GET', '/api/v1/orders/availability', [OrderController::class, 'availability']],
    ['POST', '/api/v1/reservations', [ReservationController::class, 'store'], ['csrf']],

    // Autenticación
    ['POST', '/api/v1/auth/login', [AuthController::class, 'login'], ['csrf']],
    ['POST', '/api/v1/auth/login/2fa', [AuthController::class, 'twoFactor'], ['csrf']],
    ['POST', '/api/v1/auth/logout', [AuthController::class, 'logout'], ['auth', 'csrf']],
    ['GET', '/api/v1/auth/me', [AuthController::class, 'me'], ['auth']],
    ['POST', '/api/v1/auth/change-password', [AuthController::class, 'changePassword'], ['auth', 'csrf']],

    // Verificación en dos pasos (TOTP, solo administradores)
    ['GET', '/api/v1/auth/2fa', [TwoFactorController::class, 'status'], ['auth', 'admin']],
    ['POST', '/api/v1/auth/2fa/setup', [TwoFactorController::class, 'setup'], ['auth', 'admin', 'csrf']],
    ['POST', '/api/v1/auth/2fa/enable', [TwoFactorController::class, 'enable'], ['auth', 'admin', 'csrf']],
    ['POST', '/api/v1/auth/2fa/disable', [TwoFactorController::class, 'disable'], ['auth', 'admin', 'csrf']],
    ['POST', '/api/v1/auth/2fa/recovery-codes', [TwoFactorController::class, 'recoveryCodes'], ['auth', 'admin', 'csrf']],

    // Panel: dashboard
    ['GET', '/api/v1/admin/dashboard', [DashboardController::class, 'index'], ['auth', 'editor']],

    // Panel: categorías
    ['PUT', '/api/v1/admin/categories/reorder', [CategoryController::class, 'reorder'], ['auth', 'editor', 'csrf']],
    ['GET', '/api/v1/admin/categories', [CategoryController::class, 'index'], ['auth', 'editor']],
    ['POST', '/api/v1/admin/categories', [CategoryController::class, 'store'], ['auth', 'editor', 'csrf']],
    ['GET', '/api/v1/admin/categories/{id}', [CategoryController::class, 'show'], ['auth', 'editor']],
    ['PUT', '/api/v1/admin/categories/{id}', [CategoryController::class, 'update'], ['auth', 'editor', 'csrf']],
    ['DELETE', '/api/v1/admin/categories/{id}', [CategoryController::class, 'destroy'], ['auth', 'editor', 'csrf']],

    // Panel: productos
    ['PUT', '/api/v1/admin/products/reorder', [ProductController::class, 'reorder'], ['auth', 'editor', 'csrf']],
    ['PATCH', '/api/v1/admin/products/{id}/availability', [ProductController::class, 'availability'], ['auth', 'editor', 'csrf']],
    ['PATCH', '/api/v1/admin/products/{id}/featured', [ProductController::class, 'featured'], ['auth', 'editor', 'csrf']],
    ['POST', '/api/v1/admin/products/{id}/image', [ProductController::class, 'uploadImage'], ['auth', 'editor', 'csrf']],
    ['DELETE', '/api/v1/admin/products/{id}/image', [ProductController::class, 'deleteImage'], ['auth', 'editor', 'csrf']],
    ['GET', '/api/v1/admin/products', [ProductController::class, 'index'], ['auth', 'editor']],
    ['POST', '/api/v1/admin/products', [ProductController::class, 'store'], ['auth', 'editor', 'csrf']],
    ['GET', '/api/v1/admin/products/{id}', [ProductController::class, 'show'], ['auth', 'editor']],
    ['PUT', '/api/v1/admin/products/{id}', [ProductController::class, 'update'], ['auth', 'editor', 'csrf']],
    ['DELETE', '/api/v1/admin/products/{id}', [ProductController::class, 'destroy'], ['auth', 'editor', 'csrf']],

    // Panel: reservaciones
    ['GET', '/api/v1/admin/reservations', [ReservationController::class, 'index'], ['auth', 'editor']],
    ['GET', '/api/v1/admin/reservations/{id}', [ReservationController::class, 'show'], ['auth', 'editor']],
    ['PATCH', '/api/v1/admin/reservations/{id}', [ReservationController::class, 'update'], ['auth', 'editor', 'csrf']],
    ['DELETE', '/api/v1/admin/reservations/{id}', [ReservationController::class, 'destroy'], ['auth', 'editor', 'csrf']],

    // Panel: QR
    ['GET', '/api/v1/admin/qr', [QrController::class, 'image'], ['auth', 'editor']],
    ['GET', '/api/v1/admin/qr/print', [QrController::class, 'printable'], ['auth', 'editor']],

    // Panel: configuración (solo admin)
    ['GET', '/api/v1/admin/settings', [SettingsController::class, 'index'], ['auth', 'admin']],
    ['PUT', '/api/v1/admin/settings', [SettingsController::class, 'update'], ['auth', 'admin', 'csrf']],
    ['POST', '/api/v1/admin/settings/image', [SettingsController::class, 'uploadImage'], ['auth', 'admin', 'csrf']],
    ['POST', '/api/v1/admin/settings/test-notification', [SettingsController::class, 'testNotification'], ['auth', 'admin', 'csrf']],

    // Panel: usuarios y auditoría (solo admin)
    ['GET', '/api/v1/admin/users', [UserController::class, 'index'], ['auth', 'admin']],
    ['POST', '/api/v1/admin/users', [UserController::class, 'store'], ['auth', 'admin', 'csrf']],
    ['GET', '/api/v1/admin/users/{id}', [UserController::class, 'show'], ['auth', 'admin']],
    ['PUT', '/api/v1/admin/users/{id}', [UserController::class, 'update'], ['auth', 'admin', 'csrf']],
    ['DELETE', '/api/v1/admin/users/{id}', [UserController::class, 'destroy'], ['auth', 'admin', 'csrf']],
    ['GET', '/api/v1/admin/audit-log', [AuditController::class, 'index'], ['auth', 'admin']],
];
