<?php

namespace App\Services;

use App\Exceptions\ReservationException;
use Carbon\CarbonImmutable;

/**
 * Valida las reglas de horario del punto c.
 *
 * Los horarios viven en config('reservas.schedule') expresados en MINUTOS
 * desde la medianoche del dia de apertura. Esa eleccion evita trabajar con
 * objetos fecha para el cierre del sabado (22:00 a 02:00 = cierre en minuto
 * 1560, o sea 02:00 del dia siguiente): comparar enteros no tiene casos borde.
 *
 * Reglas:
 *  - El inicio debe caer dentro de la ventana del dia.
 *  - Inicio + duracion estandar no puede exceder el cierre (suposicion
 *    documentada en el README).
 *  - Anticipacion minima de config('reservas.min_lead_minutes').
 */
class ScheduleValidator
{
    public function ensureWithinOpeningHours(CarbonImmutable $startsAt): void
    {
        $window = config('reservas.schedule')[$startsAt->dayOfWeekIso] ?? null;

        if ($window === null) {
            throw ReservationException::outsideOpeningHours();
        }

        $startMinutes = $startsAt->hour * 60 + $startsAt->minute;
        $endMinutes = $startMinutes + (int) config('reservas.duration_minutes');

        if ($startMinutes < $window['open'] || $endMinutes > $window['close']) {
            throw ReservationException::outsideOpeningHours();
        }
    }

    public function ensureMinimumLeadTime(CarbonImmutable $startsAt): void
    {
        $minLead = (int) config('reservas.min_lead_minutes');

        if (CarbonImmutable::now()->addMinutes($minLead)->gt($startsAt)) {
            throw ReservationException::insufficientLeadTime($minLead);
        }
    }
}
