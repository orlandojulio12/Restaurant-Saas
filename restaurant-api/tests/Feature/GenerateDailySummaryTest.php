<?php

namespace Tests\Feature;

use App\Jobs\GenerateDailySummary;
use App\Models\Category;
use App\Models\DailySummary;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateDailySummaryTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;
    private Product $arroz;
    private Product $jugo;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = Plan::create([
            'name'           => 'pro',
            'display_name'   => 'Pro',
            'max_tables'     => 0,
            'max_products'   => 0,
            'price_monthly'  => 99000,
            'price_yearly'   => 950000,
            'has_inventory'  => true,
            'has_reports'    => true,
            'has_financials' => true,
        ]);

        $this->restaurant = Restaurant::create([
            'plan_id'  => $plan->id,
            'name'     => 'El Rincón de Prueba',
            'slug'     => 'el-rincon-de-prueba',
            'city'     => 'Bogotá',
            'currency' => 'COP',
            'timezone' => 'America/Bogota',   // UTC-5
        ]);

        $category = Category::create([
            'restaurant_id' => $this->restaurant->id,
            'name'          => 'Platos',
        ]);

        $this->arroz = Product::create([
            'restaurant_id' => $this->restaurant->id,
            'category_id'   => $category->id,
            'name'          => 'Arroz con pollo',
            'price'         => 25000,
            'cost'          => 10000,
        ]);

        $this->jugo = Product::create([
            'restaurant_id' => $this->restaurant->id,
            'category_id'   => $category->id,
            'name'          => 'Jugo de mango',
            'price'         => 8000,
            'cost'          => 5000,
        ]);
    }

    public function test_consolida_las_ventas_del_dia_local_del_restaurante(): void
    {
        // Dentro del 10/03 local (13:00 en Bogotá)
        $this->closedOrder('dine_in', 50000, '2026-03-10 18:00:00', $this->arroz, 2);

        // Dentro del 10/03 local (21:00 en Bogotá) pese a caer en el 11/03 UTC
        $this->closedOrder('delivery', 30000, '2026-03-11 02:00:00', $this->jugo, 1);

        // 09/03 local (23:00 en Bogotá): NO debe contarse
        $this->closedOrder('counter', 20000, '2026-03-10 04:00:00', $this->arroz, 5);

        // Pedido abierto: no es una venta realizada
        Order::create([
            'restaurant_id' => $this->restaurant->id,
            'type'          => 'dine_in',
            'status'        => 'pending',
            'subtotal'      => 99999,
            'total'         => 99999,
        ]);

        (new GenerateDailySummary($this->restaurant->id, '2026-03-10'))->handle();

        $summary = DailySummary::where('restaurant_id', $this->restaurant->id)
            ->where('date', '2026-03-10')
            ->first();

        $this->assertNotNull($summary, 'No se generó el resumen del día.');

        $this->assertEquals(2, $summary->total_orders);
        $this->assertEquals(80000, $summary->total_sales);
        $this->assertEquals(25000, $summary->total_cost);   // 2×10000 + 1×5000
        $this->assertEquals(55000, $summary->gross_profit);
        $this->assertEquals(40000, $summary->avg_ticket);
        $this->assertEquals(1, $summary->orders_dine_in);
        $this->assertEquals(1, $summary->orders_delivery);
        $this->assertEquals(0, $summary->orders_counter);
        $this->assertEquals($this->arroz->id, $summary->top_product_id);
    }

    public function test_es_idempotente_y_no_duplica_filas(): void
    {
        $this->closedOrder('dine_in', 50000, '2026-03-10 18:00:00', $this->arroz, 2);

        (new GenerateDailySummary($this->restaurant->id, '2026-03-10'))->handle();
        (new GenerateDailySummary($this->restaurant->id, '2026-03-10'))->handle();

        $this->assertSame(1, DailySummary::where('restaurant_id', $this->restaurant->id)->count());
    }

    public function test_registra_un_dia_sin_ventas_en_cero(): void
    {
        (new GenerateDailySummary($this->restaurant->id, '2026-03-10'))->handle();

        $summary = DailySummary::where('restaurant_id', $this->restaurant->id)->first();

        $this->assertNotNull($summary);
        $this->assertEquals(0, $summary->total_orders);
        $this->assertEquals(0, $summary->total_sales);
        $this->assertNull($summary->top_product_id);
    }

    private function closedOrder(string $type, float $total, string $closedAtUtc, Product $product, int $qty): Order
    {
        $order = Order::create([
            'restaurant_id' => $this->restaurant->id,
            'type'          => $type,
            'status'        => 'closed',
            'subtotal'      => $total,
            'total'         => $total,
            'closed_at'     => $closedAtUtc,
        ]);

        OrderItem::create([
            'order_id'     => $order->id,
            'product_id'   => $product->id,
            'product_name' => $product->name,
            'unit_price'   => $product->price,
            'quantity'     => $qty,
            'subtotal'     => $product->price * $qty,
        ]);

        return $order;
    }
}
