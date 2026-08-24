<?php

namespace App\Services;

use App\Models\Table;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Consulta de disponibilidad por ubicacion (punto c).
 *
 * Solapamiento de intervalos: una mesa esta ocupada cuando existe una reserva
 * con  exists.starts_at < ventana_fin  AND  exists.ends_at > ventana_inicio.
 * Los extremos que se tocan NO solapan (una reserva que termina 22:00 libera
 * la mesa para otra que empieza 22:00).
 *
 * La deteccion usa un anti-join con NOT EXISTS correlacionado: para cada mesa
 * de la ubicacion pregunta si tiene reservas superpuestas. Corre con
 * lockForUpdate() DENTRO de la transaccion del punto c para que dos pedidos
 * simultaneos no puedan elegir la misma mesa.
 *
 * Cache en memoria (driver array de Laravel, pedido por el enunciado):
 *  - Marca por ubicacion+ventana+cantidad de personas cuando ya se sabe que
 *    no hay combinacion viable, para saltearse la consulta bloqueante en
 *    intentos siguientes. El people_count en la clave es obligatorio: un
 *    "30 personas no entran en A" no implica lo mismo para 2 personas.
 *  - Se invalida al confirmar la reserva (flushLocation).
 *  - Es coherente porque en este MVP no existen cancelaciones: el conjunto de
 *    mesas libres solo puede ACHICARSE. Si se agregaran cancelaciones habria
 *    que invalidar tambien en ese flujo.
 */
class AvailabilityService
{
    private const CACHE_PREFIX = 'no_fit:';

    private const KEYS_INDEX_PREFIX = 'no_fit_keys:';

    private const CACHE_TTL_SECONDS = 60;

    /**
     * Mesas LIBRES de una ubicacion para la ventana indicada, ordenadas por
     * capacidad ascendente y bloqueadas contra escrituras concurrentes.
     *
     * @return Collection<int, Table>
     */
    public function availableTables(string $location, CarbonInterface $startsAt, CarbonInterface $endsAt): Collection
    {
        return Table::query()
            ->where('tables.location', $location)
            ->whereNotExists(function ($query) use ($startsAt, $endsAt) {
                $query->selectRaw(1)
                    ->from('reservation_table as rt')
                    ->join('reservations as r', 'r.id', '=', 'rt.reservation_id')
                    ->whereColumn('rt.table_id', 'tables.id')
                    ->where('r.starts_at', '<', $endsAt)
                    ->where('r.ends_at', '>', $startsAt);
            })
            ->orderBy('capacity')
            ->lockForUpdate()
            ->get();
    }

    public function hasKnownNoFit(string $location, CarbonInterface $startsAt, int $peopleCount): bool
    {
        return Cache::store('array')->has($this->key($location, $startsAt, $peopleCount));
    }

    public function markNoFit(string $location, CarbonInterface $startsAt, int $peopleCount): void
    {
        $store = Cache::store('array');
        $store->put($this->key($location, $startsAt, $peopleCount), true, self::CACHE_TTL_SECONDS);

        $index = (array) $store->get(self::KEYS_INDEX_PREFIX.$location, []);
        $index[] = $this->key($location, $startsAt, $peopleCount);
        $store->put(self::KEYS_INDEX_PREFIX.$location, array_unique($index), self::CACHE_TTL_SECONDS);
    }

    public function flushLocation(string $location): void
    {
        $store = Cache::store('array');

        foreach ((array) $store->pull(self::KEYS_INDEX_PREFIX.$location, []) as $key) {
            $store->forget($key);
        }
    }

    /**
     * Dadas las mesas disponibles y la cantidad de personas, devuelve la mejor
     * combinacion o null si ninguna entra en los limites.
     *
     * Criterio:
     *  1. Minima cantidad de mesas posible (se prueba 1, luego 2, luego 3...).
     *  2. A igual cantidad de mesas, minimiza el desperdicio (capacidad sobrante).
     *  3. Corte temprano si encuentra ajuste perfecto (desperdicio 0).
     *
     * Las mesas llegan ordenadas por capacidad ascendente, lo que permite
     * podar: si incluso sumando las mas grandes no se cubre la gente, corta.
     */
    public function findBestTableCombination(Collection $tables, int $peopleCount): ?Collection
    {
        $candidates = $tables->values()->all();
        $maxTables = (int) config('reservas.max_tables_per_reservation');
        $limit = min($maxTables, count($candidates));

        for ($size = 1; $size <= $limit; $size++) {
            $best = $this->bestSubset($candidates, $size, $peopleCount);

            if ($best !== null) {
                return collect($best);
            }
        }

        return null;
    }

    /**
     * Busca entre las combinaciones de exactamente $size mesas la de menor
     * desperdicio cuya capacidad sumada cubra $peopleCount.
     *
     * @param  list<Table>  $candidates  ordenados por capacidad ascendente
     * @return list<Table>|null
     */
    private function bestSubset(array $candidates, int $size, int $peopleCount): ?array
    {
        // Poda rapida: las $size mesas mas grandes son el techo alcanzable.
        $topCapacity = array_sum(array_map(
            fn (Table $t) => $t->capacity,
            array_slice($candidates, -$size),
        ));

        if ($topCapacity < $peopleCount) {
            return null;
        }

        $best = null;
        $bestWaste = PHP_INT_MAX;
        $chosen = [];

        $dfs = function (int $start, int $sum, int $depth) use (
            &$dfs, &$best, &$bestWaste, &$chosen, $candidates, $size, $peopleCount,
        ): void {
            if ($depth === $size) {
                if ($sum >= $peopleCount && $sum - $peopleCount < $bestWaste) {
                    $bestWaste = $sum - $peopleCount;
                    $best = array_map(fn (int $i) => $candidates[$i], $chosen);
                }

                return;
            }

            $remainingSlots = $size - $depth;

            for ($i = $start, $n = count($candidates); $i <= $n - $remainingSlots; $i++) {
                $chosen[] = $i;
                ($dfs)($i + 1, $sum + $candidates[$i]->capacity, $depth + 1);
                array_pop($chosen);

                if ($bestWaste === 0) {
                    return;
                }
            }
        };

        $dfs(0, 0, 0);

        return $best;
    }

    private function key(string $location, CarbonInterface $startsAt, int $peopleCount): string
    {
        return self::CACHE_PREFIX.$location.'.'.$startsAt->getTimestamp().'.'.$peopleCount;
    }
}
