<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Menú público y pedido desde el QR de la mesa.
 *
 * Todo aquí ocurre sin sesión: lo usa el comensal desde su propio móvil. Por
 * eso la mesa se identifica con `qr_code` —un uuid impreso en el adhesivo— y
 * no con `table_id`, que es secuencial: bastaría con cambiar el número en la
 * URL para pedir a cuenta de la mesa de al lado.
 */
class MenuPublicoTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;
    private Restaurant $otro;
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

        $this->otro = Restaurant::create([
            'plan_id' => $plan->id, 'name' => 'Ajeno', 'slug' => 'ajeno',
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
    }

    public function test_el_menu_publico_no_necesita_sesion(): void
    {
        $this->getJson('/api/menu/el-rincon')
            ->assertOk()
            ->assertJsonPath('restaurant.name', 'El Rincón')
            ->assertJsonPath('categories.0.products.0.name', 'Bandeja Paisa');
    }

    public function test_resuelve_la_mesa_por_su_codigo_qr(): void
    {
        $this->getJson("/api/menu/el-rincon?qr={$this->mesa->qr_code}")
            ->assertOk()
            ->assertJsonPath('table.number', '5')
            ->assertJsonPath('table.id', $this->mesa->id);
    }

    public function test_un_codigo_desconocido_no_resuelve_mesa(): void
    {
        $this->getJson('/api/menu/el-rincon?qr=' . Str::uuid())
            ->assertOk()
            ->assertJsonPath('table', null);
    }

    public function test_el_codigo_de_otro_restaurante_no_resuelve_mesa(): void
    {
        $ajena = RestaurantTable::create([
            'restaurant_id' => $this->otro->id, 'number' => '1', 'capacity' => 4,
            'qr_code' => Str::uuid()->toString(),
        ]);

        $this->getJson("/api/menu/el-rincon?qr={$ajena->qr_code}")
            ->assertOk()
            ->assertJsonPath('table', null);
    }

    public function test_el_menu_publico_oculta_lo_no_disponible(): void
    {
        Product::create([
            'restaurant_id' => $this->restaurant->id,
            'category_id'   => $this->producto->category_id,
            'name'          => 'Agotado', 'price' => 1000, 'is_available' => false,
        ]);

        $productos = $this->getJson('/api/menu/el-rincon')->json('categories.0.products');

        $this->assertCount(1, $productos);
        $this->assertSame('Bandeja Paisa', $productos[0]['name']);
    }

    public function test_crea_el_pedido_con_el_codigo_qr(): void
    {
        $response = $this->postJson('/api/orders/qr', [
            'restaurant_slug' => 'el-rincon',
            'qr_code'         => $this->mesa->qr_code,
            'items'           => [['product_id' => $this->producto->id, 'quantity' => 2]],
        ])->assertCreated();

        $this->assertSame('dine_in', $response->json('type'));
        // Nace como propuesta: el mesero lo confirma antes de que baje a cocina
        // (ver PropuestaQrTest).
        $this->assertSame('proposed', $response->json('status'));
        $this->assertEquals(50000, $response->json('total'));

        $pedido = Order::first();
        $this->assertSame($this->mesa->id, $pedido->table_id);
        // Lo origina el comensal, no el personal.
        $this->assertNull($pedido->user_id);
    }

    public function test_el_codigo_qr_de_otra_mesa_no_sirve_para_pedir(): void
    {
        $ajena = RestaurantTable::create([
            'restaurant_id' => $this->otro->id, 'number' => '9', 'capacity' => 4,
            'qr_code' => Str::uuid()->toString(),
        ]);

        $this->postJson('/api/orders/qr', [
            'restaurant_slug' => 'el-rincon',
            'qr_code'         => $ajena->qr_code,
            'items'           => [['product_id' => $this->producto->id, 'quantity' => 1]],
        ])->assertStatus(422);

        $this->assertSame(0, Order::count());
    }

    public function test_exige_identificar_la_mesa_de_alguna_forma(): void
    {
        $this->postJson('/api/orders/qr', [
            'restaurant_slug' => 'el-rincon',
            'items'           => [['product_id' => $this->producto->id, 'quantity' => 1]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['qr_code', 'table_id']);
    }

    public function test_ocupa_la_mesa_al_pedir(): void
    {
        $this->postJson('/api/orders/qr', [
            'restaurant_slug' => 'el-rincon',
            'qr_code'         => $this->mesa->qr_code,
            'items'           => [['product_id' => $this->producto->id, 'quantity' => 1]],
        ])->assertCreated();

        $this->assertSame('occupied', $this->mesa->fresh()->status);
    }

    public function test_no_deja_pedir_productos_de_otro_restaurante(): void
    {
        $categoriaAjena = Category::create(['restaurant_id' => $this->otro->id, 'name' => 'Suyo']);
        $ajeno = Product::create([
            'restaurant_id' => $this->otro->id, 'category_id' => $categoriaAjena->id,
            'name' => 'Ajeno', 'price' => 5000,
        ]);

        $this->postJson('/api/orders/qr', [
            'restaurant_slug' => 'el-rincon',
            'qr_code'         => $this->mesa->qr_code,
            'items'           => [['product_id' => $ajeno->id, 'quantity' => 1]],
        ])->assertStatus(422);

        $this->assertSame(0, Order::count());
    }

    public function test_un_restaurante_inactivo_no_atiende(): void
    {
        $this->restaurant->update(['is_active' => false]);

        $this->getJson('/api/menu/el-rincon')->assertNotFound();
    }
}
