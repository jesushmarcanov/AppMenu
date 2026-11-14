<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ApiException;
use App\Exceptions\ValidationException;
use App\Models\Setting;
use App\Services\ImageService;
use App\Services\TelegramNotifier;

final class SettingsController
{
    public function index(Request $request, array $params = []): Response
    {
        return Response::success([
            'settings' => Setting::allWithTranslations(),
            'public' => Setting::public('es'),
            'editable' => Setting::EDITABLE,
            'translatable' => Setting::TRANSLATABLE,
        ]);
    }

    public function update(Request $request, array $params = []): Response
    {
        $body = $request->body();
        $scalars = [];
        $translations = [];
        $errors = [];

        foreach (Setting::EDITABLE as $key) {
            if (!array_key_exists($key, $body)) {
                continue;
            }

            $value = $body[$key];

            if (in_array($key, Setting::TRANSLATABLE, true)) {
                if (is_array($value)) {
                    $es = trim((string) ($value['es'] ?? ''));
                    $en = trim((string) ($value['en'] ?? ''));
                    if ($key === 'business_name' && $es === '') {
                        $errors[$key . '.es'] = 'El nombre del restaurante es obligatorio.';
                    }
                    $translations[$key] = ['es' => $es, 'en' => $en];
                } else {
                    $translations[$key] = ['es' => trim((string) $value)];
                }
                continue;
            }

            $scalars[$key] = $value;
        }

        $this->validateScalars($scalars, $errors);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        Setting::setMany($scalars, $translations);
        audit('settings.updated', 'settings', null, ['keys' => array_keys(array_merge($scalars, $translations))]);

        return Response::success([
            'settings' => Setting::allWithTranslations(),
            'public' => Setting::public('es'),
        ]);
    }

    public function uploadImage(Request $request, array $params = []): Response
    {
        $key = (string) $request->input('key', '');
        if (!in_array($key, ['logo', 'hero'], true)) {
            throw new ValidationException(['key' => 'Indica si la imagen es logo o hero.']);
        }

        $file = $request->file('image');
        if ($file === null) {
            throw new ApiException('UPLOAD_ERROR', 'Selecciona una imagen.', 422, ['image' => 'Selecciona una imagen.']);
        }

        $settingKey = $key === 'logo' ? 'logo_path' : 'hero_image_path';
        $previous = (string) Setting::get($settingKey, '');

        $service = new ImageService();
        $paths = $service->store($file, 'branding');
        Setting::setMany([$settingKey => $paths['image_path']]);
        $service->delete($previous);
        $service->delete(str_replace('.webp', '-thumb.webp', $previous));
        $service->delete(str_replace('.jpg', '-thumb.jpg', $previous));

        audit('settings.image_uploaded', 'settings', null, ['key' => $key]);

        return Response::success([
            'key' => $key,
            'path' => $paths['image_path'],
            'url' => asset_url($paths['image_path']),
            'public' => Setting::public('es'),
        ], [], 201);
    }

    public function testNotification(Request $request, array $params = []): Response
    {
        $notifier = TelegramNotifier::fromSettings(true);

        if (!$notifier->isConfigured()) {
            return Response::success([
                'sent' => false,
                'error' => 'Completa el token del bot y el chat ID antes de probar.',
            ]);
        }

        $result = $notifier->send(TelegramNotifier::formatTest());
        audit('settings.notification_tested', 'settings', null, ['channel' => 'telegram', 'sent' => $result['ok']]);

        return Response::success([
            'sent' => $result['ok'],
            'error' => $result['error'],
        ]);
    }

    /** @param array<string, mixed> $scalars @param array<string, string> $errors */
    private function validateScalars(array &$scalars, array &$errors): void
    {
        if (isset($scalars['default_lang']) && !in_array($scalars['default_lang'], ['es', 'en'], true)) {
            $errors['default_lang'] = 'Idioma no válido.';
        }

        if (isset($scalars['timezone'])) {
            try {
                new \DateTimeZone((string) $scalars['timezone']);
            } catch (\Throwable) {
                $errors['timezone'] = 'Zona horaria no válida.';
            }
        }

        if (isset($scalars['currency_decimals'])) {
            $decimals = (int) $scalars['currency_decimals'];
            if ($decimals < 0 || $decimals > 4) {
                $errors['currency_decimals'] = 'Los decimales deben estar entre 0 y 4.';
            }
            $scalars['currency_decimals'] = $decimals;
        }

        if (isset($scalars['email']) && trim((string) $scalars['email']) !== '' && !filter_var($scalars['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Correo no válido.';
        }

        if (isset($scalars['public_menu_url']) && trim((string) $scalars['public_menu_url']) !== '' && !filter_var($scalars['public_menu_url'], FILTER_VALIDATE_URL)) {
            $errors['public_menu_url'] = 'URL no válida.';
        }

        if (isset($scalars['reservation_start']) && !preg_match('/^\d{2}:\d{2}$/', (string) $scalars['reservation_start'])) {
            $errors['reservation_start'] = 'Hora no válida.';
        }
        if (isset($scalars['reservation_end']) && !preg_match('/^\d{2}:\d{2}$/', (string) $scalars['reservation_end'])) {
            $errors['reservation_end'] = 'Hora no válida.';
        }

        if (isset($scalars['reservation_slot_minutes'])) {
            $slot = (int) $scalars['reservation_slot_minutes'];
            if ($slot < 5 || $slot > 120) {
                $errors['reservation_slot_minutes'] = 'El intervalo debe estar entre 5 y 120 minutos.';
            }
            $scalars['reservation_slot_minutes'] = $slot;
        }

        if (isset($scalars['reservation_max_party'])) {
            $max = (int) $scalars['reservation_max_party'];
            if ($max < 1 || $max > 100) {
                $errors['reservation_max_party'] = 'El máximo de personas debe estar entre 1 y 100.';
            }
            $scalars['reservation_max_party'] = $max;
        }

        if (isset($scalars['reservation_capacity'])) {
            $capacity = (int) $scalars['reservation_capacity'];
            if ($capacity < 0 || $capacity > 500) {
                $errors['reservation_capacity'] = 'El aforo debe estar entre 0 y 500 (0 = sin límite).';
            }
            $scalars['reservation_capacity'] = $capacity;
        }

        if (isset($scalars['reservation_turn_minutes'])) {
            $turn = (int) $scalars['reservation_turn_minutes'];
            if ($turn < 30 || $turn > 240) {
                $errors['reservation_turn_minutes'] = 'La duración debe estar entre 30 y 240 minutos.';
            }
            $scalars['reservation_turn_minutes'] = $turn;
        }

        if (isset($scalars['whatsapp'])) {
            $digits = preg_replace('/\D+/', '', (string) $scalars['whatsapp']) ?? '';
            if ($digits !== '' && (strlen($digits) < 8 || strlen($digits) > 15 || str_starts_with($digits, '0'))) {
                $errors['whatsapp'] = 'Escribe el número en formato internacional, solo dígitos (ej. 584125550101).';
            }
        }

        if (isset($scalars['telegram_bot_token'])) {
            $token = trim((string) $scalars['telegram_bot_token']);
            if ($token !== '' && !TelegramNotifier::isValidToken($token)) {
                $errors['telegram_bot_token'] = 'El token no tiene el formato de un bot de Telegram.';
            }
            $scalars['telegram_bot_token'] = $token;
        }

        if (isset($scalars['telegram_chat_id'])) {
            $chatId = trim((string) $scalars['telegram_chat_id']);
            if ($chatId !== '' && !TelegramNotifier::isValidChatId($chatId)) {
                $errors['telegram_chat_id'] = 'Indica un chat ID numérico (ej. -1001234567890) o @usuario.';
            }
            $scalars['telegram_chat_id'] = $chatId;
        }

        if (isset($scalars['reservation_days'])) {
            if (!is_array($scalars['reservation_days'])) {
                $errors['reservation_days'] = 'Los días deben ser una lista.';
            } else {
                $days = array_values(array_unique(array_map('intval', $scalars['reservation_days'])));
                $days = array_values(array_filter($days, static fn (int $day): bool => $day >= 1 && $day <= 7));
                $scalars['reservation_days'] = $days;
            }
        }

        if (isset($scalars['opening_hours']) && !is_array($scalars['opening_hours'])) {
            $errors['opening_hours'] = 'El horario debe ser un objeto.';
        }

        foreach (['reservation_enabled', 'whatsapp_orders_enabled', 'order_hours_enabled', 'show_sold_out', 'telegram_enabled'] as $boolKey) {
            if (isset($scalars[$boolKey])) {
                $scalars[$boolKey] = in_array($scalars[$boolKey], [true, 1, '1', 'true', 'on'], true) ? '1' : '0';
            }
        }
    }
}
