<?php

namespace Tests\Feature;

use App\Models\AdditionalGroup;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductControllerTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;
    private Restaurant $otro;
    private Category $categoria;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $plan = Plan::create(['name' => 'pro', 'display_name' => 'Pro', 'max_products' => 0]);

        $this->restaurant = Restaurant::create([
            'plan_id' => $plan->id, 'name' => 'Mío', 'slug' => 'mio', 'timezone' => 'America/Bogota',
        ]);

        $this->otro = Restaurant::create([
            'plan_id' => $plan->id, 'name' => 'Ajeno', 'slug' => 'ajeno', 'timezone' => 'America/Bogota',
        ]);

        $this->categoria = Category::create([
            'restaurant_id' => $this->restaurant->id, 'name' => 'Platos',
        ]);

        Sanctum::actingAs(User::create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Admin', 'email' => 'admin@test.com',
            'password' => 'password', 'role' => 'admin',
        ]));
    }

    public function test_crea_un_producto(): void
    {
        $response = $this->postJson('/api/products', [
            'category_id' => $this->categoria->id,
            'name'        => 'Bandeja Paisa',
            'price'       => 25000,
            'cost'        => 10000,
        ])->assertCreated();

        $this->assertSame('Bandeja Paisa', $response->json('name'));
        $this->assertTrue($response->json('is_available'));
        $this->assertDatabaseHas('products', [
            'name'          => 'Bandeja Paisa',
            'restaurant_id' => $this->restaurant->id,
        ]);
    }

    public function test_rechaza_una_categoria_de_otro_restaurante(): void
    {
        $ajena = Category::create(['restaurant_id' => $this->otro->id, 'name' => 'De otro']);

        $this->postJson('/api/products', [
            'category_id' => $ajena->id,
            'name'        => 'Colado',
            'price'       => 1000,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('products', ['name' => 'Colado']);
    }

    public function test_lista_filtrando_por_categoria_y_busqueda(): void
    {
        $bebidas = Category::create(['restaurant_id' => $this->restaurant->id, 'name' => 'Bebidas']);
        $this->producto('Bandeja Paisa');
        $this->producto('Jugo de mango', $bebidas);

        $this->getJson('/api/products')->assertOk()->assertJsonCount(2);
        $this->getJson("/api/products?category_id={$bebidas->id}")->assertOk()->assertJsonCount(1);
        $this->getJson('/api/products?search=Bandeja')->assertOk()->assertJsonCount(1);
    }

    public function test_sube_y_reencoda_la_imagen(): void
    {
        $response = $this->post('/api/products', [
            'category_id' => $this->categoria->id,
            'name'        => 'Con foto',
            'price'       => 1000,
            'image'       => UploadedFile::fake()->image('foto.png', 2000, 1500),
        ], ['Accept' => 'application/json'])->assertCreated();

        $url = $response->json('image_url');
        $this->assertNotNull($url);
        $this->assertStringEndsWith('.jpg', $url, 'Debe normalizarse a JPEG.');

        $path = substr($url, strpos($url, '/storage/') + strlen('/storage/'));
        Storage::disk('public')->assertExists($path);
    }

    public function test_al_reemplazar_la_imagen_borra_la_anterior(): void
    {
        $producto = $this->producto('Con foto');

        $primera = $this->putJson("/api/products/{$producto->id}", []);   // sin imagen aún
        $conFoto = $this->post("/api/products/{$producto->id}", [
            '_method'     => 'PUT',
            'image'       => UploadedFile::fake()->image('uno.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $urlVieja  = $conFoto->json('image_url');
        $pathViejo = substr($urlVieja, strpos($urlVieja, '/storage/') + strlen('/storage/'));
        Storage::disk('public')->assertExists($pathViejo);

        $conOtra = $this->post("/api/products/{$producto->id}", [
            '_method' => 'PUT',
            'image'   => UploadedFile::fake()->image('dos.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertNotSame($urlVieja, $conOtra->json('image_url'));
        Storage::disk('public')->assertMissing($pathViejo);
    }

    public function test_rechaza_un_archivo_que_no_es_imagen(): void
    {
        $this->post('/api/products', [
            'category_id' => $this->categoria->id,
            'name'        => 'Malicioso',
            'price'       => 1000,
            'image'       => UploadedFile::fake()->create('script.php', 10),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');
    }

    public function test_sincroniza_grupos_de_adicionales_ignorando_los_ajenos(): void
    {
        $mio   = AdditionalGroup::create(['restaurant_id' => $this->restaurant->id, 'name' => 'Salsas']);
        $ajeno = AdditionalGroup::create(['restaurant_id' => $this->otro->id,       'name' => 'De otro']);

        $response = $this->postJson('/api/products', [
            'category_id'          => $this->categoria->id,
            'name'                 => 'Con salsas',
            'price'                => 1000,
            'additional_group_ids' => [$mio->id, $ajeno->id],
        ])->assertCreated();

        $this->assertCount(1, $response->json('additional_groups'));
        $this->assertSame($mio->id, $response->json('additional_groups.0.id'));
    }

    public function test_respeta_el_limite_de_productos_del_plan(): void
    {
        $free = Plan::create(['name' => 'free', 'display_name' => 'Gratis', 'max_products' => 1]);
        $this->restaurant->update(['plan_id' => $free->id]);

        $this->producto('El único');

        $this->postJson('/api/products', [
            'category_id' => $this->categoria->id,
            'name'        => 'El de más',
            'price'       => 1000,
        ])
            ->assertStatus(403)
            ->assertJsonPath('limit', 1)
            ->assertJsonPath('current', 1);
    }

    public function test_no_permite_borrar_un_producto_ya_vendido(): void
    {
        $producto = $this->producto('Vendido');
        $this->vender($producto);

        $this->deleteJson("/api/products/{$producto->id}")->assertStatus(422);

        $this->assertDatabaseHas('products', ['id' => $producto->id]);
    }

    public function test_borra_un_producto_sin_ventas(): void
    {
        $producto = $this->producto('Sin ventas');

        $this->deleteJson("/api/products/{$producto->id}")->assertNoContent();

        $this->assertDatabaseMissing('products', ['id' => $producto->id]);
    }

    public function test_no_deja_tocar_productos_de_otro_restaurante(): void
    {
        $ajena  = Category::create(['restaurant_id' => $this->otro->id, 'name' => 'De otro']);
        $ajeno  = Product::create([
            'restaurant_id' => $this->otro->id,
            'category_id'   => $ajena->id,
            'name'          => 'Ajeno',
            'price'         => 1000,
        ]);

        $this->getJson("/api/products/{$ajeno->id}")->assertForbidden();
        $this->putJson("/api/products/{$ajeno->id}", ['name' => 'X'])->assertForbidden();
        $this->deleteJson("/api/products/{$ajeno->id}")->assertForbidden();
    }

    private function producto(string $nombre, ?Category $categoria = null): Product
    {
        return Product::create([
            'restaurant_id' => $this->restaurant->id,
            'category_id'   => ($categoria ?? $this->categoria)->id,
            'name'          => $nombre,
            'price'         => 25000,
        ]);
    }

    private function vender(Product $producto): void
    {
        $order = Order::create([
            'restaurant_id' => $this->restaurant->id,
            'type'          => 'dine_in',
            'status'        => 'closed',
            'subtotal'      => $producto->price,
            'total'         => $producto->price,
        ]);

        OrderItem::create([
            'order_id'     => $order->id,
            'product_id'   => $producto->id,
            'product_name' => $producto->name,
            'unit_price'   => $producto->price,
            'quantity'     => 1,
            'subtotal'     => $producto->price,
        ]);
    }
}
