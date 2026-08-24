<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Listado de reservas por fecha (punto d).
 *
 * UNA SOLA consulta SQL: JOINs contra la pivot y las mesas, agregacion con
 * GROUP_CONCAT para traer los numeros de mesa en la misma fila que la reserva.
 * Es la respuesta al clasico problema N+1 (una query por reserva para sus
 * mesas) y demuestra que el modelo pivot esta correctamente indexado.
 *
 * El filtro usa reservation_date (columna generada = DATE(starts_at),
 * indexada) en vez de DATE(starts_at), que no podria usar el indice.
 */
class ReservationQueryService
{
    /**
     * Reservas de una fecha con datos del cliente, ubicacion, mesas unidas y
     * capacidad total, ordenadas por ubicacion y hora.
     *
     * Suposicion documentada: el enunciado pide agrupar "por ubicacion y
     * seccion"; como "seccion" no esta definida, se interpreta como la
     * ubicacion misma (A-D).
     *
     * @return Collection<int, object>
     */
    public function listByDate(string $date): Collection
    {
        $rows = DB::select(<<<'SQL'
            SELECT
                r.id,
                u.name            AS cliente,
                r.people_count,
                r.starts_at,
                r.ends_at,
                t.location,
                COUNT(t.id)                                       AS cantidad_mesas,
                GROUP_CONCAT(t.number ORDER BY t.number)           AS mesas,
                CAST(SUM(t.capacity) AS UNSIGNED)                 AS capacidad_total
            FROM reservations r
            INNER JOIN users u              ON u.id  = r.user_id
            INNER JOIN reservation_table rt ON rt.reservation_id = r.id
            INNER JOIN tables t             ON t.id  = rt.table_id
            WHERE r.reservation_date = :date
            GROUP BY
                r.id, u.name, r.people_count, r.starts_at, r.ends_at, t.location
            ORDER BY t.location ASC, r.starts_at ASC
        SQL, ['date' => $date]);

        return collect($rows);
    }
}
