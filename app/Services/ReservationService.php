<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Exceptions\ApiException;
use App\Models\RateLimit;
use App\Models\Reservation;
use App\Models\Setting;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class ReservationService
{
    /** @return array<string, mixed> */
    public function create(array $data, string $locale): array
    {
        RateLimit::hit('reservation:' . client_ip(), 3, 3600);

        $settings = Setting::public($locale);
        $reservationSettings = $settings['reservation'] ?? [];

        if (!($reservationSettings['enabled'] ?? true)) {
            throw new ApiException('RESERVATIONS_DISABLED', 'Las reservaciones están deshabilitadas por ahora.', 403);
        }

        $timezone = new DateTimeZone((string) ($settings['timezone'] ?? 'America/Caracas'));
        $today = new DateTimeImmutable('today', $timezone);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($data['reservation_date'] ?? ''), $timezone);

        if (!$date || $date->format('Y-m-d') !== (string) ($data['reservation_date'] ?? '')) {
            throw new ApiException('VALIDATION_ERROR', 'La fecha no es válida.', 422, [
                'reservation_date' => 'Selecciona una fecha válida.',
            ]);
        }

        if ($date < $today) {
            throw new ApiException('VALIDATION_ERROR', 'No se pueden reservar fechas pasadas.', 422, [
                'reservation_date' => 'La fecha ya pasó.',
            ]);
        }

        $maxDate = $today->modify('+90 days');
        if ($date > $maxDate) {
            throw new ApiException('VALIDATION_ERROR', 'Solo se aceptan reservas hasta 90 días adelante.', 422, [
                'reservation_date' => 'La fecha está demasiado lejos.',
            ]);
        }

        $allowedDays = array_map('intval', $reservationSettings['days'] ?? []);
        if ($allowedDays !== [] && !in_array((int) $date->format('N'), $allowedDays, true)) {
            throw new ApiException('VALIDATION_ERROR', 'El restaurante no abre ese día.', 422, [
                'reservation_date' => 'No hay servicio ese día.',
            ]);
        }

        $window = $this->window($date, $reservationSettings, (array) ($settings['opening_hours'] ?? []));
        if ($window['closed']) {
            throw new ApiException('VALIDATION_ERROR', 'El restaurante no abre ese día.', 422, [
                'reservation_date' => 'No hay servicio ese día.',
            ]);
        }

        $time = (string) ($data['reservation_time'] ?? '');
        if (!preg_match('/^(\d{2}):(\d{2})$/', $time, $matches)) {
            throw new ApiException('VALIDATION_ERROR', 'La hora no es válida.', 422, [
                'reservation_time' => 'Selecciona una hora válida.',
            ]);
        }

        $minutes = ((int) $matches[1]) * 60 + (int) $matches[2];
        $slot = max(5, (int) ($reservationSettings['slot_minutes'] ?? 30));

        if ($minutes < $window['start'] || $minutes >= $window['end'] || (($minutes - $window['start']) % $slot) !== 0) {
            throw new ApiException('VALIDATION_ERROR', 'La hora está fuera del horario o no es un bloque válido.', 422, [
                'reservation_time' => 'Selecciona una hora dentro del horario de atención.',
            ]);
        }

        if ($date->format('Y-m-d') === $today->format('Y-m-d')) {
            $now = new DateTimeImmutable('now', $timezone);
            $nowMinutes = ((int) $now->format('G')) * 60 + (int) $now->format('i');
            if ($minutes <= $nowMinutes + 15) {
                throw new ApiException('VALIDATION_ERROR', 'Esa hora ya pasó. Elige una franja posterior.', 422, [
                    'reservation_time' => 'Esa hora ya pasó.',
                ]);
            }
        }

        $partySize = (int) ($data['party_size'] ?? 0);
        $maxParty = max(1, (int) ($reservationSettings['max_party'] ?? 20));
        if ($partySize < 1 || $partySize > $maxParty) {
            throw new ApiException('VALIDATION_ERROR', 'Cantidad de personas no válida.', 422, [
                'party_size' => 'Debe ser entre 1 y ' . $maxParty . ' personas.',
            ]);
        }

        $capacity = max(0, (int) ($reservationSettings['capacity'] ?? 0));
        $turnMinutes = max(30, (int) ($reservationSettings['turn_minutes'] ?? 90));

        $id = Database::transaction(function () use ($capacity, $turnMinutes, $time, $partySize, $date, $data, $locale): int {
            if ($capacity > 0) {
                $booked = Reservation::seatsOnDate($date->format('Y-m-d'), true);
                $used = ReservationCapacity::seatsInWindow($booked, $time, $turnMinutes);

                if ($used + $partySize > $capacity) {
                    $remaining = max(0, $capacity - $used);
                    $message = $remaining > 0
                        ? 'Solo quedan ' . $remaining . ' asientos disponibles en esa franja.'
                        : 'Esa franja ya está completa. Elige otra hora.';

                    throw new ApiException('SLOT_FULL', $message, 422, [
                        'reservation_time' => $message,
                    ]);
                }
            }

            return Reservation::createReservation([
                'name' => $data['name'],
                'phone' => $data['phone'],
                'email' => $data['email'] ?? null,
                'reservation_date' => $date->format('Y-m-d'),
                'reservation_time' => $time,
                'party_size' => $partySize,
                'notes' => $data['notes'] ?? null,
                'locale' => $locale,
            ]);
        });

        audit('reservation.created', 'reservations', $id, [
            'date' => $date->format('Y-m-d'),
            'time' => $time,
            'party_size' => $partySize,
        ]);

        $this->notify($id, $settings);

        return [
            'id' => $id,
            'status' => 'pending',
            'reservation_date' => $date->format('Y-m-d'),
            'reservation_time' => $time,
            'party_size' => $partySize,
        ];
    }

    /**
     * Franjas de una fecha con los asientos que quedan en cada una.
     *
     * @return array<string, mixed>
     */
    public function availability(string $date, string $locale): array
    {
        RateLimit::hit('reservation-availability:' . client_ip(), 120, 3600);

        $settings = Setting::public($locale);
        $reservationSettings = $settings['reservation'] ?? [];
        $timezone = new DateTimeZone((string) ($settings['timezone'] ?? 'America/Caracas'));
        $today = new DateTimeImmutable('today', $timezone);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);

        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new ApiException('VALIDATION_ERROR', 'La fecha no es válida.', 422, [
                'reservation_date' => 'Selecciona una fecha válida.',
            ]);
        }

        if ($parsed < $today || $parsed > $today->modify('+90 days')) {
            throw new ApiException('VALIDATION_ERROR', 'La fecha está fuera del rango permitido.', 422, [
                'reservation_date' => 'Selecciona una fecha dentro de los próximos 90 días.',
            ]);
        }

        $window = $this->window($parsed, $reservationSettings, (array) ($settings['opening_hours'] ?? []));
        $slot = max(5, (int) ($reservationSettings['slot_minutes'] ?? 30));
        $capacity = max(0, (int) ($reservationSettings['capacity'] ?? 0));
        $turnMinutes = max(30, (int) ($reservationSettings['turn_minutes'] ?? 90));
        $booked = $capacity > 0 && !$window['closed'] ? Reservation::seatsOnDate($date) : [];

        $slots = [];
        if (!$window['closed']) {
            for ($cursor = $window['start']; $cursor < $window['end']; $cursor += $slot) {
                $time = sprintf('%02d:%02d', intdiv($cursor, 60), $cursor % 60);
                $remaining = ReservationCapacity::remaining($capacity, $booked, $time, $turnMinutes);
                $slots[] = [
                    'time' => $time,
                    'remaining' => $remaining,
                    'available' => $remaining === null || $remaining > 0,
                ];
            }
        }

        return [
            'date' => $date,
            'closed' => $window['closed'],
            'slot_minutes' => $slot,
            'turn_minutes' => $turnMinutes,
            'capacity' => $capacity,
            'slots' => $slots,
        ];
    }

    /**
     * Ventana efectiva de reservas: intersección entre el horario de atención del
     * día y la ventana configurada para reservas.
     *
     * @param array<string, mixed> $reservationSettings
     * @param array<string, mixed> $openingHours
     * @return array{closed: bool, start: int, end: int}
     */
    private function window(DateTimeImmutable $date, array $reservationSettings, array $openingHours): array
    {
        $dayKey = OrderAvailability::DAY_KEYS[((int) $date->format('N')) - 1];
        $schedule = $openingHours[$dayKey] ?? null;

        if (is_array($schedule) && !empty($schedule['closed'])) {
            return ['closed' => true, 'start' => 0, 'end' => 0];
        }

        $start = ReservationCapacity::toMinutes((string) ($reservationSettings['start'] ?? '12:00'));
        $end = ReservationCapacity::toMinutes((string) ($reservationSettings['end'] ?? '22:00'));

        if (is_array($schedule) && !empty($schedule['open']) && !empty($schedule['close'])) {
            $open = ReservationCapacity::toMinutes(substr((string) $schedule['open'], 0, 5));
            $close = ReservationCapacity::toMinutes(substr((string) $schedule['close'], 0, 5));

            if ($close <= $open) {
                $close += 1440;
            }

            $start = max($start, $open);
            $end = min($end, $close);
        }

        if ($end <= $start) {
            return ['closed' => true, 'start' => 0, 'end' => 0];
        }

        return ['closed' => false, 'start' => $start, 'end' => $end];
    }

    /** @param array<string, mixed> $settings */
    private function notify(int $id, array $settings): void
    {
        try {
            $notifier = TelegramNotifier::fromSettings();
            if (!$notifier->isEnabled()) {
                return;
            }

            $reservation = Reservation::findReservation($id);
            if ($reservation === null) {
                return;
            }

            $result = $notifier->send(TelegramNotifier::formatReservation($reservation, $settings));
            if (!$result['ok']) {
                Logger::error('No se pudo enviar la notificación de la reserva: ' . ($result['error'] ?? 'error desconocido'));
            }
        } catch (Throwable $error) {
            Logger::error($error);
        }
    }
}
