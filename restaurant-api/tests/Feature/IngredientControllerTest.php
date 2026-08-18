<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductIngredient;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IngredientControllerTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;
    private Restaurant $otro;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = Plan::create(['name' => 'pro', 'display_name' => 'Pro', 'has_inventory' => true]);

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
    }

    public function test_crea_un_ingrediente_y_registra_el_stock_inicial_como_movimiento(): void
    {
        $response = $this->postJson('/api/ingredients', [
            'name'          => 'Arroz',
            'unit'          => 'kg',
            'stock'         => 25,
            'min_stock'     => 5,
            'cost_per_unit' => 3200.5678,
        ])->assertCreated();

        $this->assertSame('Arroz', $response->json('name'));

        $mov = InventoryMovement::where('ingredient_id', $response->json('id'))->first();
        $this->assertNotNull($mov, 'El stock inicial debe quedar en el historial.');
        $this->assertSame('in', $mov->type);
        $this->assertEquals(0, $mov->stock_before);
        $this->assertEquals(25, $mov->stock_after);
        $this->assertSame('Stock inicial', $mov->reason);
    }

    public function test_sin_stock_inicial_no_crea_movimiento(): void
    {
        $this->postJson('/api/ingredients', ['name' => 'Sal', 'unit' => 'kg'])
            ->assertCreated();

        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_conserva_los_cuatro_decimales_del_costo_unitario(): void
    {
        $response = $this->postJson('/api/ingredients', [
            'name' => 'Azafrán', 'unit' => 'g', 'cost_per_unit' => 1234.5678,
        ])->assertCreated();

        // Con el cast anterior (decimal:3) el cuarto decimal se perdía.
        $this->assertSame('1234.5678', $response->json('cost_per_unit'));
    }

    public function test_expone_el_indicador_de_stock_bajo(): void
    {
        $this->ingrediente('Arroz', stock: 2, minimo: 5);
        $this->ingrediente('Sal', stock: 50, minimo: 5);

        $response = $this->getJson('/api/ingredients')->assertOk();

        $porNombre = collect($response->json())->keyBy('name');
        $this->assertTrue($porNombre['Arroz']['low_stock']);
        $this->assertFalse($porNombre['Sal']['low_stock']);
    }

    public function test_filtra_los_que_estan_bajo_minimo(): void
    {
        $this->ingrediente('Arroz', stock: 2, minimo: 5);
        $this->ingrediente('Sal', stock: 50, minimo: 5);

        $this->getJson('/api/ingredients?low_stock=true')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Arroz');
    }

    public function test_no_permite_editar_el_stock_directamente(): void
    {
        $arroz = $this->ingrediente('Arroz', stock: 10, minimo: 2);

        $this->putJson("/api/ingredients/{$arroz->id}", [
            'name'  => 'Arroz premium',
            'stock' => 9999,
        ])->assertOk();

        $arroz->refresh();
        $this->assertSame('Arroz premium', $arroz->name);
        $this->assertEquals(10, $arroz->stock, 'El stock solo cambia por movimientos.');
    }

    public function test_no_permite_borrar_un_ingrediente_usado_en_una_receta(): void
    {
        $arroz = $this->ingrediente('Arroz', stock: 0, minimo: 0);

        $categoria = Category::create(['restaurant_id' => $this->restaurant->id, 'name' => 'Platos']);
        $producto  = Product::create([
            'restaurant_id' => $this->restaurant->id,
            'category_id'   => $categoria->id,
            'name'          => 'Bandeja',
            'price'         => 25000,
        ]);
        ProductIngredient::create([
            'product_id' => $producto->id, 'ingredient_id' => $arroz->id, 'quantity' => 0.5,
        ]);

        $this->deleteJson("/api/ingredients/{$arroz->id}")->assertStatus(422);

        $this->assertDatabaseHas('ingredients', ['id' => $arroz->id]);
    }

    public function test_no_permite_borrar_un_ingrediente_con_historial(): void
    {
        $response = $this->postJson('/api/ingredients', [
            'name' => 'Arroz', 'unit' => 'kg', 'stock' => 10,
        ])->assertCreated();

        // El propio stock inicial ya generó un movimiento.
        $this->deleteJson("/api/ingredients/{$response->json('id')}")->assertStatus(422);
    }

    public function test_borra_un_ingrediente_sin_recetas_ni_historial(): void
    {
        $sal = $this->ingrediente('Sal', stock: 0, minimo: 0);

        $this->deleteJson("/api/ingredients/{$sal->id}")->assertNoContent();

        $this->assertDatabaseMissing('ingredients', ['id' => $sal->id]);
    }

    public function test_no_deja_tocar_ingredientes_de_otro_restaurante(): void
    {
        $ajeno = Ingredient::create([
            'restaurant_id' => $this->otro->id, 'name' => 'Ajeno', 'unit' => 'kg',
        ]);

        $this->getJson("/api/ingredients/{$ajeno->id}")->assertForbidden();
        $this->putJson("/api/ingredients/{$ajeno->id}", ['name' => 'X'])->assertForbidden();
        $this->deleteJson("/api/ingredients/{$ajeno->id}")->assertForbidden();
    }

    private function ingrediente(string $nombre, float $stock, float $minimo): Ingredient
    {
        return Ingredient::create([
            'restaurant_id' => $this->restaurant->id,
            'name'          => $nombre,
            'unit'          => 'kg',
            'stock'         => $stock,
            'min_stock'     => $minimo,
        ]);
    }
}
