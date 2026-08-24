<?php

namespace App\Services;

use App\Exceptions\ReservationException;
use App\Models\Reservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Orquesta la creacion de una reserva (punto c).
 *
 * Flujo:
 *  1. Validar horario de apertura y anticipacion minima (fuera de transaccion:
 *     son rechazos baratos que no necesitan locks).
 *  2. Transaccion: recorrer ubicaciones en orden (A,B,C,D). Por cada una,
 *     consultar mesas libres bloqueadas (FOR UPDATE) y buscar la mejor
 *     combinacion de hasta 3 mesas.
 *  3. La PRIMERA ubicacion con combinacion viable gana; se persiste la
 *     reserva, se adjuntan sus mesas y se invalida el cache de la ubicacion.
 *  4. Si ninguna ubicacion sirve -> ReservationException::noAvailability()
 *     (HTTP 409) y rollback automatico del closure de DB::transaction().
 *
 * El lockForUpdate() del paso 2 garantiza exclusion mutua: un segundo pedido
 * simultaneo que intente leer las mismas mesas queda bloqueado hasta que el
 * primero confirme, y al re-ejecutar su consulta ya ve las filas nuevas.
 */
class ReservationService
{
    public function __construct(
        private readonly ScheduleValidator $scheduleValidator,
        private readonly AvailabilityService $availability,
    ) {}

    /**
     * @throws ReservationException
     */
    public function create(User $user, CarbonImmutable $startsAt, int $peopleCount): Reservation
    {
        $this->scheduleValidator->ensureWithinOpeningHours($startsAt);
        $this->scheduleValidator->ensureMinimumLeadTime($startsAt);

        $endsAt = $startsAt->addMinutes((int) config('reservas.duration_minutes'));

        // Dos transacciones con FOR UPDATE pueden chocar por gap locks de
        // InnoDB (SQLSTATE 40001). retry() reintenta SOLO deadlocks; otros
        // errores (ej. constraint) se propagan sin re-ejecutar.
        // El cache negativo sobrevive al rollback de un intento fallido, lo
        // cual es seguro: solo se marca "no fit", y tras rollback las mesas
        // libres no aumentan.
        $attempt = function () use ($user, $startsAt, $endsAt, $peopleCount): Reservation {
            return DB::transaction(function () use ($user, $startsAt, $endsAt, $peopleCount) {
                foreach (config('reservas.locations') as $location) {
                    if ($this->availability->hasKnownNoFit($location, $startsAt, $peopleCount)) {
                        continue;
                    }

                    $freeTables = $this->availability->availableTables($location, $startsAt, $endsAt);
                    $combination = $this->availability->findBestTableCombination($freeTables, $peopleCount);

                    if ($combination === null) {
                        $this->availability->markNoFit($location, $startsAt, $peopleCount);

                        continue;
                    }

                    $reservation = Reservation::create([
                        'user_id' => $user->id,
                        'starts_at' => $startsAt,
                        'ends_at' => $endsAt,
                        'people_count' => $peopleCount,
                    ]);

                    $reservation->tables()->attach($combination->pluck('id'));
                    $this->availability->flushLocation($location);

                    return $reservation->load('tables');
                }

                throw ReservationException::noAvailability();
            });
        };

        return retry(3, $attempt, 100, fn (\Throwable $e) => $e instanceof QueryException && $e->errorInfo[0] === '40001');
    }
}
