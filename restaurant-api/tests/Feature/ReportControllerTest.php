<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportControllerTest extends TestCase
{
    use RefreshDatabase;

    /** 23:30 en Bogotá del 16/08 = 04:30 UTC del 17/08. */
    private const AHORA_LOCAL = '2026-08-16 23:30';

    private Restaurant $restaurant;
    private Product $bandeja;
    private Product $jugo;

    protected function setUp(): void
    {
        parent::setUp();

        // Se congela en UTC, como corre la app: un instante con otra zona
        // haría que Carbon la adopte al parsear y desplazaría los casts.
        Carbon::setTestNow(Carbon::parse(self::AHORA_LOCAL, 'America/Bogota')->utc());

        $plan = Plan::create(['name' => 'pro', 'display_name' => 'Pro', 'has_reports' => true]);

        $this->restaurant = Restaurant::create([
            'plan_id' => $plan->id, 'name' => 'Mío', 'slug' => 'mio', 'timezone' => 'America/Bogota',
        ]);

        Sanctum::actingAs(User::create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Admin', 'email' => 'admin@test.com',
            'password' => 'password', 'role' => 'admin',
        ]));

        $categoria = Category::create(['restaurant_id' => $this->restaurant->id, 'name' => 'Platos']);

        $this->bandeja = Product::create([
            'restaurant_id' => $this->restaurant->id, 'category_id' => $categoria->id,
            'name' => 'Bandeja Paisa', 'price' => 25000, 'cost' => 10000,
        ]);

        $this->jugo = Product::create([
            'restaurant_id' => $this->restaurant->id, 'category_id' => $categoria->id,
            'name' => 'Jugo', 'price' => 8000, 'cost' => 3000,
        ]);
    }

    public function test_la_serie_diaria_incluye_los_dias_sin_ventas_en_cero(): void
    {
        $this->venta('2026-08-15 12:00', $this->bandeja, 2);

        $response = $this->getJson('/api/reports/daily?from=2026-08-14&to=2026-08-16')->assertOk();

        $this->assertCount(3, $response->json('data'));
        $this->assertSame('2026-08-14', $response->json('data.0.date'));
        $this->assertSame(0, $response->json('data.0.orders'));
        $this->assertEquals(50000, $response->json('data.1.sales'));
        $this->assertSame(0, $response->json('data.2.orders'));
    }

    public function test_una_venta_nocturna_cae_en_el_dia_local_correcto(): void
    {
        // 22:00 en Bogotá del 15 = 03:00 UTC del 16.
        $this->venta('2026-08-15 22:00', $this->bandeja, 1);

        $response = $this->getJson('/api/reports/daily?from=2026-08-15&to=2026-08-16')->assertOk();

        $this->assertEquals(25000, $response->json('data.0.sales'), 'Debe contar el 15, no el 16.');
        $this->assertEquals(0, $response->json('data.1.sales'));
    }

    public function test_calcula_costo_y_utilidad_bruta(): void
    {
        $this->venta('2026-08-16 12:00', $this->bandeja, 2);   // 50.000 venta, 20.000 costo

        $response = $this->getJson('/api/reports/daily?from=2026-08-16&to=2026-08-16')->assertOk();

        $this->assertEquals(50000, $response->json('totals.sales'));
        $this->assertEquals(20000, $response->json('totals.cost'));
        $this->assertEquals(30000, $response->json('totals.gross_profit'));
        $this->assertEquals(50000, $response->json('totals.avg_ticket'));
    }

    public function test_solo_cuenta_pedidos_cerrados(): void
    {
        $this->venta('2026-08-16 12:00', $this->bandeja, 1);

        // Uno abierto y otro cancelado no son ventas realizadas.
        Order::create([
            'restaurant_id' => $this->restaurant->id, 'type' => 'counter',
            'status' => 'preparing', 'subtotal' => 99000, 'total' => 99000,
        ]);
        Order::create([
            'restaurant_id' => $this->restaurant->id, 'type' => 'counter',
            'status' => 'cancelled', 'subtotal' => 88000, 'total' => 88000,
            'closed_at' => Carbon::parse('2026-08-16 12:00', 'America/Bogota')->utc(),
        ]);

        $this->getJson('/api/reports/daily?from=2026-08-16&to=2026-08-16')
            ->assertOk()
            ->assertJsonPath('totals.orders', 1)
            ->assertJsonPath('totals.sales', 25000);
    }

    public function test_top_de_productos_ordena_por_unidades(): void
    {
        $this->venta('2026-08-16 12:00', $this->jugo, 5);
        $this->venta('2026-08-16 13:00', $this->bandeja, 2);

        $response = $this->getJson('/api/reports/products?from=2026-08-16&to=2026-08-16')->assertOk();

        $this->assertSame('Jugo', $response->json('data.0.product_name'));
        $this->assertSame(5, $response->json('data.0.quantity'));
        $this->assertEquals(40000, $response->json('data.0.revenue'));
        $this->assertEquals(15000, $response->json('data.0.cost'));
        $this->assertEquals(25000, $response->json('data.0.gross_profit'));

        $this->assertSame('Bandeja Paisa', $response->json('data.1.product_name'));
    }

    public function test_el_resumen_desglosa_por_tipo_y_metodo_de_pago(): void
    {
        $enMesa = $this->venta('2026-08-16 12:00', $this->bandeja, 1, tipo: 'dine_in');
        $aDomicilio = $this->venta('2026-08-16 13:00', $this->jugo, 1, tipo: 'delivery');

        $this->pago($enMesa, 'cash', 25000);
        $this->pago($aDomicilio, 'nequi', 8000);

        $response = $this->getJson('/api/reports/summary?from=2026-08-16&to=2026-08-16')->assertOk();

        $this->assertEquals(33000, $response->json('totals.sales'));
        $this->assertEquals(13000, $response->json('totals.cost'));

        $porTipo = collect($response->json('by_type'))->keyBy('type');
        $this->assertSame(1, $porTipo['dine_in']['count']);
        $this->assertEquals(25000, $porTipo['dine_in']['sales']);
        $this->assertSame(0, $porTipo['counter']['count']);

        $porMetodo = collect($response->json('by_payment_method'))->keyBy('method');
        $this->assertEquals(25000, $porMetodo['cash']['amount']);
        $this->assertEquals(8000, $porMetodo['nequi']['amount']);
    }

    public function test_el_resumen_cuadra_con_la_serie_diaria(): void
    {
        $this->venta('2026-08-15 12:00', $this->bandeja, 2);
        $this->venta('2026-08-16 12:00', $this->jugo, 3);

        $diario  = $this->getJson('/api/reports/daily?from=2026-08-15&to=2026-08-16')->assertOk();
        $resumen = $this->getJson('/api/reports/summary?from=2026-08-15&to=2026-08-16')->assertOk();

        $this->assertEquals(
            $diario->json('totals.sales'),
            $resumen->json('totals.sales'),
            'Los dos endpoints deben reconciliar.'
        );
        $this->assertEquals($diario->json('totals.cost'), $resumen->json('totals.cost'));
    }

    public function test_sin_rango_usa_los_ultimos_treinta_dias(): void
    {
        $response = $this->getJson('/api/reports/daily')->assertOk();

        $this->assertSame('2026-07-18', $response->json('from'));
        $this->assertSame('2026-08-16', $response->json('to'));
        $this->assertCount(30, $response->json('data'));
    }

    public function test_rechaza_un_rango_invertido_o_demasiado_amplio(): void
    {
        $this->getJson('/api/reports/daily?from=2026-08-16&to=2026-08-01')->assertStatus(422);
        $this->getJson('/api/reports/daily?from=2020-01-01&to=2026-08-16')
            ->assertStatus(422)
            ->assertJsonPath('max_days', 366);
    }

    public function test_responde_403_si_el_plan_no_incluye_reportes(): void
    {
        $free = Plan::create(['name' => 'free', 'display_name' => 'Gratis', 'has_reports' => false]);
        $this->restaurant->update(['plan_id' => $free->id]);

        $this->getJson('/api/reports/daily')->assertStatus(403);
        $this->getJson('/api/reports/products')->assertStatus(403);
        $this->getJson('/api/reports/summary')->assertStatus(403);
    }

    private function venta(string $localDateTime, Product $producto, int $cantidad, string $tipo = 'counter'): Order
    {
        $closedAt = Carbon::parse($localDateTime, 'America/Bogota')->utc();
        $total    = $producto->price * $cantidad;

        $order = Order::create([
            'restaurant_id' => $this->restaurant->id,
            'type'          => $tipo,
            'status'        => 'closed',
            'subtotal'      => $total,
            'total'         => $total,
            'closed_at'     => $closedAt,
            'created_at'    => $closedAt,
        ]);

        OrderItem::create([
            'order_id'     => $order->id,
            'product_id'   => $producto->id,
            'product_name' => $producto->name,
            'unit_price'   => $producto->price,
            'quantity'     => $cantidad,
            'subtotal'     => $total,
        ]);

        return $order;
    }

    private function pago(Order $order, string $metodo, float $monto): void
    {
        Payment::create([
            'restaurant_id' => $this->restaurant->id,
            'order_id'      => $order->id,
            'method'        => $metodo,
            'amount'        => $monto,
            'status'        => 'completed',
            'created_at'    => $order->closed_at,
        ]);
    }
}
