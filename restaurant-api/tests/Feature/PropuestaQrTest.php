<?php

namespace Tests\Feature;

use App\Events\OrderCreated;
use App\Models\Category;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\RestaurantSetting;
use App\Models\RestaurantTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * El comensal arma el pedido desde el QR mientras espera; el mesero lo confirma
 * al pasar por la mesa y solo entonces baja a cocina.
 *
 * Es opcional por restaurante, pero viene activado: que alguien sentado en una
 * mesa mande comanda directa a cocina sin que nadie mire debe ser una decisión
 * consciente, no el comportamiento por omisión.
 */
class PropuestaQrTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;
    private RestaurantTable $mesa;
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
            'qr_code' => Str::uuid()->toString(),
        ]);
    }

    private function pedirDesdeQr()
    {
        return $this->postJson('/api/orders/qr', [
            'restaurant_slug' => 'el-rincon',
            'qr_code'         => $this->mesa->qr_code,
            'items'           => [['product_id' => $this->producto->id, 'quantity' => 2]],
        ]);
    }

    private function comoMesero(): void
    {
        Sanctum::actingAs(User::create([
            'restaurant_id' => $this->restaurant->id, 'name' => 'Mozo',
            'email' => 'mozo@test.com', 'password' => 'password', 'role' => 'waiter',
        ]));
    }

    public function test_el_pedido_del_qr_nace_como_propuesta(): void
    {
        $this->pedirDesdeQr()->assertCreated()->assertJsonPath('status', 'proposed');

        $pedido = Order::first();
        $this->assertSame('proposed', $pedido->status);
        $this->assertNull($pedido->confirmed_at, 'Todavía no lo ha visto nadie.');
        $this->assertNull($pedido->user_id);
    }

    public function test_el_mesero_lo_confirma_y_baja_a_cocina(): void
    {
        $id = $this->pedirDesdeQr()->json('id');
        $this->comoMesero();

        $this->patchJson("/api/orders/{$id}/status", ['status' => 'pending'])
            ->assertOk()
            ->assertJsonPath('status', 'pending');

        $pedido = Order::find($id);
        $this->assertNotNull($pedido->confirmed_at, 'Confirmar debe sellar confirmed_at.');
    }

    public function test_el_mesero_puede_descartar_la_propuesta(): void
    {
        $id = $this->pedirDesdeQr()->json('id');
        $this->comoMesero();

        $this->patchJson("/api/orders/{$id}/status", ['status' => 'cancelled'])->assertOk();

        $this->assertSame('cancelled', Order::find($id)->status);
    }

    /** Una propuesta no es trabajo para cocina: no puede saltarse al mesero. */
    public function test_una_propuesta_no_puede_pasar_directo_a_preparacion(): void
    {
        $id = $this->pedirDesdeQr()->json('id');
        $this->comoMesero();

        $this->patchJson("/api/orders/{$id}/status", ['status' => 'preparing'])
            ->assertStatus(422);

        $this->assertSame('proposed', Order::find($id)->status);
    }

    public function test_la_propuesta_avisa_al_mesero_no_a_cocina(): void
    {
        Event::fake([OrderCreated::class]);

        $this->pedirDesdeQr()->assertCreated();

        Event::assertDispatched(OrderCreated::class, function (OrderCreated $e) {
            $canales = implode(',', array_map(fn($c) => $c->name, $e->broadcastOn()));

            return str_contains($canales, '.waiters') && !str_contains($canales, '.kitchen');
        });
    }

    public function test_con_la_confirmacion_desactivada_va_directo_a_cocina(): void
    {
        RestaurantSetting::create([
            'restaurant_id' => $this->restaurant->id,
            'key_name'      => 'qr_confirm',
            'value'         => '0',
        ]);

        $this->pedirDesdeQr()->assertCreated()->assertJsonPath('status', 'pending');
    }

    public function test_el_pedido_que_toma_el_personal_no_pasa_por_propuesta(): void
    {
        $this->comoMesero();

        $this->postJson('/api/orders', [
            'type'     => 'dine_in',
            'table_id' => $this->mesa->id,
            'items'    => [['product_id' => $this->producto->id, 'quantity' => 1]],
        ])
            ->assertCreated()
            ->assertJsonPath('status', 'pending');
    }

    public function test_la_mesa_queda_ocupada_aunque_esté_sin_confirmar(): void
    {
        $this->pedirDesdeQr()->assertCreated();

        // El comensal está sentado ahí: al mesero le sirve verlo en el plano.
        $this->assertSame('occupied', $this->mesa->fresh()->status);
    }
}
