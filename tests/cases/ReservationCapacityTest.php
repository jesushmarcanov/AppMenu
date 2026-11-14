<?php

declare(strict_types=1);

use App\Services\ReservationCapacity;

return [
    'ReservationCapacity: convierte horas a minutos' => static function ($t): void {
        $t->same(0, ReservationCapacity::toMinutes('00:00'));
        $t->same(750, ReservationCapacity::toMinutes('12:30'));
        $t->same(1439, ReservationCapacity::toMinutes('23:59'));
    },

    'ReservationCapacity: rechaza horas inválidas' => static function ($t): void {
        foreach (['24:00', '12:60', 'abc', '12', '12:3'] as $value) {
            try {
                ReservationCapacity::toMinutes($value);
                $t->true(false, 'Se esperaba excepción para ' . $value);
            } catch (InvalidArgumentException) {
                $t->true(true);
            }
        }
    },

    'ReservationCapacity: suma solo reservas que se solapan' => static function ($t): void {
        $reservations = [
            ['reservation_time' => '19:30:00', 'party_size' => 2], // 19:30–21:00
            ['reservation_time' => '20:00:00', 'party_size' => 4], // 20:00–21:30
            ['reservation_time' => '20:30:00', 'party_size' => 6], // 20:30–22:00
            ['reservation_time' => '22:30:00', 'party_size' => 3], // fuera
            ['reservation_time' => '18:30:00', 'party_size' => 8], // termina justo a las 20:00
        ];

        $t->same(12, ReservationCapacity::seatsInWindow($reservations, '20:00', 90));
        $t->same(10, ReservationCapacity::seatsInWindow($reservations, '21:00', 90));
    },

    'ReservationCapacity: asientos restantes y aforo ilimitado' => static function ($t): void {
        $reservations = [
            ['reservation_time' => '20:00:00', 'party_size' => 4],
            ['reservation_time' => '20:30:00', 'party_size' => 6],
        ];

        $t->same(10, ReservationCapacity::remaining(20, $reservations, '20:00', 90));
        $t->same(0, ReservationCapacity::remaining(8, $reservations, '20:00', 90));
        $t->null(ReservationCapacity::remaining(0, $reservations, '20:00', 90));
        $t->null(ReservationCapacity::remaining(-5, $reservations, '20:00', 90));
    },

    'ReservationCapacity: ignora filas sin hora y tamaños negativos' => static function ($t): void {
        $reservations = [
            ['reservation_time' => '', 'party_size' => 5],
            ['reservation_time' => '20:15:00', 'party_size' => -3],
            ['reservation_time' => '20:15:00', 'party_size' => 2],
        ];

        $t->same(2, ReservationCapacity::seatsInWindow($reservations, '20:00', 90));
    },
];
