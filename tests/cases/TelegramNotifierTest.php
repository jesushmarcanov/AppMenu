<?php

declare(strict_types=1);

use App\Services\TelegramNotifier;

return [
    'TelegramNotifier: valida el token del bot' => static function ($t): void {
        $t->true(TelegramNotifier::isValidToken('123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw'));
        $t->false(TelegramNotifier::isValidToken('123456789'));
        $t->false(TelegramNotifier::isValidToken('abc:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw'));
        $t->false(TelegramNotifier::isValidToken('123456789:corto'));
        $t->false(TelegramNotifier::isValidToken(''));
    },

    'TelegramNotifier: valida el chat ID' => static function ($t): void {
        $t->true(TelegramNotifier::isValidChatId('123456789'));
        $t->true(TelegramNotifier::isValidChatId('-1001234567890'));
        $t->true(TelegramNotifier::isValidChatId('@mi_restaurante'));
        $t->false(TelegramNotifier::isValidChatId('abc'));
        $t->false(TelegramNotifier::isValidChatId('123'));
        $t->false(TelegramNotifier::isValidChatId('@ab'));
        $t->false(TelegramNotifier::isValidChatId(''));
    },

    'TelegramNotifier: solo se activa con configuración completa' => static function ($t): void {
        $token = '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw';

        $t->true((new TelegramNotifier($token, '-1001234567890', true))->isEnabled());
        $t->false((new TelegramNotifier($token, '-1001234567890', false))->isEnabled());
        $t->false((new TelegramNotifier('', '-1001234567890', true))->isEnabled());
        $t->false((new TelegramNotifier($token, '', true))->isEnabled());
        $t->true((new TelegramNotifier($token, '-1001234567890', true))->isConfigured());
        $t->false((new TelegramNotifier($token, '', true))->isConfigured());
    },

    'TelegramNotifier: sin configuración no intenta la red' => static function ($t): void {
        $notifier = new TelegramNotifier('', '', false);
        $result = $notifier->send('Hola');

        $t->false($result['ok']);
        $t->contains('no están activas', (string) $result['error']);
    },

    'TelegramNotifier: formatea una reserva completa' => static function ($t): void {
        $reservation = [
            'name' => 'Ana <b>& Cía',
            'phone' => '+58 412 555 0101',
            'email' => 'ana@example.com',
            'reservation_date' => '2026-11-05',
            'reservation_time' => '20:30:00',
            'party_size' => 4,
            'notes' => 'Mesa junto a la ventana',
        ];

        $text = TelegramNotifier::formatReservation($reservation, [
            'business' => ['name' => 'JM Burguers'],
        ]);

        $t->contains('Nueva reserva', $text);
        $t->contains('JM Burguers', $text);
        $t->contains('Ana &lt;b&gt;&amp; Cía', $text);
        $t->contains('4 personas', $text);
        $t->contains('jueves 05/11/2026', $text);
        $t->contains('20:30', $text);
        $t->contains('+58 412 555 0101', $text);
        $t->contains('ana@example.com', $text);
        $t->contains('Mesa junto a la ventana', $text);
    },

    'TelegramNotifier: singular y campos opcionales vacíos' => static function ($t): void {
        $text = TelegramNotifier::formatReservation([
            'name' => 'Luis',
            'phone' => '5551234',
            'email' => '',
            'reservation_date' => '2026-11-06',
            'reservation_time' => '13:00:00',
            'party_size' => 1,
            'notes' => '',
        ], ['business' => ['name' => 'Demo']]);

        $t->contains('1 persona', $text);
        $t->false(str_contains($text, '📧'), 'No debe incluir correo vacío');
        $t->false(str_contains($text, '📝'), 'No debe incluir notas vacías');
        $t->contains('viernes 06/11/2026', $text);
    },

    'TelegramNotifier: mensaje de prueba identificable' => static function ($t): void {
        $text = TelegramNotifier::formatTest();

        $t->contains('AppMenu', $text);
        $t->contains('correctamente', $text);
    },
];
