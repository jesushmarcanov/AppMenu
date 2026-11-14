<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Estado de apertura para pedidos, calculado en la zona horaria del restaurante.
 *
 * Solo hay una ventana por día (apertura/cierre). Si el cierre es anterior a la
 * apertura se interpreta como cierre pasada la medianoche.
 */
final class OrderAvailability
{
    /** @var array<int, string> */
    public const DAY_KEYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /**
     * @param array<string, array<string, mixed>> $openingHours
     * @return array<string, mixed>
     */
    public static function evaluate(
        array $openingHours,
        string $timezone,
        bool $respectHours = true,
        ?DateTimeImmutable $now = null,
    ): array {
        $zone = self::timezone($timezone);
        $now = $now === null ? new DateTimeImmutable('now', $zone) : $now->setTimezone($zone);
        $dayIndex = ((int) $now->format('N')) - 1;
        $nowMinutes = ((int) $now->format('G')) * 60 + (int) $now->format('i');

        $todayKey = self::DAY_KEYS[$dayIndex];
        $today = self::schedule($openingHours, $todayKey);

        $result = [
            'open' => true,
            'respect_hours' => $respectHours,
            'reason' => 'always_open',
            'timezone' => $zone->getName(),
            'now' => $now->format(DATE_ATOM),
            'weekday' => $todayKey,
            'today' => [
                'day' => $todayKey,
                'open' => $today['open'],
                'close' => $today['close'],
                'closed' => $today['closed'],
            ],
            'closes_at' => null,
            'opens_at' => null,
        ];

        if (!$respectHours || $openingHours === []) {
            return $result;
        }

        // Turno de ayer que termina después de medianoche (ej. 18:00 – 01:00).
        $previousKey = self::DAY_KEYS[($dayIndex + 6) % 7];
        $previous = self::schedule($openingHours, $previousKey);
        if (!$previous['closed']) {
            [$previousOpen, $previousClose] = [$previous['start'], $previous['end']];
            if ($previousClose <= $previousOpen) {
                $previousClose += 1440;
            }
            if ($previousClose > 1440 && $nowMinutes < $previousClose - 1440) {
                $result['open'] = true;
                $result['reason'] = 'open';
                $result['closes_at'] = self::formatMinutes($previousClose);

                return $result;
            }
        }

        if ($today['closed']) {
            $result['open'] = false;
            $result['reason'] = 'closed_day';
            $result['opens_at'] = self::nextOpening($openingHours, $now, $dayIndex, 1);

            return $result;
        }

        $start = $today['start'];
        $end = $today['end'];
        if ($end <= $start) {
            $end += 1440;
        }

        if ($nowMinutes < $start) {
            $result['open'] = false;
            $result['reason'] = 'before_open';
            $result['opens_at'] = [
                'date' => $now->format('Y-m-d'),
                'day' => $todayKey,
                'time' => self::formatMinutes($start),
                'in_minutes' => $start - $nowMinutes,
            ];

            return $result;
        }

        if ($nowMinutes < $end) {
            $result['open'] = true;
            $result['reason'] = 'open';
            $result['closes_at'] = self::formatMinutes($end);

            return $result;
        }

        $result['open'] = false;
        $result['reason'] = 'after_close';
        $result['opens_at'] = self::nextOpening($openingHours, $now, $dayIndex, 1);

        return $result;
    }

    /** @param array<string, array<string, mixed>> $openingHours */
    private static function nextOpening(array $openingHours, DateTimeImmutable $now, int $dayIndex, int $offset): ?array
    {
        for ($step = $offset; $step <= $offset + 7; $step++) {
            $index = ($dayIndex + $step) % 7;
            $schedule = self::schedule($openingHours, self::DAY_KEYS[$index]);
            if ($schedule['closed']) {
                continue;
            }

            $date = $now->modify('+' . $step . ' days');

            return [
                'date' => $date->format('Y-m-d'),
                'day' => self::DAY_KEYS[$index],
                'time' => self::formatMinutes($schedule['start']),
                'in_minutes' => $step === 0 ? 0 : $step * 1440,
            ];
        }

        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $openingHours
     * @return array{open: string, close: string, closed: bool, start: int, end: int}
     */
    private static function schedule(array $openingHours, string $dayKey): array
    {
        $entry = $openingHours[$dayKey] ?? null;
        $closed = !is_array($entry) || !empty($entry['closed']);

        $open = is_array($entry) && isset($entry['open']) ? (string) $entry['open'] : '';
        $close = is_array($entry) && isset($entry['close']) ? (string) $entry['close'] : '';

        if ($open === '' || $close === '' || !preg_match('/^\d{2}:\d{2}$/', substr($open, 0, 5)) || !preg_match('/^\d{2}:\d{2}$/', substr($close, 0, 5))) {
            $closed = true;
        }

        $start = $closed ? 0 : ReservationCapacity::toMinutes(substr($open, 0, 5));
        $end = $closed ? 0 : ReservationCapacity::toMinutes(substr($close, 0, 5));

        return [
            'open' => $closed ? '' : substr($open, 0, 5),
            'close' => $closed ? '' : substr($close, 0, 5),
            'closed' => $closed,
            'start' => $start,
            'end' => $end,
        ];
    }

    private static function formatMinutes(int $minutes): string
    {
        $minutes %= 1440;

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    private static function timezone(string $timezone): DateTimeZone
    {
        try {
            return new DateTimeZone($timezone);
        } catch (\Throwable) {
            return new DateTimeZone('UTC');
        }
    }
}
