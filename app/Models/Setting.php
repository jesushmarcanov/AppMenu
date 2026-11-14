<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Lang;

final class Setting
{
    /** @var array<int, string> */
    public const TRANSLATABLE = [
        'business_name',
        'tagline',
        'description',
        'address',
        'opening_hours_text',
        'reservation_note',
    ];

    /** @var array<int, string> */
    public const EDITABLE = [
        'business_name',
        'tagline',
        'description',
        'address',
        'phone',
        'whatsapp',
        'email',
        'instagram',
        'facebook',
        'maps_url',
        'currency_code',
        'currency_symbol',
        'currency_locale',
        'currency_decimals',
        'default_lang',
        'timezone',
        'opening_hours',
        'opening_hours_text',
        'reservation_enabled',
        'reservation_start',
        'reservation_end',
        'reservation_days',
        'reservation_slot_minutes',
        'reservation_max_party',
        'reservation_capacity',
        'reservation_turn_minutes',
        'reservation_note',
        'whatsapp_orders_enabled',
        'order_hours_enabled',
        'show_sold_out',
        'public_menu_url',
        'telegram_enabled',
        'telegram_bot_token',
        'telegram_chat_id',
        'logo_path',
        'hero_image_path',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        $row = Database::fetch(
            'SELECT value FROM settings WHERE setting_key = :setting_key LIMIT 1',
            ['setting_key' => $key],
        );

        return $row['value'] ?? $default;
    }

    public static function getLocalized(string $key, string $locale): mixed
    {
        $row = Database::fetch(
            'SELECT s.value AS base_value, t.value AS translated_value
             FROM settings s
             LEFT JOIN setting_translations t ON t.setting_id = s.id AND t.locale = :locale
             WHERE s.setting_key = :setting_key
             LIMIT 1',
            ['locale' => $locale, 'setting_key' => $key],
        );

        if (!$row) {
            return null;
        }

        return $row['translated_value'] ?? $row['base_value'];
    }

    /** @return array<string, array{value: string, is_public: bool, translations: array<string, string>}> */
    public static function allWithTranslations(): array
    {
        $rows = Database::fetchAll('SELECT * FROM settings ORDER BY setting_key ASC');
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $translations = Database::fetchAll(
            'SELECT setting_id, locale, value FROM setting_translations WHERE setting_id IN (' . $placeholders . ')',
            $ids,
        );

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row['setting_key'];
        }

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['setting_key']] = [
                'value' => (string) $row['value'],
                'is_public' => (int) $row['is_public'] === 1,
                'translations' => [],
            ];
        }

        foreach ($translations as $translation) {
            $key = $byId[(int) $translation['setting_id']] ?? null;
            if ($key !== null) {
                $result[$key]['translations'][(string) $translation['locale']] = (string) $translation['value'];
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $scalars
     * @param array<string, array<string, string>> $translations
     */
    public static function setMany(array $scalars, array $translations = []): void
    {
        Database::transaction(static function () use ($scalars, $translations): void {
            $keys = array_unique(array_merge(array_keys($scalars), array_keys($translations)));

            foreach ($keys as $key) {
                if (!in_array($key, self::EDITABLE, true)) {
                    throw new \InvalidArgumentException('Configuración no permitida: ' . $key);
                }

                $translationValues = $translations[$key] ?? [];
                $isTranslatable = in_array($key, self::TRANSLATABLE, true);
                $baseValue = $translationValues['es'] ?? ($scalars[$key] ?? null);

                if ($baseValue === null && $isTranslatable) {
                    $baseValue = '';
                }

                if (is_array($baseValue)) {
                    $baseValue = json_encode($baseValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } elseif (is_bool($baseValue)) {
                    $baseValue = $baseValue ? '1' : '0';
                }

                $baseValue = (string) $baseValue;

                Database::execute(
                    'INSERT INTO settings (setting_key, value, is_public, updated_at)
                     VALUES (:setting_key, :value, 1, :updated_at)
                     ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)',
                    ['setting_key' => $key, 'value' => $baseValue, 'updated_at' => now()],
                );

                if (!$isTranslatable) {
                    continue;
                }

                $setting = Database::fetch(
                    'SELECT id FROM settings WHERE setting_key = :setting_key LIMIT 1',
                    ['setting_key' => $key],
                );
                if (!$setting) {
                    continue;
                }

                foreach (Lang::supported() as $locale) {
                    $value = $translationValues[$locale] ?? ($locale === 'es' ? $baseValue : null);
                    if ($value === null) {
                        continue;
                    }

                    Database::execute(
                        'INSERT INTO setting_translations (setting_id, locale, value)
                         VALUES (:setting_id, :locale, :value)
                         ON DUPLICATE KEY UPDATE value = VALUES(value)',
                        ['setting_id' => (int) $setting['id'], 'locale' => $locale, 'value' => (string) $value],
                    );
                }
            }
        });
    }

    /** @return array<string, mixed> */
    public static function public(string $locale): array
    {
        $settings = self::allWithTranslations();
        $localized = static function (string $key) use ($settings, $locale): string {
            $entry = $settings[$key] ?? null;
            if ($entry === null) {
                return '';
            }

            return (string) ($entry['translations'][$locale] ?? $entry['translations']['es'] ?? $entry['value']);
        };
        $raw = static fn (string $key, string $default = ''): string => (string) ($settings[$key]['value'] ?? $default);

        $openingHours = json_decode($raw('opening_hours', '{}'), true);
        if (!is_array($openingHours)) {
            $openingHours = [];
        }

        $reservationDays = json_decode($raw('reservation_days', '[1,2,3,4,5,6]'), true);
        if (!is_array($reservationDays)) {
            $reservationDays = [1, 2, 3, 4, 5, 6];
        }
        $reservationDays = array_values(array_map('intval', $reservationDays));

        return [
            'business' => [
                'name' => $localized('business_name'),
                'tagline' => $localized('tagline'),
                'description' => $localized('description'),
                'address' => $localized('address'),
                'phone' => $raw('phone'),
                'whatsapp' => $raw('whatsapp'),
                'email' => $raw('email'),
                'instagram' => $raw('instagram'),
                'facebook' => $raw('facebook'),
                'maps_url' => $raw('maps_url'),
                'logo_url' => asset_url($raw('logo_path')),
                'hero_image_url' => asset_url($raw('hero_image_path')),
                'opening_hours_text' => $localized('opening_hours_text'),
            ],
            'currency' => [
                'code' => $raw('currency_code', 'USD'),
                'symbol' => $raw('currency_symbol', '$'),
                'locale' => $raw('currency_locale', 'es-VE'),
                'decimals' => (int) $raw('currency_decimals', '2'),
            ],
            'locale' => $locale,
            'default_lang' => $raw('default_lang', 'es'),
            'timezone' => $raw('timezone', (string) config('app.timezone', 'America/Caracas')),
            'opening_hours' => $openingHours,
            'reservation' => [
                'enabled' => $raw('reservation_enabled', '1') === '1',
                'start' => $raw('reservation_start', '12:00'),
                'end' => $raw('reservation_end', '22:00'),
                'days' => $reservationDays,
                'slot_minutes' => (int) $raw('reservation_slot_minutes', '30'),
                'max_party' => (int) $raw('reservation_max_party', '20'),
                'capacity' => max(0, (int) $raw('reservation_capacity', '20')),
                'turn_minutes' => max(30, (int) $raw('reservation_turn_minutes', '90')),
                'note' => $localized('reservation_note'),
            ],
            'whatsapp_orders_enabled' => $raw('whatsapp_orders_enabled', '1') === '1',
            'order_hours_enabled' => $raw('order_hours_enabled', '1') === '1',
            'show_sold_out' => $raw('show_sold_out', '1') === '1',
            'public_menu_url' => $raw('public_menu_url') !== '' ? $raw('public_menu_url') : app_public_url('/'),
            'public_menu_url_is_configured' => $raw('public_menu_url') !== '',
            'locales' => Lang::supported(),
        ];
    }
}
