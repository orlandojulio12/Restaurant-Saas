<?php

namespace Tests\Feature;

use App\Events\LowStockAlert;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductIngredient;
use App\Models\Restaurant;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class InventoryDeductionTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;
    private Product $producto;
    private Ingredient $arroz;

    private function montar(bool $conInventario): void
    {
        $plan = Plan::create([
            'name'          => $conInventario ? 'pro' : 'free',
            'display_name'  => $conInventario ? 'Pro' : 'Gratis',
            'has_inventory' => $conInventario,
        ]);

        $this->restaurant = Restaurant::create([
            'plan_id'  => $plan->id,
            'name'     => 'El Rincón de Prueba',
            'slug'     => 'el-rincon-' . ($conInventario ? 'pro' : 'free'),
            'timezone' => 'America/Bogota',
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

        $this->arroz = Ingredient::create([
            'restaurant_id' => $this->restaurant->id,
            'name'          => 'Arroz',
            'unit'          => 'kg',
            'stock'         => 10,
            'min_stock'     => 2,
            'cost_per_unit' => 3000,
        ]);

        ProductIngredient::create([
            'product_id'    => $this->producto->id,
            'ingredient_id' => $this->arroz->id,
            'quantity'      => 0.5,          // 0.5 kg por plato
        ]);
    }

    public function test_descuenta_stock_y_registra_el_movimiento(): void
    {
        $this->montar(conInventario: true);
        $order = $this->ordenCerrada(cantidad: 4);   // 4 × 0.5 = 2 kg

        app(InventoryService::class)->deductForOrder($order);

        $this->assertEquals(8, $this->arroz->fresh()->stock);

        $mov = InventoryMovement::where('ingredient_id', $this->arroz->id)->first();
        $this->assertNotNull($mov);
        $this->assertEquals('out', $mov->type);
        $this->assertEquals(2, $mov->quantity);
        $this->assertEquals(10, $mov->stock_before);
        $this->assertEquals(8, $mov->stock_after);
        $this->assertEquals($order->id, $mov->order_id);
        $this->assertEquals($this->restaurant->id, $mov->restaurant_id);
    }

    public function test_no_toca_el_inventario_si_el_plan_no_lo_incluye(): void
    {
        $this->montar(conInventario: false);
        $order = $this->ordenCerrada(cantidad: 4);

        app(InventoryService::class)->deductForOrder($order);

        $this->assertEquals(10, $this->arroz->fresh()->stock, 'El plan free no debe descontar stock.');
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_emite_alerta_al_caer_bajo_el_minimo(): void
    {
        Event::fake([LowStockAlert::class]);

        $this->montar(conInventario: true);
        $order = $this->ordenCerrada(cantidad: 17);   // 8.5 kg → quedan 1.5, bajo el mínimo de 2

        app(InventoryService::class)->deductForOrder($order);

        $this->assertEquals(1.5, $this->arroz->fresh()->stock);
        Event::assertDispatched(LowStockAlert::class, fn($e) => $e->ingredient->id === $this->arroz->id);
    }

    public function test_no_emite_alerta_si_queda_sobre_el_minimo(): void
    {
        Event::fake([LowStockAlert::class]);

        $this->montar(conInventario: true);
        $order = $this->ordenCerrada(cantidad: 2);    // quedan 9 kg

        app(InventoryService::class)->deductForOrder($order);

        Event::assertNotDispatched(LowStockAlert::class);
    }

    public function test_el_stock_nunca_queda_negativo(): void
    {
        $this->montar(conInventario: true);
        $order = $this->ordenCerrada(cantidad: 100);  // 50 kg pedidos, solo hay 10

        app(InventoryService::class)->deductForOrder($order);

        $this->assertEquals(0, $this->arroz->fresh()->stock);
    }

    private function ordenCerrada(int $cantidad): Order
    {
        $order = Order::create([
            'restaurant_id' => $this->restaurant->id,
            'type'          => 'dine_in',
            'status'        => 'closed',
            'subtotal'      => $this->producto->price * $cantidad,
            'total'         => $this->producto->price * $cantidad,
            'closed_at'     => now(),
        ]);

        OrderItem::create([
            'order_id'     => $order->id,
            'product_id'   => $this->producto->id,
            'product_name' => $this->producto->name,
            'unit_price'   => $this->producto->price,
            'quantity'     => $cantidad,
            'subtotal'     => $this->producto->price * $cantidad,
        ]);

        return $order;
    }
}
