<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * Cálculo puro del aforo por franja.
 *
 * Cada reserva ocupa la mesa durante `turnMinutes`; dos reservas se solapan si
 * sus intervalos [hora, hora + turno) comparten al menos un minuto. Así una
 * reserva a las 20:00 con turno de 90 minutos bloquea 20:00, 20:30 y 21:00.
 */
final class ReservationCapacity
{
    public static function toMinutes(string $time): int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $time, $matches)) {
            throw new InvalidArgumentException('Hora no válida: ' . $time);
        }

        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];

        if ($hours > 23 || $minutes > 59) {
            throw new InvalidArgumentException('Hora no válida: ' . $time);
        }

        return $hours * 60 + $minutes;
    }

    /**
     * Personas ya comprometidas en el intervalo de la franja solicitada.
     *
     * @param array<int, array<string, mixed>> $reservations
     */
    public static function seatsInWindow(array $reservations, string $time, int $turnMinutes): int
    {
        $turnMinutes = max(1, $turnMinutes);
        $start = self::toMinutes($time);
        $end = $start + $turnMinutes;
        $seats = 0;

        foreach ($reservations as $reservation) {
            $time = (string) ($reservation['reservation_time'] ?? '');
            if ($time === '') {
                continue;
            }

            $otherStart = self::toMinutes(substr($time, 0, 5));
            $otherEnd = $otherStart + $turnMinutes;

            if ($otherStart < $end && $otherEnd > $start) {
                $seats += max(0, (int) ($reservation['party_size'] ?? 0));
            }
        }

        return $seats;
    }

    /**
     * Asientos libres para una franja. `null` significa aforo ilimitado.
     *
     * @param array<int, array<string, mixed>> $reservations
     */
    public static function remaining(int $capacity, array $reservations, string $time, int $turnMinutes): ?int
    {
        if ($capacity <= 0) {
            return null;
        }

        return max(0, $capacity - self::seatsInWindow($reservations, $time, $turnMinutes));
    }
}
