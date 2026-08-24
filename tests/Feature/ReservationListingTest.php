<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ReservationQueryService;
use App\Services\ReservationService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReservationListingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->user = User::whereEmail('test@example.com')->firstOrFail();
    }

    public function test_el_listado_se_resuelve_en_una_sola_consulta(): void
    {
        $service = app(ReservationService::class);

        $service->create($this->user, CarbonImmutable::parse('2026-08-25 20:00:00'), 12);
        $service->create($this->user, CarbonImmutable::parse('2026-08-25 21:00:00'), 4);
        $service->create($this->user, CarbonImmutable::parse('2026-08-26 20:00:00'), 4);

        DB::enableQueryLog();
        app(ReservationQueryService::class)->listByDate('2026-08-25');
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $queryCount);
    }

    public function test_agrupa_por_ubicacion_e_incluye_las_mesas_de_cada_reserva(): void
    {
        $service = app(ReservationService::class);

        $service->create($this->user, CarbonImmutable::parse('2026-08-25 20:00:00'), 18);
        $service->create($this->user, CarbonImmutable::parse('2026-08-25 21:00:00'), 4);

        $rows = app(ReservationQueryService::class)->listByDate('2026-08-25');

        $this->assertCount(2, $rows);

        $first = $rows[0];
        $this->assertSame('A', $first->location);
        $this->assertSame('Test User', $first->cliente);
        $this->assertSame(18, (int) $first->capacidad_total);
        $this->assertSame('4,7,8', $first->mesas);

        $second = $rows[1];
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(1, (int) $second->cantidad_mesas);
    }

    public function test_ordena_por_ubicacion_y_hora(): void
    {
        $service = app(ReservationService::class);

        $firstInA = $service->create($this->user, CarbonImmutable::parse('2026-08-25 10:00:00'), 18);
        // A ya no puede armar 3 mesas para 18: la siguiente ubicacion es B
        $inB = $service->create($this->user, CarbonImmutable::parse('2026-08-25 11:00:00'), 18);
        $laterInA = $service->create($this->user, CarbonImmutable::parse('2026-08-25 22:00:00'), 2);

        $rows = app(ReservationQueryService::class)->listByDate('2026-08-25');

        $this->assertSame([$firstInA->id, $laterInA->id, $inB->id], $rows->pluck('id')->all());
        $this->assertSame(['A', 'A', 'B'], $rows->pluck('location')->all());
    }

    public function test_endpoint_requiere_fecha_valida(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/reservations?date=ayer')
            ->assertInvalid(['date']);
    }
}
