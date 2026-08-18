<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DailySummary;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinancialDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;
    private User $admin;
    private Product $producto;

    /**
     * Momento congelado: 23:30 en Bogotá del 16/08, que en UTC ya es el 17/08 04:30.
     * Es justo la franja donde el día local y el día UTC no coinciden.
     */
    private const AHORA_LOCAL = '2026-08-16 23:30';

    protected function setUp(): void
    {
        parent::setUp();

        // Se congela en UTC, como corre la app: un instante con otra zona
        // haría que Carbon la adopte al parsear y desplazaría los casts.
        Carbon::setTestNow(Carbon::parse(self::AHORA_LOCAL, 'America/Bogota')->utc());

        $plan = Plan::create([
            'name'           => 'pro',
            'display_name'   => 'Pro',
            'price_monthly'  => 99000,
            'price_yearly'   => 950000,
            'has_financials' => true,
        ]);

        $this->restaurant = Restaurant::create([
            'plan_id'  => $plan->id,
            'name'     => 'El Rincón de Prueba',
            'slug'     => 'el-rincon-de-prueba',
            'currency' => 'COP',
            'timezone' => 'America/Bogota',   // UTC-5
        ]);

        $this->admin = User::create([
            'restaurant_id' => $this->restaurant->id,
            'name'          => 'Admin',
            'email'         => 'admin@test.com',
            'password'      => 'password',
            'role'          => 'admin',
        ]);

        $category = Category::create([
            'restaurant_id' => $this->restaurant->id,
            'name'          => 'Platos',
        ]);

        $this->producto = Product::create([
            'restaurant_id' => $this->restaurant->id,
            'category_id'   => $category->id,
            'name'          => 'Bandeja Paisa',
            'price'         => 25000,
            'cost'          => 10000,
        ]);
    }

    public function test_las_ventas_de_hoy_se_calculan_en_vivo_sin_esperar_al_job(): void
    {
        // 14:00 hora de Bogotá de hoy: el job del resumen aún no ha corrido.
        $hoyLocal = $this->restaurant->localNow()->toDateString();
        $this->closedOrderAt("{$hoyLocal} 14:00");
        $this->closedOrderAt("{$hoyLocal} 15:30");

        $data = $this->dashboard();

        // Antes esto devolvía 0 porque leía de daily_summaries, que solo cubre días cerrados.
        $this->assertEquals(50000, $data['today']['sales']);
        $this->assertEquals(2, $data['today']['orders']);
        $this->assertEquals(25000, $data['today']['avg_ticket']);
        $this->assertEquals($hoyLocal, $data['today']['date']);

        // Y el mes en curso incluye el día de hoy.
        $this->assertEquals(50000, $data['month']['sales']);
    }

    public function test_una_venta_nocturna_cuenta_en_el_dia_local_y_no_en_el_siguiente_utc(): void
    {
        // 22:00 en Bogotá = 03:00 UTC del 17/08, pero el día de negocio sigue siendo el 16.
        $this->closedOrderAt('2026-08-16 22:00');

        $data = $this->dashboard();

        $this->assertEquals('2026-08-16', $data['today']['date']);
        $this->assertEquals(25000, $data['today']['sales'], 'La venta nocturna se fue al día equivocado.');
        $this->assertEquals(1, $data['today']['orders']);
    }

    public function test_una_venta_de_ayer_no_se_cuenta_como_de_hoy(): void
    {
        // 23:00 del 15/08 local = 04:00 UTC del 16/08: cae en el día UTC de "hoy",
        // pero pertenece al día local de ayer.
        $this->closedOrderAt('2026-08-15 23:00');

        $data = $this->dashboard();

        $this->assertEquals(0, $data['today']['sales']);
        $this->assertEquals(25000, $data['today']['vs_yesterday']['sales']);
    }

    public function test_los_pedidos_abiertos_no_suman_a_las_ventas(): void
    {
        $hoyLocal = $this->restaurant->localNow()->toDateString();
        $this->closedOrderAt("{$hoyLocal} 12:00");

        // Pedido en curso: no es una venta realizada.
        Order::create([
            'restaurant_id' => $this->restaurant->id,
            'type'          => 'dine_in',
            'status'        => 'preparing',
            'subtotal'      => 99000,
            'total'         => 99000,
        ]);

        $data = $this->dashboard();

        $this->assertEquals(25000, $data['today']['sales']);
        $this->assertEquals(1, $data['today']['orders']);
        $this->assertEquals(1, $data['today']['open_orders']);
    }

    public function test_el_mes_suma_dias_consolidados_mas_el_dia_en_vivo(): void
    {
        $localNow = $this->restaurant->localNow();

        DailySummary::create([
            'restaurant_id' => $this->restaurant->id,
            'date'          => $localNow->copy()->startOfMonth()->toDateString(),
            'total_orders'  => 4,
            'total_sales'   => 120000,
        ]);

        $this->closedOrderAt($localNow->toDateString() . ' 11:00');

        $data = $this->dashboard();

        $this->assertEquals(145000, $data['month']['sales'], '120000 consolidados + 25000 de hoy.');
    }

    private function dashboard(): array
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/financial/dashboard');
        $response->assertOk();

        return $response->json();
    }

    private function closedOrderAt(string $localDateTime): Order
    {
        $closedAt = Carbon::parse($localDateTime, 'America/Bogota')->utc();

        $order = Order::create([
            'restaurant_id' => $this->restaurant->id,
            'type'          => 'dine_in',
            'status'        => 'closed',
            'subtotal'      => $this->producto->price,
            'total'         => $this->producto->price,
            'closed_at'     => $closedAt,
            'created_at'    => $closedAt,
        ]);

        OrderItem::create([
            'order_id'     => $order->id,
            'product_id'   => $this->producto->id,
            'product_name' => $this->producto->name,
            'unit_price'   => $this->producto->price,
            'quantity'     => 1,
            'subtotal'     => $this->producto->price,
        ]);

        return $order;
    }
}
