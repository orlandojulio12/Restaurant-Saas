<?php

namespace Tests\Feature;

use App\Events\OrderStatusUpdated;
use App\Events\TableStatusUpdated;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductIngredient;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentControllerTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;
    private Product $producto;
    private RestaurantTable $mesa;
    private User $cajero;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = Plan::create(['name' => 'pro', 'display_name' => 'Pro', 'has_inventory' => true]);

        $this->restaurant = Restaurant::create([
            'plan_id' => $plan->id, 'name' => 'Mío', 'slug' => 'mio', 'timezone' => 'America/Bogota',
        ]);

        $this->cajero = User::create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Cajero', 'email' => 'caja@test.com',
            'password' => 'password', 'role' => 'cashier',
        ]);

        Sanctum::actingAs($this->cajero);

        $categoria = Category::create(['restaurant_id' => $this->restaurant->id, 'name' => 'Platos']);

        $this->producto = Product::create([
            'restaurant_id' => $this->restaurant->id,
            'category_id'   => $categoria->id,
            'name'          => 'Bandeja Paisa',
            'price'         => 25000,
            'cost'          => 10000,
        ]);

        $this->mesa = RestaurantTable::create([
            'restaurant_id' => $this->restaurant->id,
            'number'        => '1',
            'capacity'      => 4,
            'qr_code'       => Str::uuid()->toString(),
            'status'        => 'occupied',
        ]);
    }

    public function test_registra_el_pago_y_cierra_el_pedido(): void
    {
        $pedido = $this->pedido();

        $response = $this->postJson('/api/payments', [
            'order_id' => $pedido->id,
            'method'   => 'cash',
            'amount'   => 30000,
        ])->assertCreated();

        $this->assertSame('completed', $response->json('payment.status'));
        $this->assertSame($this->cajero->id, $response->json('payment.cashier_id'));

        $pedido->refresh();
        $this->assertSame('closed', $pedido->status);
        $this->assertNotNull($pedido->closed_at);
    }

    public function test_calcula_el_vuelto_solo_en_efectivo(): void
    {
        $enEfectivo = $this->postJson('/api/payments', [
            'order_id' => $this->pedido()->id, 'method' => 'cash', 'amount' => 30000,
        ])->assertCreated();

        $this->assertEquals(5000, $enEfectivo->json('payment.change_amount'));

        $conTarjeta = $this->postJson('/api/payments', [
            'order_id' => $this->pedido()->id, 'method' => 'card', 'amount' => 30000,
        ])->assertCreated();

        $this->assertEquals(0, $conTarjeta->json('payment.change_amount'));
    }

    public function test_rechaza_un_pago_que_no_cubre_el_total(): void
    {
        $pedido = $this->pedido();

        $this->postJson('/api/payments', [
            'order_id' => $pedido->id, 'method' => 'cash', 'amount' => 20000,
        ])
            ->assertStatus(422)
            ->assertJsonPath('total', 25000);

        $this->assertSame('pending', $pedido->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_no_permite_cobrar_dos_veces_el_mismo_pedido(): void
    {
        $pedido = $this->pedido();

        $this->postJson('/api/payments', ['order_id' => $pedido->id, 'method' => 'cash', 'amount' => 25000])
            ->assertCreated();

        $this->postJson('/api/payments', ['order_id' => $pedido->id, 'method' => 'cash', 'amount' => 25000])
            ->assertStatus(422);

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_no_permite_cobrar_un_pedido_cancelado(): void
    {
        $pedido = $this->pedido();
        $pedido->update(['status' => 'cancelled']);

        $this->postJson('/api/payments', ['order_id' => $pedido->id, 'method' => 'cash', 'amount' => 25000])
            ->assertStatus(422);
    }

    public function test_no_permite_cobrar_pedidos_de_otro_restaurante(): void
    {
        $otro   = Restaurant::create([
            'plan_id' => Plan::first()->id, 'name' => 'Ajeno', 'slug' => 'ajeno', 'timezone' => 'America/Bogota',
        ]);
        $ajeno = Order::create([
            'restaurant_id' => $otro->id, 'type' => 'counter', 'status' => 'pending',
            'subtotal' => 1000, 'total' => 1000,
        ]);

        $this->postJson('/api/payments', ['order_id' => $ajeno->id, 'method' => 'cash', 'amount' => 1000])
            ->assertStatus(422);
    }

    public function test_libera_la_mesa_al_cobrar(): void
    {
        Event::fake([TableStatusUpdated::class, OrderStatusUpdated::class]);

        $pedido = $this->pedido(conMesa: true);

        $this->postJson('/api/payments', ['order_id' => $pedido->id, 'method' => 'cash', 'amount' => 25000])
            ->assertCreated();

        $this->assertSame('available', $this->mesa->fresh()->status);
        Event::assertDispatched(TableStatusUpdated::class);
        Event::assertDispatched(OrderStatusUpdated::class);
    }

    public function test_no_libera_la_mesa_si_le_quedan_pedidos_abiertos(): void
    {
        $primero = $this->pedido(conMesa: true);
        $this->pedido(conMesa: true);   // sigue abierto

        $this->postJson('/api/payments', ['order_id' => $primero->id, 'method' => 'cash', 'amount' => 25000])
            ->assertCreated();

        $this->assertSame('occupied', $this->mesa->fresh()->status);
    }

    public function test_actualiza_los_contadores_del_cliente(): void
    {
        $cliente = Customer::create([
            'restaurant_id' => $this->restaurant->id, 'name' => 'Ana', 'phone' => '3001111111',
        ]);

        $pedido = $this->pedido();
        $pedido->update(['customer_id' => $cliente->id]);

        $this->postJson('/api/payments', ['order_id' => $pedido->id, 'method' => 'cash', 'amount' => 25000])
            ->assertCreated();

        $cliente->refresh();
        $this->assertSame(1, $cliente->total_orders);
        $this->assertEquals(25000, $cliente->total_spent);
        $this->assertNotNull($cliente->last_order_at);
    }

    public function test_descuenta_el_inventario_al_cobrar(): void
    {
        $arroz = Ingredient::create([
            'restaurant_id' => $this->restaurant->id, 'name' => 'Arroz', 'unit' => 'kg',
            'stock' => 10, 'min_stock' => 1, 'cost_per_unit' => 3000,
        ]);

        ProductIngredient::create([
            'product_id' => $this->producto->id, 'ingredient_id' => $arroz->id, 'quantity' => 0.5,
        ]);

        $pedido = $this->pedido();   // 1 unidad → 0.5 kg

        $this->postJson('/api/payments', ['order_id' => $pedido->id, 'method' => 'cash', 'amount' => 25000])
            ->assertCreated();

        $this->assertEquals(9.5, $arroz->fresh()->stock);
    }

    public function test_lista_los_pagos_filtrando_por_metodo(): void
    {
        $this->postJson('/api/payments', ['order_id' => $this->pedido()->id, 'method' => 'cash', 'amount' => 25000]);
        $this->postJson('/api/payments', ['order_id' => $this->pedido()->id, 'method' => 'nequi', 'amount' => 25000]);

        $this->getJson('/api/payments')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/payments?method=nequi')->assertOk()->assertJsonCount(1, 'data');
    }

    private function pedido(bool $conMesa = false): Order
    {
        $order = Order::create([
            'restaurant_id' => $this->restaurant->id,
            'table_id'      => $conMesa ? $this->mesa->id : null,
            'type'          => $conMesa ? 'dine_in' : 'counter',
            'status'        => 'pending',
            'subtotal'      => 25000,
            'total'         => 25000,
        ]);

        OrderItem::create([
            'order_id'     => $order->id,
            'product_id'   => $this->producto->id,
            'product_name' => $this->producto->name,
            'unit_price'   => 25000,
            'quantity'     => 1,
            'subtotal'     => 25000,
        ]);

        return $order;
    }
}
