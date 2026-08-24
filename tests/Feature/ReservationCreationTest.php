<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationCreationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->user = User::whereEmail('test@example.com')->firstOrFail();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_asigna_la_primera_ubicacion_disponible_por_orden(): void
    {
        // Martes 20:00 dentro del horario L-V (600..1440)
        $response = $this->reserve('2026-08-25', '20:00', 4);

        $response->assertCreated()
            ->assertJsonPath('data.location', 'A')
            ->assertJsonCount(1, 'data.tables');

        $this->assertSame(4, $response->json('data.tables.0.capacity'));
    }

    public function test_une_varias_mesas_cuando_una_sola_no_alcanza(): void
    {
        $response = $this->reserve('2026-08-25', '20:00', 12);

        $response->assertCreated()->assertJsonPath('data.location', 'A');

        $tables = $response->json('data.tables');
        $this->assertCount(2, $tables);
        $this->assertLessThanOrEqual(3, count($tables));

        $total = array_sum(array_column($tables, 'capacity'));
        $this->assertGreaterThanOrEqual(12, $total);
        $this->assertSame(12, $total);
    }

    public function test_nunca_supera_el_maximo_de_tres_mesas_y_responde_409_si_no_alcanza(): void
    {
        $topPerLocation = ['A' => 18, 'B' => 18, 'C' => 18, 'D' => 20];

        foreach ($topPerLocation as $people) {
            $response = $this->reserve('2026-08-26', '20:00', $people);
            $response->assertCreated();

            $reservationId = $response->json('data.id');
            $tableCount = Reservation::find($reservationId)->tables()->count();
            $this->assertLessThanOrEqual(3, $tableCount);
        }

        $impossibleWithThreeTables = 30;
        $this->reserve('2026-08-27', '20:00', $impossibleWithThreeTables)
            ->assertStatus(409)
            ->assertJsonPath('message', __('No hay disponibilidad para la cantidad de personas solicitada.'));
    }

    public function test_rechaza_horarios_fuera_de_atencion(): void
    {
        $this->reserve('2026-08-30', '17:00', 4)
            ->assertStatus(422);

        $this->reserve('2026-08-24', '09:00', 4)
            ->assertStatus(422);
    }

    public function test_viernes_a_las_23_excede_el_cierre_y_se_rechaza(): void
    {
        $this->reserve('2026-08-28', '23:00', 4)
            ->assertStatus(422);
    }

    public function test_sabado_puede_cruzar_la_medianoche(): void
    {
        $response = $this->reserve('2026-08-29', '23:30', 6);

        $response->assertCreated();
        $this->assertSame('2026-08-30T01:30:00.000000Z', $response->json('data.ends_at'));
    }

    public function test_exige_quince_minutos_de_anticipacion(): void
    {
        CarbonImmutable::setTestNow('2026-08-25 19:46:00');

        $this->reserve('2026-08-25', '20:00', 4)->assertStatus(422);

        CarbonImmutable::setTestNow('2026-08-25 19:44:00');

        $this->reserve('2026-08-25', '20:00', 4)->assertCreated();
    }

    public function test_solapamiento_en_agota_primera_ubicacion_y_cae_en_la_siguiente(): void
    {
        $this->reserve('2026-08-25', '20:00', 18)->assertCreated()->assertJsonPath('data.location', 'A');

        $this->reserve('2026-08-25', '21:00', 18)->assertCreated()->assertJsonPath('data.location', 'B');
    }

    public function test_reservas_solapadas_no_comparten_mesas(): void
    {
        $first = $this->reserve('2026-08-25', '20:00', 12)->json('data.tables');
        $second = $this->reserve('2026-08-25', '21:00', 4)->json('data.tables');

        $firstIds = array_column($first, 'id');
        $secondIds = array_column($second, 'id');

        $this->assertEmpty(array_intersect($firstIds, $secondIds));
    }

    public function test_requiere_autenticacion(): void
    {
        $this->postJson('/api/reservations', [
            'date' => '2026-08-25',
            'time' => '20:00',
            'people_count' => 4,
        ])->assertUnauthorized();
    }

    public function test_valida_formato_del_payload(): void
    {
        $this->actingAs($this->user)->postJson('/api/reservations', [
            'date' => '25-08-2026',
            'time' => '7pm',
            'people_count' => -1,
        ])->assertInvalid(['date', 'time', 'people_count']);
    }

    private function reserve(string $date, string $time, int $people)
    {
        return $this->actingAs($this->user)->postJson('/api/reservations', [
            'date' => $date,
            'time' => $time,
            'people_count' => $people,
        ]);
    }
}
