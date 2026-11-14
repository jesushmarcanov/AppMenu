<?php

declare(strict_types=1);

use App\Services\OrderAvailability;

$hours = [
    'monday' => ['open' => '12:00', 'close' => '22:00', 'closed' => false],
    'tuesday' => ['open' => '12:00', 'close' => '22:00', 'closed' => false],
    'wednesday' => ['open' => '12:00', 'close' => '22:00', 'closed' => false],
    'thursday' => ['open' => '12:00', 'close' => '22:00', 'closed' => false],
    'friday' => ['open' => '12:00', 'close' => '23:00', 'closed' => false],
    'saturday' => ['open' => '12:00', 'close' => '23:00', 'closed' => false],
    'sunday' => ['open' => '12:00', 'close' => '16:00', 'closed' => true],
];

$zone = new DateTimeZone('America/Caracas');
$at = static fn (string $moment): DateTimeImmutable => new DateTimeImmutable($moment, $zone);

return [
    'OrderAvailability: abierto dentro del horario' => static function ($t) use ($hours, $at): void {
        $result = OrderAvailability::evaluate($hours, 'America/Caracas', true, $at('2026-11-02 13:00:00'));

        $t->true($result['open']);
        $t->same('open', $result['reason']);
        $t->same('22:00', $result['closes_at']);
        $t->same('monday', $result['weekday']);
        $t->same('2026-11-02T13:00:00-04:00', $result['now']);
    },

    'OrderAvailability: antes de abrir calcula la próxima apertura' => static function ($t) use ($hours, $at): void {
        $result = OrderAvailability::evaluate($hours, 'America/Caracas', true, $at('2026-11-02 11:00:00'));

        $t->false($result['open']);
        $t->same('before_open', $result['reason']);
        $t->same('2026-11-02', $result['opens_at']['date']);
        $t->same('12:00', $result['opens_at']['time']);
        $t->same(60, $result['opens_at']['in_minutes']);
    },

    'OrderAvailability: día cerrado salta al siguiente día abierto' => static function ($t) use ($hours, $at): void {
        $result = OrderAvailability::evaluate($hours, 'America/Caracas', true, $at('2026-11-01 13:00:00'));

        $t->false($result['open']);
        $t->same('closed_day', $result['reason']);
        $t->true($result['today']['closed']);
        $t->same('2026-11-02', $result['opens_at']['date']);
        $t->same('monday', $result['opens_at']['day']);
        $t->same('12:00', $result['opens_at']['time']);
    },

    'OrderAvailability: después de cerrar salta al día siguiente' => static function ($t) use ($hours, $at): void {
        $result = OrderAvailability::evaluate($hours, 'America/Caracas', true, $at('2026-11-02 23:00:00'));

        $t->false($result['open']);
        $t->same('after_close', $result['reason']);
        $t->same('2026-11-03', $result['opens_at']['date']);
        $t->same('tuesday', $result['opens_at']['day']);
        $t->same('12:00', $result['opens_at']['time']);
    },

    'OrderAvailability: turno de ayer que cruza medianoche' => static function ($t) use ($at): void {
        $overnight = [
            'monday' => ['open' => '18:00', 'close' => '01:00', 'closed' => false],
            'tuesday' => ['open' => '18:00', 'close' => '01:00', 'closed' => false],
        ];

        $result = OrderAvailability::evaluate($overnight, 'America/Caracas', true, $at('2026-11-03 00:30:00'));

        $t->true($result['open']);
        $t->same('open', $result['reason']);
        $t->same('01:00', $result['closes_at']);
    },

    'OrderAvailability: respeta la opción de ignorar el horario' => static function ($t) use ($hours, $at): void {
        $result = OrderAvailability::evaluate($hours, 'America/Caracas', false, $at('2026-11-01 03:00:00'));

        $t->true($result['open']);
        $t->same('always_open', $result['reason']);
        $t->false($result['respect_hours']);
    },

    'OrderAvailability: sin horarios configurados siempre abre' => static function ($t) use ($at): void {
        $result = OrderAvailability::evaluate([], 'America/Caracas', true, $at('2026-11-01 03:00:00'));

        $t->true($result['open']);
        $t->same('always_open', $result['reason']);
    },

    'OrderAvailability: un horario malformado se trata como cerrado' => static function ($t) use ($at): void {
        $result = OrderAvailability::evaluate([
            'monday' => ['open' => 'abierto', 'close' => '22:00', 'closed' => false],
        ], 'America/Caracas', true, $at('2026-11-02 13:00:00'));

        $t->false($result['open']);
        $t->same('closed_day', $result['reason']);
    },

    'OrderAvailability: zona horaria inválida cae a UTC' => static function ($t) use ($hours): void {
        $result = OrderAvailability::evaluate($hours, 'Marte/Olympus', true);

        $t->same('UTC', $result['timezone']);
    },
];
