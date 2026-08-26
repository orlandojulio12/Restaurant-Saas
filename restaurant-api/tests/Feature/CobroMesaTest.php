<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cobrar de una vez todo lo que lleva una mesa.
 *
 * Desde que cada comensal pide por su cuenta con el QR, una mesa de cuatro
 * puede acumular cuatro pedidos. Cobrarlos de uno en uno obligaba a la caja a
 * sumar el total a mano y a repetir el cobro cuatro veces.
 *
 * Sigue habiendo un pago por pedido —`payments.order_id` no es nulable y los
 * reportes cuentan por pedido—, pero se crean juntos y en una sola transacción.
 */
class CobroMesaTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;
    private Restaurant $otro;
    private RestaurantTable $mesa;
    private Product $producto;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = Plan::create(['name' => 'pro', 'display_name' => 'Pro']);

        $this->restaurant = Restaurant::create([
            'plan_id' => $plan->id, 'name' => 'Mío', 'slug' => 'mio', 'timezone' => 'America/Bogota',
        ]);

        $this->otro = Restaurant::create([
            'plan_id' => $plan->id, 'name' => 'Ajeno', 'slug' => 'ajeno', 'timezone' => 'America/Bogota',
        ]);

        Sanctum::actingAs(User::create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Cajero', 'email' => 'caja@test.com',
            'password' => 'password', 'role' => 'cashier',
        ]));

        $categoria = Category::create(['restaurant_id' => $this->restaurant->id, 'name' => 'Platos']);

        $this->producto = Product::create([
            'restaurant_id' => $this->restaurant->id, 'category_id' => $categoria->id,
            'name' => 'Bandeja Paisa', 'price' => 25000, 'cost' => 10000,
        ]);

        $this->mesa = RestaurantTable::create([
            'restaurant_id' => $this->restaurant->id, 'number' => '5', 'capacity' => 4,
            'qr_code' => Str::uuid()->toString(), 'status' => 'occupied',
        ]);
    }

    public function test_cobra_todos_los_pedidos_de_la_mesa_y_la_libera(): void
    {
        $ana  = $this->pedido(25000);
        $luis = $this->pedido(50000);

        $this->postJson("/api/payments/table/{$this->mesa->id}", [
            'method' => 'cash',
            'amount' => 75000,
        ])
            ->assertCreated()
            ->assertJsonPath('total', 75000)
            ->assertJsonCount(2, 'payments')
            ->assertJsonPath('table.status', 'available');

        $this->assertSame('closed', $ana->fresh()->status);
        $this->assertSame('closed', $luis->fresh()->status);

        // La mesa solo se libera cuando no queda nada abierto, y aquí se cerró
        // todo de golpe.
        $this->assertSame('available', $this->mesa->fresh()->status);
    }

    public function test_cada_pedido_conserva_su_propio_pago(): void
    {
        $ana  = $this->pedido(25000);
        $luis = $this->pedido(50000);

        $this->postJson("/api/payments/table/{$this->mesa->id}", [
            'method' => 'card',
            'amount' => 75000,
        ])->assertCreated();

        // Un pago por pedido: los reportes cuentan por pedido y `order->payment`
        // sigue siendo uno a uno.
        $this->assertSame('25000.00', Payment::where('order_id', $ana->id)->value('amount'));
        $this->assertSame('50000.00', Payment::where('order_id', $luis->id)->value('amount'));
        $this->assertSame(2, Payment::where('restaurant_id', $this->restaurant->id)->count());
    }

    public function test_el_vuelto_se_calcula_sobre_el_total_de_la_mesa(): void
    {
        $this->pedido(25000);
        $this->pedido(50000);

        $response = $this->postJson("/api/payments/table/{$this->mesa->id}", [
            'method' => 'cash',
            'amount' => 100000,
        ])->assertCreated();

        $this->assertEquals(25000, $response->json('change'));

        // El excedente va al último pago, de forma que en cada fila
        // `amount - change_amount` sigue siendo el total de su pedido.
        foreach (Payment::all() as $pago) {
            $this->assertSame(
                (float) $pago->order->total,
                round((float) $pago->amount - (float) $pago->change_amount, 2),
            );
        }
    }

    public function test_una_tarjeta_no_genera_vuelto(): void
    {
        $this->pedido(25000);

        $this->postJson("/api/payments/table/{$this->mesa->id}", [
            'method' => 'card',
            'amount' => 30000,
        ])
            ->assertCreated()
            ->assertJsonPath('change', 0);

        $this->assertSame('0.00', Payment::first()->change_amount);
    }

    public function test_rechaza_un_monto_que_no_cubre_la_mesa(): void
    {
        $this->pedido(25000);
        $this->pedido(50000);

        $this->postJson("/api/payments/table/{$this->mesa->id}", [
            'method' => 'cash',
            'amount' => 60000,
        ])
            ->assertStatus(422)
            ->assertJsonPath('total', 75000);

        $this->assertSame(0, Payment::count());
        $this->assertSame('occupied', $this->mesa->fresh()->status);
    }

    public function test_no_cobra_una_mesa_con_propuestas_sin_confirmar(): void
    {
        $this->pedido(25000);
        $propuesto = $this->pedido(50000, 'proposed');

        // Cobrar una propuesta sería dar por buena una comanda que el mesero
        // todavía no ha mirado. Y dejarla fuera del cobro tampoco sirve: la
        // mesa quedaría ocupada por un pedido pendiente justo después de pagar.
        $this->postJson("/api/payments/table/{$this->mesa->id}", [
            'method' => 'cash',
            'amount' => 75000,
        ])
            ->assertStatus(422)
            ->assertJsonPath('proposed.0', $propuesto->id);

        $this->assertSame(0, Payment::count());
    }

    public function test_no_cobra_una_mesa_sin_nada_pendiente(): void
    {
        $this->postJson("/api/payments/table/{$this->mesa->id}", [
            'method' => 'cash',
            'amount' => 10000,
        ])->assertStatus(422);
    }

    public function test_no_cobra_la_mesa_de_otro_restaurante(): void
    {
        $ajena = RestaurantTable::create([
            'restaurant_id' => $this->otro->id, 'number' => '1', 'capacity' => 2,
            'qr_code' => Str::uuid()->toString(), 'status' => 'occupied',
        ]);

        $this->postJson("/api/payments/table/{$ajena->id}", [
            'method' => 'cash',
            'amount' => 10000,
        ])->assertForbidden();
    }

    public function test_no_vuelve_a_cobrar_un_pedido_que_ya_tiene_pago(): void
    {
        $pagado = $this->pedido(25000);
        $nuevo  = $this->pedido(50000);

        Payment::create([
            'restaurant_id' => $this->restaurant->id,
            'order_id'      => $pagado->id,
            'method'        => 'cash',
            'amount'        => 25000,
            'status'        => 'completed',
        ]);

        $this->postJson("/api/payments/table/{$this->mesa->id}", [
            'method' => 'cash',
            'amount' => 50000,
        ])
            ->assertCreated()
            ->assertJsonPath('total', 50000)
            ->assertJsonCount(1, 'payments')
            ->assertJsonPath('orders.0', $nuevo->id);
    }

    private function pedido(float $total, string $status = 'pending'): Order
    {
        $order = Order::create([
            'restaurant_id' => $this->restaurant->id,
            'table_id'      => $this->mesa->id,
            'type'          => 'dine_in',
            'status'        => $status,
            'subtotal'      => $total,
            'total'         => $total,
        ]);

        OrderItem::create([
            'order_id'     => $order->id,
            'product_id'   => $this->producto->id,
            'product_name' => $this->producto->name,
            'unit_price'   => $total,
            'quantity'     => 1,
            'subtotal'     => $total,
        ]);

        return $order;
    }
}
