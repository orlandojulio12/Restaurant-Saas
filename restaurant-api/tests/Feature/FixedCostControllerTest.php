<?php

namespace Tests\Feature;

use App\Models\FixedCost;
use App\Models\Plan;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FixedCostControllerTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;
    private Restaurant $otro;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = Plan::create(['name' => 'pro', 'display_name' => 'Pro', 'has_financials' => true]);

        $this->restaurant = Restaurant::create([
            'plan_id' => $plan->id, 'name' => 'Mío', 'slug' => 'mio', 'timezone' => 'America/Bogota',
        ]);

        $this->otro = Restaurant::create([
            'plan_id' => $plan->id, 'name' => 'Ajeno', 'slug' => 'ajeno', 'timezone' => 'America/Bogota',
        ]);

        Sanctum::actingAs(User::create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Admin', 'email' => 'admin@test.com',
            'password' => 'password', 'role' => 'admin',
        ]));
    }

    public function test_crea_un_costo_fijo(): void
    {
        $response = $this->postJson('/api/fixed-costs', [
            'name'      => 'Arriendo',
            'amount'    => 2000000,
            'category'  => 'rent',
            'frequency' => 'monthly',
        ])->assertCreated();

        $this->assertSame('Arriendo', $response->json('name'));
        $this->assertSame($this->restaurant->id, $response->json('restaurant_id'));
    }

    public function test_admite_las_frecuencias_ampliadas(): void
    {
        foreach (['daily', 'weekly', 'biweekly', 'monthly', 'quarterly', 'yearly'] as $frecuencia) {
            $this->postJson('/api/fixed-costs', [
                'name'      => "Costo {$frecuencia}",
                'amount'    => 1000,
                'frequency' => $frecuencia,
            ])->assertCreated();
        }

        $this->assertDatabaseCount('fixed_costs', 6);
    }

    public function test_rechaza_una_frecuencia_invalida(): void
    {
        $this->postJson('/api/fixed-costs', [
            'name' => 'Raro', 'amount' => 1000, 'frequency' => 'cada-luna-llena',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('frequency');
    }

    /**
     * El caso que estaba mal: 'daily' existía en el enum pero no en el cálculo,
     * así que un costo diario se sumaba como si fuera mensual.
     */
    public function test_normaliza_un_costo_diario_a_su_equivalente_mensual(): void
    {
        $costo = FixedCost::create([
            'restaurant_id' => $this->restaurant->id,
            'name'          => 'Hielo',
            'amount'        => 50000,
            'frequency'     => 'daily',
        ]);

        // 50.000 × 365/12 ≈ 1.520.835, no 50.000.
        $this->assertEqualsWithDelta(1520835, $costo->monthlyAmount(), 1);
    }

    public function test_normaliza_cada_frecuencia_al_mes(): void
    {
        $casos = [
            'daily'     => 30.4167,
            'weekly'    => 4.3333,
            'biweekly'  => 2.0,
            'monthly'   => 1.0,
            'quarterly' => 0.3333,
            'yearly'    => 0.0833,
        ];

        foreach ($casos as $frecuencia => $factor) {
            $costo = FixedCost::create([
                'restaurant_id' => $this->restaurant->id,
                'name'          => "C-{$frecuencia}",
                'amount'        => 1200,
                'frequency'     => $frecuencia,
            ]);

            $this->assertEqualsWithDelta(1200 * $factor, $costo->monthlyAmount(), 0.5, "Falla {$frecuencia}");
        }
    }

    public function test_el_listado_devuelve_el_total_mensual_normalizado(): void
    {
        $this->costo('Arriendo', 2000000, 'monthly');
        $this->costo('Aseo', 100000, 'weekly');          // ×4.3333 ≈ 433.330
        $this->costo('Inactivo', 999999, 'monthly', activo: false);

        $response = $this->getJson('/api/fixed-costs')->assertOk();

        $this->assertCount(3, $response->json('data'));
        // Solo los activos entran en el total.
        $this->assertEqualsWithDelta(2433330, $response->json('monthly_total'), 5);
    }

    public function test_el_punto_de_equilibrio_usa_la_misma_normalizacion(): void
    {
        $this->costo('Arriendo', 2000000, 'monthly');
        $this->costo('Hielo', 50000, 'daily');

        $response = $this->getJson('/api/financial/breakeven')->assertOk();

        $this->assertEqualsWithDelta(3520835, $response->json('monthly_fixed_costs'), 5);
    }

    public function test_filtra_por_categoria_y_estado(): void
    {
        $this->costo('Arriendo', 2000000, 'monthly', categoria: 'rent');
        $this->costo('Nómina', 3000000, 'monthly', categoria: 'staff');

        $this->getJson('/api/fixed-costs?category=rent')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/fixed-costs')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_actualiza_y_borra(): void
    {
        $costo = $this->costo('Arriendo', 2000000, 'monthly');

        $this->putJson("/api/fixed-costs/{$costo->id}", ['amount' => 2500000])
            ->assertOk()
            ->assertJsonPath('amount', '2500000.00');

        $this->deleteJson("/api/fixed-costs/{$costo->id}")->assertNoContent();
        $this->assertDatabaseMissing('fixed_costs', ['id' => $costo->id]);
    }

    public function test_no_deja_tocar_costos_de_otro_restaurante(): void
    {
        $ajeno = FixedCost::create([
            'restaurant_id' => $this->otro->id, 'name' => 'Ajeno', 'amount' => 1000,
        ]);

        $this->getJson("/api/fixed-costs/{$ajeno->id}")->assertForbidden();
        $this->putJson("/api/fixed-costs/{$ajeno->id}", ['amount' => 1])->assertForbidden();
        $this->deleteJson("/api/fixed-costs/{$ajeno->id}")->assertForbidden();
    }

    private function costo(
        string $nombre,
        float $monto,
        string $frecuencia,
        string $categoria = 'other',
        bool $activo = true,
    ): FixedCost {
        return FixedCost::create([
            'restaurant_id' => $this->restaurant->id,
            'name'          => $nombre,
            'amount'        => $monto,
            'frequency'     => $frecuencia,
            'category'      => $categoria,
            'is_active'     => $activo,
        ]);
    }
}
