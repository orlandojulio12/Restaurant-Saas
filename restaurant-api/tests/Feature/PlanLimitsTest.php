<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlanLimitsTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;
    private Category $categoria;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-16 12:00', 'America/Bogota')->utc());

        // Plan gratuito: cupos ajustados y ningún módulo activado.
        $free = Plan::create([
            'name' => 'free', 'display_name' => 'Gratis',
            'max_tables' => 2, 'max_products' => 2, 'max_daily_orders' => 2,
            'has_inventory' => false, 'has_reports' => false,
            'has_financials' => false, 'has_whatsapp' => false,
        ]);

        $this->restaurant = Restaurant::create([
            'plan_id' => $free->id, 'name' => 'Mío', 'slug' => 'mio', 'timezone' => 'America/Bogota',
        ]);

        Sanctum::actingAs(User::create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Admin', 'email' => 'admin@test.com',
            'password' => 'password', 'role' => 'admin',
        ]));

        $this->categoria = Category::create([
            'restaurant_id' => $this->restaurant->id, 'name' => 'Platos',
        ]);
    }

    public function test_corta_al_llegar_al_cupo_de_productos(): void
    {
        $this->crearProducto('Uno')->assertCreated();
        $this->crearProducto('Dos')->assertCreated();

        $this->crearProducto('Tres')
            ->assertStatus(403)
            ->assertJsonPath('resource', 'products')
            ->assertJsonPath('limit', 2)
            ->assertJsonPath('current', 2);
    }

    public function test_corta_al_llegar_al_cupo_de_mesas(): void
    {
        $this->crearMesa('1')->assertCreated();
        $this->crearMesa('2')->assertCreated();

        $this->crearMesa('3')
            ->assertStatus(403)
            ->assertJsonPath('resource', 'tables');

        $this->assertSame(2, RestaurantTable::count());
    }

    public function test_corta_al_llegar_al_cupo_de_pedidos_diarios(): void
    {
        $producto = Product::create([
            'restaurant_id' => $this->restaurant->id,
            'category_id'   => $this->categoria->id,
            'name'          => 'Bandeja', 'price' => 25000,
        ]);

        $this->crearPedido($producto)->assertCreated();
        $this->crearPedido($producto)->assertCreated();

        $this->crearPedido($producto)
            ->assertStatus(403)
            ->assertJsonPath('resource', 'daily_orders');
    }

    public function test_un_pedido_cancelado_no_consume_cupo(): void
    {
        $producto = Product::create([
            'restaurant_id' => $this->restaurant->id,
            'category_id'   => $this->categoria->id,
            'name'          => 'Bandeja', 'price' => 25000,
        ]);

        $primero = $this->crearPedido($producto)->assertCreated();
        $this->deleteJson("/api/orders/{$primero->json('id')}")->assertNoContent();  // lo cancela

        $this->crearPedido($producto)->assertCreated();
        $this->crearPedido($producto)->assertCreated();
    }

    public function test_cupo_cero_significa_ilimitado(): void
    {
        $pro = Plan::create([
            'name' => 'pro', 'display_name' => 'Pro',
            'max_tables' => 0, 'max_products' => 0, 'max_daily_orders' => 0,
        ]);
        $this->restaurant->update(['plan_id' => $pro->id]);

        foreach (range(1, 5) as $i) {
            $this->crearProducto("P{$i}")->assertCreated();
        }

        $this->assertSame(5, Product::count());
    }

    public function test_los_modulos_de_plan_responden_403(): void
    {
        $this->getJson('/api/inventory/alerts')->assertStatus(403)->assertJsonPath('feature', 'inventory');
        $this->getJson('/api/ingredients')->assertStatus(403);
        $this->getJson('/api/reports/daily')->assertStatus(403)->assertJsonPath('feature', 'reports');
        $this->getJson('/api/financial/dashboard')->assertStatus(403)->assertJsonPath('feature', 'financials');
        $this->getJson('/api/fixed-costs')->assertStatus(403);
    }

    public function test_con_el_plan_pro_los_modulos_se_abren(): void
    {
        $pro = Plan::create([
            'name' => 'pro', 'display_name' => 'Pro',
            'has_inventory' => true, 'has_reports' => true, 'has_financials' => true,
        ]);
        $this->restaurant->update(['plan_id' => $pro->id]);

        $this->getJson('/api/inventory/alerts')->assertOk();
        $this->getJson('/api/reports/daily')->assertOk();
        $this->getJson('/api/financial/dashboard')->assertOk();
        $this->getJson('/api/fixed-costs')->assertOk();
    }

    private function crearProducto(string $nombre)
    {
        return $this->postJson('/api/products', [
            'category_id' => $this->categoria->id,
            'name'        => $nombre,
            'price'       => 1000,
        ]);
    }

    private function crearMesa(string $numero)
    {
        return $this->postJson('/api/tables', [
            'number'   => $numero,
            'capacity' => 4,
        ]);
    }

    private function crearPedido(Product $producto)
    {
        return $this->postJson('/api/orders', [
            'type'  => 'counter',
            'items' => [['product_id' => $producto->id, 'quantity' => 1]],
        ]);
    }
}
