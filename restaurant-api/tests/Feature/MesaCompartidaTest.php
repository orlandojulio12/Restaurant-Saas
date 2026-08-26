<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\RestaurantSetting;
use App\Models\RestaurantTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Una mesa con varios comensales pidiendo desde sus propios móviles.
 *
 * El caso normal en un restaurante: cuatro personas se sientan, las cuatro
 * escanean el mismo adhesivo y cada una arma su pedido. No hay sesión de mesa
 * ni tabla nueva — la cuenta abierta son los pedidos activos de la mesa, y al
 * cobrarlos pasan a 'closed' y desaparecen solos.
 *
 * Lo que se prueba aquí es que esos pedidos se agrupen bien, que el comensal
 * pueda ver lo que ya pidió su mesa antes de repetir algo, y que la segunda
 * ronda pueda ser para llevar sin dejar de pertenecer a la mesa.
 */
class MesaCompartidaTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;
    private RestaurantTable $mesa;
    private RestaurantTable $otraMesa;
    private Product $producto;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = Plan::create(['name' => 'pro', 'display_name' => 'Pro', 'max_daily_orders' => 0]);

        $this->restaurant = Restaurant::create([
            'plan_id' => $plan->id, 'name' => 'El Rincón', 'slug' => 'el-rincon',
            'timezone' => 'America/Bogota', 'is_active' => true,
        ]);

        $categoria = Category::create([
            'restaurant_id' => $this->restaurant->id, 'name' => 'Platos', 'is_active' => true,
        ]);

        $this->producto = Product::create([
            'restaurant_id' => $this->restaurant->id, 'category_id' => $categoria->id,
            'name' => 'Bandeja Paisa', 'price' => 25000, 'is_available' => true,
        ]);

        $this->mesa = RestaurantTable::create([
            'restaurant_id' => $this->restaurant->id, 'number' => '5', 'capacity' => 4,
            'qr_code' => Str::uuid()->toString(), 'status' => 'available',
        ]);

        $this->otraMesa = RestaurantTable::create([
            'restaurant_id' => $this->restaurant->id, 'number' => '6', 'capacity' => 2,
            'qr_code' => Str::uuid()->toString(), 'status' => 'available',
        ]);

        // Sin confirmación del mesero: lo que se prueba aquí es el agrupado por
        // mesa, no la propuesta, que ya tiene su propio test.
        RestaurantSetting::create([
            'restaurant_id' => $this->restaurant->id, 'key_name' => 'qr_confirm', 'value' => '0',
        ]);
    }

    public function test_dos_comensales_de_la_misma_mesa_crean_pedidos_separados(): void
    {
        $ana  = $this->pedir(['diner_name' => 'Ana']);
        $luis = $this->pedir(['diner_name' => 'Luis']);

        $this->assertNotSame($ana->json('id'), $luis->json('id'));

        // Pedidos distintos, misma mesa: así la cocina saca dos comandas y la
        // caja cobra una sola cuenta.
        $this->assertSame(
            2,
            Order::where('table_id', $this->mesa->id)->whereIn('status', Order::ACTIVOS)->count(),
        );

        $this->assertSame('Ana', Order::find($ana->json('id'))->diner_name);
    }

    public function test_el_estado_de_la_mesa_numera_las_rondas_y_suma_el_total(): void
    {
        $this->pedir(['diner_name' => 'Ana']);
        $this->pedir([
            'diner_name' => 'Luis',
            'items'      => [['product_id' => $this->producto->id, 'quantity' => 2]],
        ]);

        $this->getJson("/api/menu/el-rincon/mesa/{$this->mesa->qr_code}")
            ->assertOk()
            ->assertJsonPath('table.number', '5')
            ->assertJsonCount(2, 'orders')
            // El comensal cuenta rondas, no ids: "lo que pedimos primero".
            ->assertJsonPath('orders.0.round', 1)
            ->assertJsonPath('orders.0.diner_name', 'Ana')
            ->assertJsonPath('orders.1.round', 2)
            ->assertJsonPath('orders.1.items.0.quantity', 2)
            ->assertJsonPath('total', 75000);
    }

    public function test_el_estado_no_expone_datos_del_cliente_registrado(): void
    {
        $this->pedir(['diner_name' => 'Ana']);

        $json = $this->getJson("/api/menu/el-rincon/mesa/{$this->mesa->qr_code}")->assertOk()->json();

        // El código está impreso a la vista de cualquiera que pase por la mesa,
        // así que de aquí solo pueden salir líneas, estados y totales.
        $this->assertArrayNotHasKey('customer', $json['orders'][0]);
        $this->assertStringNotContainsString('phone', json_encode($json));
    }

    public function test_el_estado_solo_muestra_los_pedidos_de_esa_mesa(): void
    {
        $this->pedir();
        $this->pedir(['qr_code' => $this->otraMesa->qr_code]);

        $this->getJson("/api/menu/el-rincon/mesa/{$this->mesa->qr_code}")
            ->assertOk()
            ->assertJsonCount(1, 'orders');
    }

    public function test_los_pedidos_cerrados_salen_de_la_cuenta_de_la_mesa(): void
    {
        $pedido = $this->pedir();

        Order::find($pedido->json('id'))->update(['status' => 'closed']);

        $this->getJson("/api/menu/el-rincon/mesa/{$this->mesa->qr_code}")
            ->assertOk()
            ->assertJsonCount(0, 'orders')
            ->assertJsonPath('total', 0);
    }

    public function test_un_codigo_qr_desconocido_no_devuelve_ninguna_mesa(): void
    {
        $this->getJson('/api/menu/el-rincon/mesa/' . Str::uuid())->assertNotFound();
    }

    public function test_la_segunda_ronda_puede_ser_para_llevar_sin_perder_la_mesa(): void
    {
        $this->pedir();

        $llevar = $this->pedir(['type' => 'takeaway'])->assertCreated();

        $pedido = Order::find($llevar->json('id'));

        // Lo que se lleva la mesa 5 sigue siendo de la mesa 5: misma cuenta,
        // solo cambia cómo se empaca. Contarlo como venta de mostrador
        // desplazaría el consumo de salón en los reportes.
        $this->assertSame('takeaway', $pedido->type);
        $this->assertSame($this->mesa->id, $pedido->table_id);

        $this->getJson("/api/menu/el-rincon/mesa/{$this->mesa->qr_code}")
            ->assertOk()
            ->assertJsonPath('orders.1.type', 'takeaway');
    }

    public function test_un_pedido_para_llevar_desde_la_mesa_tambien_la_ocupa(): void
    {
        $this->pedir(['type' => 'takeaway'])->assertCreated();

        // La gente está sentada ahí mientras espera, aunque se lo lleve.
        $this->assertSame('occupied', $this->mesa->fresh()->status);
    }

    public function test_el_comensal_no_puede_abrir_un_domicilio_ni_un_pedido_de_barra(): void
    {
        $this->pedir(['type' => 'delivery'])->assertStatus(422)->assertJsonValidationErrors('type');
        $this->pedir(['type' => 'counter'])->assertStatus(422)->assertJsonValidationErrors('type');
    }

    public function test_sin_tipo_el_pedido_del_qr_sigue_siendo_para_la_mesa(): void
    {
        $pedido = $this->pedir();

        $this->assertSame('dine_in', Order::find($pedido->json('id'))->type);
    }

    /** Un pedido desde el QR de la mesa, como lo manda el móvil del comensal. */
    private function pedir(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/orders/qr', array_merge([
            'restaurant_slug' => 'el-rincon',
            'qr_code'         => $this->mesa->qr_code,
            'items'           => [['product_id' => $this->producto->id, 'quantity' => 1]],
        ], $overrides));
    }
}
