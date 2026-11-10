<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\RestaurantSetting;
use App\Models\RestaurantTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Lo que le toca pagar a cada comensal.
 *
 * No hay que repartir nada a ojo: desde el QR cada comensal abre su propio
 * pedido, así que "lo de Ana" ya viene separado en la base. El reparto solo
 * agrupa por `diner_name` y suma, y el cobro parcial se pide por ids — el
 * reparto ya dice cuáles son de quién.
 */
class RepartoCuentaTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;
    private RestaurantTable $mesa;
    private Product $producto;
    private User $cajero;

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
            'name' => 'Bandeja Paisa', 'price' => 25000, 'cost' => 10000, 'is_available' => true,
        ]);

        $this->mesa = RestaurantTable::create([
            'restaurant_id' => $this->restaurant->id, 'number' => '5', 'capacity' => 4,
            'qr_code' => Str::uuid()->toString(), 'status' => 'occupied',
        ]);

        RestaurantSetting::create([
            'restaurant_id' => $this->restaurant->id, 'key_name' => 'qr_confirm', 'value' => '0',
        ]);

        $this->cajero = User::create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Cajero', 'email' => 'caja@test.com',
            'password' => 'password', 'role' => 'cashier',
        ]);
    }

    // ── El reparto, tal como lo ve el comensal ──────────────────────────────

    public function test_el_comensal_ve_lo_que_le_toca_a_cada_uno(): void
    {
        $this->pedido('Ana', 25000);
        $this->pedido('Luis', 50000);
        $this->pedido('Ana', 8000);

        $json = $this->getJson("/api/menu/el-rincon/mesa/{$this->mesa->qr_code}")
            ->assertOk()
            ->assertJsonPath('total', 83000)
            ->assertJsonCount(2, 'diners')
            ->json('diners');

        // Los dos pedidos de Ana se suman en uno solo: es una persona, no dos.
        $this->assertSame('Ana', $json[0]['diner_name']);
        $this->assertSame(33000, $json[0]['total']);
        $this->assertCount(2, $json[0]['orders']);

        $this->assertSame('Luis', $json[1]['diner_name']);
        $this->assertSame(50000, $json[1]['total']);
    }

    public function test_el_mismo_nombre_escrito_distinto_es_la_misma_persona(): void
    {
        $this->pedido('Ana', 25000);
        $this->pedido('  ana  ', 10000);

        $diners = $this->getJson("/api/menu/el-rincon/mesa/{$this->mesa->qr_code}")
            ->assertOk()
            ->assertJsonCount(1, 'diners')
            ->json('diners');

        // Se agrupa normalizado pero se muestra como lo escribió el primero.
        $this->assertSame('Ana', $diners[0]['diner_name']);
        $this->assertSame(35000, $diners[0]['total']);
    }

    public function test_los_pedidos_sin_nombre_van_a_un_solo_grupo_y_al_final(): void
    {
        $this->pedido(null, 10000);
        $this->pedido('Ana', 25000);
        $this->pedido(null, 5000);

        $diners = $this->getJson("/api/menu/el-rincon/mesa/{$this->mesa->qr_code}")
            ->assertOk()
            ->assertJsonCount(2, 'diners')
            ->json('diners');

        // Si nadie escribió su nombre no se puede saber si son la misma
        // persona; fingir dos deudores sería inventarse un dato.
        $this->assertSame('Ana', $diners[0]['diner_name']);
        $this->assertNull($diners[1]['diner_name'], 'El cajón de sastre va al final.');
        $this->assertSame(15000, $diners[1]['total']);
    }

    public function test_una_mesa_vacia_no_tiene_a_quien_repartir(): void
    {
        $this->getJson("/api/menu/el-rincon/mesa/{$this->mesa->qr_code}")
            ->assertOk()
            ->assertJsonCount(0, 'diners')
            ->assertJsonPath('total', 0);
    }

    // ── El cobro parcial ────────────────────────────────────────────────────

    public function test_cobra_solo_lo_de_un_comensal_y_deja_la_mesa_abierta(): void
    {
        $ana  = $this->pedido('Ana', 25000);
        $luis = $this->pedido('Luis', 50000);

        Sanctum::actingAs($this->cajero);

        $this->postJson("/api/payments/table/{$this->mesa->id}", [
            'method'    => 'cash',
            'amount'    => 25000,
            'order_ids' => [$ana->id],
        ])
            ->assertCreated()
            ->assertJsonPath('total', 25000)
            ->assertJsonCount(1, 'payments')
            // Lo que sigue debiendo la mesa, para que la caja no pregunte.
            ->assertJsonPath('pending.0.diner_name', 'Luis')
            ->assertJsonPath('pending.0.total', 50000);

        $this->assertSame('closed', $ana->fresh()->status);
        $this->assertSame('pending', $luis->fresh()->status);

        // La mesa sigue ocupada: Luis no ha pagado.
        $this->assertSame('occupied', $this->mesa->fresh()->status);
    }

    public function test_al_cobrar_el_ultimo_comensal_se_libera_la_mesa(): void
    {
        $ana  = $this->pedido('Ana', 25000);
        $luis = $this->pedido('Luis', 50000);

        Sanctum::actingAs($this->cajero);

        $this->postJson("/api/payments/table/{$this->mesa->id}", [
            'method' => 'cash', 'amount' => 25000, 'order_ids' => [$ana->id],
        ])->assertCreated();

        $this->postJson("/api/payments/table/{$this->mesa->id}", [
            'method' => 'cash', 'amount' => 50000, 'order_ids' => [$luis->id],
        ])
            ->assertCreated()
            ->assertJsonPath('table.status', 'available')
            ->assertJsonCount(0, 'pending');

        $this->assertSame('available', $this->mesa->fresh()->status);
    }

    public function test_varios_comensales_pueden_pagar_juntos(): void
    {
        $ana  = $this->pedido('Ana', 25000);
        $luis = $this->pedido('Luis', 50000);
        $this->pedido('Sara', 10000);

        Sanctum::actingAs($this->cajero);

        // Una lista de ids expresa "Ana y Luis pagan juntos" sin que eso sea
        // un caso especial en el servidor.
        $this->postJson("/api/payments/table/{$this->mesa->id}", [
            'method' => 'cash', 'amount' => 75000, 'order_ids' => [$ana->id, $luis->id],
        ])
            ->assertCreated()
            ->assertJsonPath('total', 75000)
            ->assertJsonCount(2, 'payments')
            ->assertJsonCount(1, 'pending')
            ->assertJsonPath('pending.0.diner_name', 'Sara');
    }

    public function test_sin_ids_sigue_cobrando_la_mesa_entera(): void
    {
        $this->pedido('Ana', 25000);
        $this->pedido('Luis', 50000);

        Sanctum::actingAs($this->cajero);

        $this->postJson("/api/payments/table/{$this->mesa->id}", [
            'method' => 'cash', 'amount' => 75000,
        ])
            ->assertCreated()
            ->assertJsonPath('total', 75000)
            ->assertJsonPath('table.status', 'available');
    }

    public function test_no_cobra_un_pedido_de_otra_mesa(): void
    {
        $ana = $this->pedido('Ana', 25000);

        $otra = RestaurantTable::create([
            'restaurant_id' => $this->restaurant->id, 'number' => '6', 'capacity' => 2,
            'qr_code' => Str::uuid()->toString(), 'status' => 'occupied',
        ]);

        $ajeno = Order::create([
            'restaurant_id' => $this->restaurant->id, 'table_id' => $otra->id,
            'type' => 'dine_in', 'status' => 'pending', 'subtotal' => 9000, 'total' => 9000,
        ]);

        Sanctum::actingAs($this->cajero);

        $this->postJson("/api/payments/table/{$this->mesa->id}", [
            'method' => 'cash', 'amount' => 34000, 'order_ids' => [$ana->id, $ajeno->id],
        ])->assertStatus(422);

        // Si uno falla no se cobra nada: medio cobro es peor que ninguno.
        $this->assertSame(0, Payment::count());
        $this->assertSame('pending', $ana->fresh()->status);
    }

    public function test_no_cobra_dos_veces_el_mismo_pedido(): void
    {
        $ana = $this->pedido('Ana', 25000);
        $this->pedido('Luis', 50000);

        Sanctum::actingAs($this->cajero);

        $this->postJson("/api/payments/table/{$this->mesa->id}", [
            'method' => 'cash', 'amount' => 25000, 'order_ids' => [$ana->id],
        ])->assertCreated();

        $this->postJson("/api/payments/table/{$this->mesa->id}", [
            'method' => 'cash', 'amount' => 25000, 'order_ids' => [$ana->id],
        ])->assertStatus(422);

        $this->assertSame(1, Payment::count());
    }

    public function test_el_monto_tiene_que_cubrir_lo_del_comensal_no_la_mesa(): void
    {
        $ana = $this->pedido('Ana', 25000);
        $this->pedido('Luis', 50000);

        Sanctum::actingAs($this->cajero);

        // 25.000 no cubre la mesa (75.000) pero sí lo de Ana.
        $this->postJson("/api/payments/table/{$this->mesa->id}", [
            'method' => 'cash', 'amount' => 25000, 'order_ids' => [$ana->id],
        ])->assertCreated();

        $otro = $this->pedido('Sara', 30000);

        $this->postJson("/api/payments/table/{$this->mesa->id}", [
            'method' => 'cash', 'amount' => 20000, 'order_ids' => [$otro->id],
        ])
            ->assertStatus(422)
            ->assertJsonPath('total', 30000);
    }

    public function test_la_propuesta_de_otro_no_impide_cobrar_lo_tuyo(): void
    {
        $ana = $this->pedido('Ana', 25000);
        $this->pedido('Luis', 50000)->update(['status' => 'proposed']);

        Sanctum::actingAs($this->cajero);

        // Cobrando la mesa entera, una propuesta sin confirmar sí estorba.
        $this->postJson("/api/payments/table/{$this->mesa->id}", [
            'method' => 'cash', 'amount' => 75000,
        ])->assertStatus(422);

        // Pero que Luis no haya confirmado no es asunto de Ana: aquí la mesa
        // se queda abierta a propósito.
        $this->postJson("/api/payments/table/{$this->mesa->id}", [
            'method' => 'cash', 'amount' => 25000, 'order_ids' => [$ana->id],
        ])->assertCreated();

        $this->assertSame('occupied', $this->mesa->fresh()->status);
    }

    public function test_no_cobra_la_propuesta_del_propio_comensal(): void
    {
        $ana = $this->pedido('Ana', 25000);
        $ana->update(['status' => 'proposed']);

        Sanctum::actingAs($this->cajero);

        $this->postJson("/api/payments/table/{$this->mesa->id}", [
            'method' => 'cash', 'amount' => 25000, 'order_ids' => [$ana->id],
        ])
            ->assertStatus(422)
            ->assertJsonPath('proposed.0', $ana->id);
    }

    private function pedido(?string $comensal, float $total): Order
    {
        $order = Order::create([
            'restaurant_id' => $this->restaurant->id,
            'table_id'      => $this->mesa->id,
            'type'          => 'dine_in',
            'status'        => 'pending',
            'diner_name'    => $comensal,
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
