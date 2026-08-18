<?php

namespace Tests\Feature;

use App\Models\Category;
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
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductIngredientControllerTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;
    private Restaurant $otro;
    private Product $bandeja;
    private Ingredient $arroz;
    private Ingredient $carne;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = Plan::create([
            'name' => 'pro', 'display_name' => 'Pro', 'has_inventory' => true,
        ]);

        $this->restaurant = Restaurant::create([
            'plan_id' => $plan->id, 'name' => 'Mío', 'slug' => 'mio', 'timezone' => 'America/Bogota',
        ]);

        $this->otro = Restaurant::create([
            'plan_id' => $plan->id, 'name' => 'Ajeno', 'slug' => 'ajeno', 'timezone' => 'America/Bogota',
        ]);

        Sanctum::actingAs(User::create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Admin', 'email' => 'admin@test.com',
            'password' => 'password', 'role' => 'admin',
        ]));

        $categoria = Category::create(['restaurant_id' => $this->restaurant->id, 'name' => 'Platos']);

        $this->bandeja = Product::create([
            'restaurant_id' => $this->restaurant->id, 'category_id' => $categoria->id,
            'name' => 'Bandeja Paisa', 'price' => 25000, 'cost' => 9000,
        ]);

        $this->arroz = $this->ingrediente('Arroz', 'kg', 3000, 20);
        $this->carne = $this->ingrediente('Carne', 'kg', 22000, 10);
    }

    public function test_define_la_receta_y_calcula_su_costo(): void
    {
        $response = $this->putJson("/api/products/{$this->bandeja->id}/ingredients", [
            'ingredients' => [
                ['ingredient_id' => $this->arroz->id, 'quantity' => 0.2],   // 0.2 × 3000 = 600
                ['ingredient_id' => $this->carne->id, 'quantity' => 0.15],  // 0.15 × 22000 = 3300
            ],
        ])->assertOk();

        $this->assertCount(2, $response->json('ingredients'));
        $this->assertEquals(3900, $response->json('calculated_cost'));
        $this->assertEquals(9000, $response->json('registered_cost'));
        $this->assertEquals(5100, $response->json('cost_difference'));

        $this->assertDatabaseCount('product_ingredients', 2);
    }

    public function test_devuelve_el_detalle_de_cada_linea(): void
    {
        $this->putJson("/api/products/{$this->bandeja->id}/ingredients", [
            'ingredients' => [['ingredient_id' => $this->carne->id, 'quantity' => 0.15]],
        ])->assertOk();

        $linea = $this->getJson("/api/products/{$this->bandeja->id}/ingredients")
            ->assertOk()
            ->json('ingredients.0');

        $this->assertSame('Carne', $linea['name']);
        $this->assertSame('kg', $linea['unit']);
        $this->assertEquals(0.15, $linea['quantity']);
        $this->assertEquals(3300, $linea['line_cost']);
        $this->assertEquals(10, $linea['current_stock']);
    }

    public function test_reemplaza_la_receta_completa(): void
    {
        $this->putJson("/api/products/{$this->bandeja->id}/ingredients", [
            'ingredients' => [
                ['ingredient_id' => $this->arroz->id, 'quantity' => 0.2],
                ['ingredient_id' => $this->carne->id, 'quantity' => 0.15],
            ],
        ])->assertOk();

        // Solo arroz en el segundo envío: la carne debe desaparecer.
        $response = $this->putJson("/api/products/{$this->bandeja->id}/ingredients", [
            'ingredients' => [['ingredient_id' => $this->arroz->id, 'quantity' => 0.3]],
        ])->assertOk();

        $this->assertCount(1, $response->json('ingredients'));
        $this->assertEquals(900, $response->json('calculated_cost'));
        $this->assertDatabaseCount('product_ingredients', 1);
    }

    public function test_una_lista_vacia_borra_la_receta(): void
    {
        ProductIngredient::create([
            'product_id' => $this->bandeja->id, 'ingredient_id' => $this->arroz->id, 'quantity' => 0.2,
        ]);

        $this->putJson("/api/products/{$this->bandeja->id}/ingredients", ['ingredients' => []])
            ->assertOk()
            ->assertJsonCount(0, 'ingredients');

        $this->assertDatabaseCount('product_ingredients', 0);
    }

    public function test_rechaza_ingredientes_repetidos(): void
    {
        $this->putJson("/api/products/{$this->bandeja->id}/ingredients", [
            'ingredients' => [
                ['ingredient_id' => $this->arroz->id, 'quantity' => 0.2],
                ['ingredient_id' => $this->arroz->id, 'quantity' => 0.3],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ingredients.1.ingredient_id');
    }

    public function test_rechaza_cantidades_no_positivas(): void
    {
        $this->putJson("/api/products/{$this->bandeja->id}/ingredients", [
            'ingredients' => [['ingredient_id' => $this->arroz->id, 'quantity' => 0]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ingredients.0.quantity');
    }

    public function test_rechaza_ingredientes_de_otro_restaurante(): void
    {
        $ajeno = Ingredient::create([
            'restaurant_id' => $this->otro->id, 'name' => 'Ajeno', 'unit' => 'kg',
            'stock' => 5, 'cost_per_unit' => 1000,
        ]);

        $this->putJson("/api/products/{$this->bandeja->id}/ingredients", [
            'ingredients' => [['ingredient_id' => $ajeno->id, 'quantity' => 1]],
        ])
            ->assertStatus(422)
            ->assertJsonPath('invalid_ids', [$ajeno->id]);

        $this->assertDatabaseCount('product_ingredients', 0);
    }

    public function test_no_deja_tocar_recetas_de_productos_ajenos(): void
    {
        $categoriaAjena = Category::create(['restaurant_id' => $this->otro->id, 'name' => 'De otro']);
        $ajeno = Product::create([
            'restaurant_id' => $this->otro->id, 'category_id' => $categoriaAjena->id,
            'name' => 'Ajeno', 'price' => 1000,
        ]);

        $this->getJson("/api/products/{$ajeno->id}/ingredients")->assertForbidden();
        $this->putJson("/api/products/{$ajeno->id}/ingredients", ['ingredients' => []])->assertForbidden();
    }

    public function test_requiere_que_el_plan_incluya_inventario(): void
    {
        $free = Plan::create(['name' => 'free', 'display_name' => 'Gratis', 'has_inventory' => false]);
        $this->restaurant->update(['plan_id' => $free->id]);

        $this->getJson("/api/products/{$this->bandeja->id}/ingredients")->assertStatus(403);
    }

    /**
     * El cierre del círculo: una receta creada por la API hace que el pedido
     * descuente stock de verdad. Antes esto solo funcionaba sembrando a mano.
     */
    public function test_la_receta_creada_por_la_api_descuenta_stock_al_cerrar(): void
    {
        $this->putJson("/api/products/{$this->bandeja->id}/ingredients", [
            'ingredients' => [['ingredient_id' => $this->arroz->id, 'quantity' => 0.5]],
        ])->assertOk();

        $mesa = RestaurantTable::create([
            'restaurant_id' => $this->restaurant->id, 'number' => '1',
            'capacity' => 4, 'qr_code' => Str::uuid()->toString(), 'status' => 'occupied',
        ]);

        $pedido = Order::create([
            'restaurant_id' => $this->restaurant->id, 'table_id' => $mesa->id,
            'type' => 'dine_in', 'status' => 'delivered',
            'subtotal' => 50000, 'total' => 50000,
        ]);

        OrderItem::create([
            'order_id' => $pedido->id, 'product_id' => $this->bandeja->id,
            'product_name' => $this->bandeja->name, 'unit_price' => 25000,
            'quantity' => 2, 'subtotal' => 50000,
        ]);

        $this->patchJson("/api/orders/{$pedido->id}/status", ['status' => 'closed'])->assertOk();

        // 2 unidades × 0.5 kg = 1 kg descontado de los 20 iniciales.
        $this->assertEquals(19, $this->arroz->fresh()->stock);
        $this->assertDatabaseHas('inventory_movements', [
            'ingredient_id' => $this->arroz->id,
            'type'          => 'out',
            'order_id'      => $pedido->id,
        ]);
    }

    private function ingrediente(string $nombre, string $unidad, float $costo, float $stock): Ingredient
    {
        return Ingredient::create([
            'restaurant_id' => $this->restaurant->id,
            'name'          => $nombre,
            'unit'          => $unidad,
            'cost_per_unit' => $costo,
            'stock'         => $stock,
            'min_stock'     => 1,
        ]);
    }
}
