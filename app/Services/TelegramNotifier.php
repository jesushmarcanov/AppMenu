<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Models\Setting;

/**
 * Avisos por Telegram (Bot API). No requiere Composer ni dependencias: usa cURL
 * si está disponible y, si no, `file_get_contents` con un contexto HTTP.
 *
 * El token nunca se registra en logs ni se devuelve en los errores.
 */
final class TelegramNotifier
{
    private const ENDPOINT = 'https://api.telegram.org/bot%s/sendMessage';
    private const TIMEOUT = 6;

    /** @var array<int, string> */
    private const WEEKDAYS = [
        'domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado',
    ];

    public function __construct(
        private readonly string $token = '',
        private readonly string $chatId = '',
        private readonly bool $enabled = false,
    ) {
    }

    public static function fromSettings(bool $forceEnabled = false): self
    {
        return new self(
            trim((string) Setting::get('telegram_bot_token', '')),
            trim((string) Setting::get('telegram_chat_id', '')),
            $forceEnabled || (string) Setting::get('telegram_enabled', '0') === '1',
        );
    }

    public static function isValidToken(string $token): bool
    {
        return (bool) preg_match('/^\d{5,}:[A-Za-z0-9_-]{20,}$/', trim($token));
    }

    public static function isValidChatId(string $chatId): bool
    {
        return (bool) preg_match('/^(-?\d{5,20}|@[A-Za-z0-9_]{5,32})$/', trim($chatId));
    }

    public function isEnabled(): bool
    {
        return $this->enabled && $this->isConfigured();
    }

    public function isConfigured(): bool
    {
        return self::isValidToken($this->token) && self::isValidChatId($this->chatId);
    }

    /** @return array{ok: bool, error: string|null} */
    public function send(string $text): array
    {
        if (!$this->isEnabled()) {
            return ['ok' => false, 'error' => 'Las notificaciones por Telegram no están activas o están incompletas.'];
        }

        return $this->request([
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]);
    }

    /** @return array{ok: bool, error: string|null} */
    private function request(array $payload): array
    {
        $url = sprintf(self::ENDPOINT, $this->token);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return ['ok' => false, 'error' => 'No se pudo preparar el mensaje.'];
        }

        [$body, $status, $transportError] = $this->post($url, $json);
        if ($transportError !== null) {
            return ['ok' => false, 'error' => $this->scrub($transportError)];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'error' => 'Respuesta no válida de Telegram (HTTP ' . $status . ').'];
        }

        if (($decoded['ok'] ?? false) === true) {
            return ['ok' => true, 'error' => null];
        }

        $description = (string) ($decoded['description'] ?? 'Error desconocido de Telegram.');
        Logger::error('Telegram rechazó la notificación', ['status' => $status]);

        return ['ok' => false, 'error' => $this->scrub($description)];
    }

    /** @return array{0: string, 1: int, 2: string|null} */
    private function post(string $url, string $json): array
    {
        if (function_exists('curl_init')) {
            $handle = curl_init($url);
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
                CURLOPT_TIMEOUT => self::TIMEOUT,
            ]);
            $body = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error = curl_error($handle);
            curl_close($handle);

            return [is_string($body) ? $body : '', $status, $body === false ? ($error ?: 'No se pudo conectar con Telegram.') : null];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $json,
                'timeout' => self::TIMEOUT,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches)) {
                $status = (int) $matches[1];
            }
        }

        return [is_string($body) ? $body : '', $status, $body === false ? 'No se pudo conectar con Telegram.' : null];
    }

    /** @param array<string, mixed> $reservation @param array<string, mixed> $settings */
    public static function formatReservation(array $reservation, array $settings): string
    {
        $business = (string) ($settings['business']['name'] ?? 'el restaurante');
        $party = max(1, (int) ($reservation['party_size'] ?? 1));
        $date = (string) ($reservation['reservation_date'] ?? '');
        $time = substr((string) ($reservation['reservation_time'] ?? ''), 0, 5);
        $timestamp = strtotime($date . ' ' . ($time === '' ? '00:00' : $time));
        $weekday = $timestamp !== false ? self::WEEKDAYS[(int) date('w', $timestamp)] : '';
        $formattedDate = $timestamp !== false ? date('d/m/Y', $timestamp) : $date;

        $lines = [
            '🍽️ <b>Nueva reserva</b> · ' . self::escape($business),
            '👤 ' . self::escape((string) ($reservation['name'] ?? '')) . ' · ' . $party . ($party === 1 ? ' persona' : ' personas'),
            '📅 ' . self::escape(trim($weekday . ' ' . $formattedDate)) . ' · ' . self::escape($time),
            '📞 ' . self::escape((string) ($reservation['phone'] ?? '')),
        ];

        $email = trim((string) ($reservation['email'] ?? ''));
        if ($email !== '') {
            $lines[] = '📧 ' . self::escape($email);
        }

        $notes = trim((string) ($reservation['notes'] ?? ''));
        if ($notes !== '') {
            $lines[] = '📝 ' . self::escape($notes);
        }

        return implode("\n", $lines);
    }

    public static function formatTest(): string
    {
        return "✅ <b>AppMenu</b>\nLas notificaciones por Telegram están configuradas correctamente.";
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function scrub(string $message): string
    {
        return $this->token === '' ? $message : str_replace($this->token, '***', $message);
    }
}
