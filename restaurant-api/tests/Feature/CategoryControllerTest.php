<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CategoryControllerTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;
    private Restaurant $otro;
    private User $admin;

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

        $this->admin = User::create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Admin', 'email' => 'admin@test.com',
            'password' => 'password', 'role' => 'admin',
        ]);

        Sanctum::actingAs($this->admin);
    }

    public function test_lista_solo_las_categorias_del_restaurante(): void
    {
        Category::create(['restaurant_id' => $this->restaurant->id, 'name' => 'Platos']);
        Category::create(['restaurant_id' => $this->otro->id,       'name' => 'De otro']);

        $response = $this->getJson('/api/categories')->assertOk();

        $this->assertCount(1, $response->json());
        $this->assertSame('Platos', $response->json('0.name'));
    }

    public function test_crea_una_categoria_asignando_el_restaurante_y_el_orden(): void
    {
        Category::create(['restaurant_id' => $this->restaurant->id, 'name' => 'Existente', 'sort_order' => 5]);

        $response = $this->postJson('/api/categories', ['name' => 'Bebidas'])
            ->assertCreated();

        $this->assertSame('Bebidas', $response->json('name'));
        $this->assertSame($this->restaurant->id, $response->json('restaurant_id'));
        $this->assertSame(6, $response->json('sort_order'), 'Debe ir al final de la lista.');
        $this->assertTrue($response->json('is_active'));
    }

    public function test_rechaza_una_categoria_sin_nombre(): void
    {
        $this->postJson('/api/categories', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_no_deja_ver_ni_editar_categorias_de_otro_restaurante(): void
    {
        $ajena = Category::create(['restaurant_id' => $this->otro->id, 'name' => 'De otro']);

        $this->getJson("/api/categories/{$ajena->id}")->assertForbidden();
        $this->putJson("/api/categories/{$ajena->id}", ['name' => 'Hackeada'])->assertForbidden();
        $this->deleteJson("/api/categories/{$ajena->id}")->assertForbidden();
    }

    public function test_actualiza_una_categoria(): void
    {
        $cat = Category::create(['restaurant_id' => $this->restaurant->id, 'name' => 'Platos']);

        $this->putJson("/api/categories/{$cat->id}", ['name' => 'Platos fuertes', 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('name', 'Platos fuertes')
            ->assertJsonPath('is_active', false);
    }

    public function test_no_permite_borrar_una_categoria_con_productos(): void
    {
        $cat = Category::create(['restaurant_id' => $this->restaurant->id, 'name' => 'Platos']);
        Product::create([
            'restaurant_id' => $this->restaurant->id,
            'category_id'   => $cat->id,
            'name'          => 'Bandeja Paisa',
            'price'         => 25000,
        ]);

        $this->deleteJson("/api/categories/{$cat->id}")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'No se puede eliminar: la categoría tiene 1 producto(s). Muévelos a otra categoría o desactiva la categoría.']);

        $this->assertDatabaseHas('categories', ['id' => $cat->id]);
    }

    public function test_borra_una_categoria_vacia(): void
    {
        $cat = Category::create(['restaurant_id' => $this->restaurant->id, 'name' => 'Vacía']);

        $this->deleteJson("/api/categories/{$cat->id}")->assertNoContent();

        $this->assertDatabaseMissing('categories', ['id' => $cat->id]);
    }
}
