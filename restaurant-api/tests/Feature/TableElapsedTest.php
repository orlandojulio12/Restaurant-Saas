<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * El tiempo de espera de la mesa es la señal más importante del panel de sala y
 * de la cocina: de él dependen los avisos de "esto lleva demasiado".
 *
 * Carbon 3 devuelve la diferencia **con signo**, así que `now()->diffInMinutes($pasado)`
 * da negativo. Con eso, el panel mostraba "-48 min" y ningún umbral saltaba nunca.
 */
class TableElapsedTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;
    private RestaurantTable $mesa;
    private Product $producto;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = Plan::create(['name' => 'pro', 'display_name' => 'Pro', 'max_tables' => 0]);

        $this->restaurant = Restaurant::create([
            'plan_id' => $plan->id, 'name' => 'El Rincón',
            'slug' => 'el-rincon', 'timezone' => 'America/Bogota',
        ]);

        $categoria = Category::create(['restaurant_id' => $this->restaurant->id, 'name' => 'Platos']);

        $this->producto = Product::create([
            'restaurant_id' => $this->restaurant->id, 'category_id' => $categoria->id,
            'name' => 'Bandeja', 'price' => 25000,
        ]);

        $this->mesa = RestaurantTable::create([
            'restaurant_id' => $this->restaurant->id, 'number' => '1',
            'capacity' => 4, 'qr_code' => Str::uuid()->toString(), 'status' => 'occupied',
        ]);

        Sanctum::actingAs(User::create([
            'restaurant_id' => $this->restaurant->id, 'name' => 'Mozo',
            'email' => 'mozo@test.com', 'password' => 'password', 'role' => 'waiter',
        ]));
    }

    private function pedidoDeHace(int $minutos): Order
    {
        $order = Order::create([
            'restaurant_id' => $this->restaurant->id,
            'table_id'      => $this->mesa->id,
            'type'          => 'dine_in',
            'status'        => 'preparing',
            'subtotal'      => 50000,
            'total'         => 50000,
        ]);

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $this->producto->id,
            'product_name' => $this->producto->name, 'unit_price' => 25000,
            'quantity' => 2, 'subtotal' => 50000,
        ]);

        // created_at no está en $fillable: se escribe por query builder.
        DB::table('orders')->where('id', $order->id)
            ->update(['created_at' => now()->subMinutes($minutos)]);

        return $order->refresh();
    }

    public function test_el_tiempo_de_espera_es_positivo(): void
    {
        $this->pedidoDeHace(48);

        $mesa = $this->getJson('/api/tables')->assertOk()->json('0');

        $this->assertEqualsWithDelta(48, $mesa['active_order']['elapsed_min'], 1);
        $this->assertGreaterThan(0, $mesa['active_order']['elapsed_min'], 'Nunca debe ser negativo.');
    }

    public function test_un_pedido_recien_creado_no_marca_espera(): void
    {
        $this->pedidoDeHace(0);

        $mesa = $this->getJson('/api/tables')->assertOk()->json('0');

        $this->assertEqualsWithDelta(0, $mesa['active_order']['elapsed_min'], 1);
    }

    public function test_la_espera_crece_con_la_antiguedad(): void
    {
        foreach ([5, 20, 60, 180] as $minutos) {
            Order::query()->delete();
            $this->pedidoDeHace($minutos);

            $mesa = $this->getJson('/api/tables')->assertOk()->json('0');

            $this->assertEqualsWithDelta(
                $minutos,
                $mesa['active_order']['elapsed_min'],
                1,
                "Falla con {$minutos} minutos.",
            );
        }
    }

    public function test_una_mesa_libre_no_trae_pedido_activo(): void
    {
        $mesa = $this->getJson('/api/tables')->assertOk()->json('0');

        $this->assertNull($mesa['active_order']);
    }

    public function test_los_pedidos_cerrados_no_cuentan_como_activos(): void
    {
        $order = $this->pedidoDeHace(30);
        $order->update(['status' => 'closed', 'closed_at' => now()]);

        $mesa = $this->getJson('/api/tables')->assertOk()->json('0');

        $this->assertNull($mesa['active_order']);
    }
}
