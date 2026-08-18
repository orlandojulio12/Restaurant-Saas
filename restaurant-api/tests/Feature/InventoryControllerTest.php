<?php

namespace Tests\Feature;

use App\Events\LowStockAlert;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\Plan;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryControllerTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;
    private Ingredient $arroz;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = Plan::create(['name' => 'pro', 'display_name' => 'Pro', 'has_inventory' => true]);

        $this->restaurant = Restaurant::create([
            'plan_id' => $plan->id, 'name' => 'Mío', 'slug' => 'mio', 'timezone' => 'America/Bogota',
        ]);

        Sanctum::actingAs(User::create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Admin', 'email' => 'admin@test.com',
            'password' => 'password', 'role' => 'admin',
        ]));

        $this->arroz = Ingredient::create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Arroz', 'unit' => 'kg',
            'stock' => 10, 'min_stock' => 3, 'cost_per_unit' => 3000,
        ]);
    }

    public function test_una_entrada_suma_stock(): void
    {
        $this->postJson("/api/ingredients/{$this->arroz->id}/movement", [
            'type' => 'in', 'quantity' => 5, 'reason' => 'Compra',
        ])->assertCreated()
            ->assertJsonPath('movement.type', 'in')
            ->assertJsonPath('movement.stock_before', '10.000')
            ->assertJsonPath('movement.stock_after', '15.000');

        $this->assertEquals(15, $this->arroz->fresh()->stock);
    }

    public function test_una_salida_resta_stock(): void
    {
        $this->postJson("/api/ingredients/{$this->arroz->id}/movement", [
            'type' => 'out', 'quantity' => 4,
        ])->assertCreated();

        $this->assertEquals(6, $this->arroz->fresh()->stock);
    }

    public function test_una_merma_resta_stock(): void
    {
        $this->postJson("/api/ingredients/{$this->arroz->id}/movement", [
            'type' => 'waste', 'quantity' => 2, 'reason' => 'Se dañó',
        ])->assertCreated();

        $this->assertEquals(8, $this->arroz->fresh()->stock);
    }

    public function test_una_salida_mayor_al_stock_lo_deja_en_cero(): void
    {
        $this->postJson("/api/ingredients/{$this->arroz->id}/movement", [
            'type' => 'out', 'quantity' => 999,
        ])->assertCreated();

        $this->assertEquals(0, $this->arroz->fresh()->stock);
    }

    public function test_un_ajuste_fija_el_stock_contado_y_registra_la_diferencia(): void
    {
        $this->postJson("/api/ingredients/{$this->arroz->id}/movement", [
            'type' => 'adjustment', 'new_stock' => 7.5, 'reason' => 'Conteo físico',
        ])->assertCreated()
            ->assertJsonPath('movement.stock_before', '10.000')
            ->assertJsonPath('movement.stock_after', '7.500')
            ->assertJsonPath('movement.quantity', '2.500');   // la diferencia observada

        $this->assertEquals(7.5, $this->arroz->fresh()->stock);
    }

    public function test_el_ajuste_exige_new_stock_y_los_demas_exigen_quantity(): void
    {
        $this->postJson("/api/ingredients/{$this->arroz->id}/movement", ['type' => 'adjustment'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('new_stock');

        $this->postJson("/api/ingredients/{$this->arroz->id}/movement", ['type' => 'in'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quantity');
    }

    public function test_rechaza_cantidades_no_positivas(): void
    {
        $this->postJson("/api/ingredients/{$this->arroz->id}/movement", ['type' => 'in', 'quantity' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quantity');
    }

    public function test_emite_alerta_al_cruzar_el_minimo_hacia_abajo(): void
    {
        Event::fake([LowStockAlert::class]);

        $this->postJson("/api/ingredients/{$this->arroz->id}/movement", [
            'type' => 'out', 'quantity' => 8,     // 10 → 2, por debajo del mínimo de 3
        ])->assertCreated();

        Event::assertDispatched(LowStockAlert::class);
    }

    public function test_una_entrada_no_emite_alerta_aunque_siga_bajo_minimo(): void
    {
        Event::fake([LowStockAlert::class]);

        $this->arroz->update(['stock' => 1]);   // ya está bajo mínimo

        $this->postJson("/api/ingredients/{$this->arroz->id}/movement", [
            'type' => 'in', 'quantity' => 1,    // 1 → 2, sigue bajo pero va mejorando
        ])->assertCreated();

        Event::assertNotDispatched(LowStockAlert::class);
    }

    public function test_lista_las_alertas_ordenando_lo_mas_critico_primero(): void
    {
        $this->arroz->update(['stock' => 3, 'min_stock' => 3]);       // ratio 1.0
        Ingredient::create([
            'restaurant_id' => $this->restaurant->id, 'name' => 'Sal', 'unit' => 'kg',
            'stock' => 0, 'min_stock' => 5,                            // ratio 0 → más crítico
        ]);
        Ingredient::create([
            'restaurant_id' => $this->restaurant->id, 'name' => 'Aceite', 'unit' => 'l',
            'stock' => 100, 'min_stock' => 5,                          // sano
        ]);

        $response = $this->getJson('/api/inventory/alerts')->assertOk();

        $this->assertSame(2, $response->json('count'));
        $this->assertSame('Sal', $response->json('data.0.name'));
        $this->assertSame('Arroz', $response->json('data.1.name'));
    }

    public function test_el_historial_de_movimientos_filtra_por_ingrediente_y_tipo(): void
    {
        $this->postJson("/api/ingredients/{$this->arroz->id}/movement", ['type' => 'in', 'quantity' => 5]);
        $this->postJson("/api/ingredients/{$this->arroz->id}/movement", ['type' => 'out', 'quantity' => 2]);

        $this->getJson('/api/inventory/movements')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/inventory/movements?type=in')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_el_modulo_responde_403_si_el_plan_no_incluye_inventario(): void
    {
        $free = Plan::create(['name' => 'free', 'display_name' => 'Gratis', 'has_inventory' => false]);
        $this->restaurant->update(['plan_id' => $free->id]);

        $this->getJson('/api/inventory/alerts')->assertStatus(403);
        $this->postJson("/api/ingredients/{$this->arroz->id}/movement", ['type' => 'in', 'quantity' => 1])
            ->assertStatus(403);

        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_no_deja_mover_stock_de_otro_restaurante(): void
    {
        $plan  = Plan::first();
        $otro  = Restaurant::create([
            'plan_id' => $plan->id, 'name' => 'Ajeno', 'slug' => 'ajeno', 'timezone' => 'America/Bogota',
        ]);
        $ajeno = Ingredient::create([
            'restaurant_id' => $otro->id, 'name' => 'Ajeno', 'unit' => 'kg', 'stock' => 10,
        ]);

        $this->postJson("/api/ingredients/{$ajeno->id}/movement", ['type' => 'in', 'quantity' => 1])
            ->assertForbidden();

        $this->assertEquals(10, $ajeno->fresh()->stock);
    }
}
