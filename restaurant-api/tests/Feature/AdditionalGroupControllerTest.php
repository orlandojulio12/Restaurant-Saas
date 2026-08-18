<?php

namespace Tests\Feature;

use App\Models\Additional;
use App\Models\AdditionalGroup;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemAdditional;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdditionalGroupControllerTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;
    private Restaurant $otro;

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
            'name' => 'Admin', 'email' => 'admin@test.com',
            'password' => 'password', 'role' => 'admin',
        ]));
    }

    public function test_crea_un_grupo_con_sus_adicionales(): void
    {
        $response = $this->postJson('/api/additional-groups', [
            'name'           => 'Salsas',
            'selection_type' => 'multiple',
            'is_required'    => false,
            'additionals'    => [
                ['name' => 'Ají',      'extra_price' => 1000],
                ['name' => 'Tártara',  'extra_price' => 1500],
            ],
        ])->assertCreated();

        $this->assertSame('Salsas', $response->json('name'));
        $this->assertSame('multiple', $response->json('selection_type'));
        $this->assertCount(2, $response->json('additionals'));
        $this->assertSame($this->restaurant->id, $response->json('restaurant_id'));
        $this->assertDatabaseCount('additionals', 2);
    }

    public function test_crea_un_grupo_sin_adicionales(): void
    {
        $this->postJson('/api/additional-groups', ['name' => 'Vacío'])
            ->assertCreated()
            ->assertJsonPath('selection_type', 'single')
            ->assertJsonCount(0, 'additionals');
    }

    public function test_exige_nombre_en_cada_adicional(): void
    {
        $this->postJson('/api/additional-groups', [
            'name'        => 'Salsas',
            'additionals' => [['extra_price' => 1000]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('additionals.0.name');
    }

    public function test_sincroniza_adicionales_creando_actualizando_y_borrando(): void
    {
        $grupo = $this->grupoConSalsas();
        [$aji, $tartara] = $grupo->additionals->all();

        $this->putJson("/api/additional-groups/{$grupo->id}", [
            'additionals' => [
                ['id' => $aji->id, 'name' => 'Ají picante', 'extra_price' => 2000],  // actualiza
                ['name' => 'Piña', 'extra_price' => 500],                            // crea
                // tártara ausente → se borra
            ],
        ])->assertOk();

        $nombres = Additional::where('group_id', $grupo->id)->pluck('name')->sort()->values()->all();

        $this->assertSame(['Ají picante', 'Piña'], $nombres);
        $this->assertEquals(2000, $aji->fresh()->extra_price);
        $this->assertNull($tartara->fresh(), 'La tártara debía borrarse.');
    }

    public function test_editar_solo_el_grupo_no_borra_sus_adicionales(): void
    {
        $grupo = $this->grupoConSalsas();

        $this->putJson("/api/additional-groups/{$grupo->id}", ['name' => 'Salsas de la casa'])
            ->assertOk()
            ->assertJsonPath('name', 'Salsas de la casa')
            ->assertJsonCount(2, 'additionals');
    }

    public function test_un_adicional_ya_pedido_se_desactiva_en_vez_de_borrarse(): void
    {
        $grupo = $this->grupoConSalsas();
        [$aji, $tartara] = $grupo->additionals->all();
        $this->pedir($aji);

        // Se envía la lista sin el ají: no puede borrarse porque está en un pedido.
        $this->putJson("/api/additional-groups/{$grupo->id}", [
            'additionals' => [['id' => $tartara->id, 'name' => 'Tártara', 'extra_price' => 1500]],
        ])->assertOk();

        $aji->refresh();
        $this->assertNotNull($aji, 'No debe borrarse: rompería el histórico del pedido.');
        $this->assertFalse((bool) $aji->is_available, 'Debe quedar desactivado.');
    }

    public function test_no_permite_borrar_un_grupo_con_adicionales_ya_pedidos(): void
    {
        $grupo = $this->grupoConSalsas();
        $this->pedir($grupo->additionals->first());

        $this->deleteJson("/api/additional-groups/{$grupo->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('additional_groups', ['id' => $grupo->id]);
    }

    public function test_borra_un_grupo_sin_historial_y_arrastra_sus_adicionales(): void
    {
        $grupo = $this->grupoConSalsas();

        $this->deleteJson("/api/additional-groups/{$grupo->id}")->assertNoContent();

        $this->assertDatabaseMissing('additional_groups', ['id' => $grupo->id]);
        $this->assertSame(0, Additional::where('group_id', $grupo->id)->count());
    }

    public function test_no_deja_tocar_grupos_de_otro_restaurante(): void
    {
        $ajeno = AdditionalGroup::create(['restaurant_id' => $this->otro->id, 'name' => 'De otro']);

        $this->getJson("/api/additional-groups/{$ajeno->id}")->assertForbidden();
        $this->putJson("/api/additional-groups/{$ajeno->id}", ['name' => 'X'])->assertForbidden();
        $this->deleteJson("/api/additional-groups/{$ajeno->id}")->assertForbidden();
    }

    public function test_no_deja_modificar_un_adicional_de_otro_grupo_colando_su_id(): void
    {
        $ajeno   = AdditionalGroup::create(['restaurant_id' => $this->otro->id, 'name' => 'De otro']);
        $victima = $ajeno->additionals()->create(['name' => 'Intacto', 'extra_price' => 100]);

        $grupo = $this->grupoConSalsas();

        $this->putJson("/api/additional-groups/{$grupo->id}", [
            'additionals' => [['id' => $victima->id, 'name' => 'Secuestrado', 'extra_price' => 9999]],
        ])->assertOk();

        $this->assertSame('Intacto', $victima->fresh()->name);
        $this->assertEquals(100, $victima->fresh()->extra_price);
    }

    private function grupoConSalsas(): AdditionalGroup
    {
        $grupo = AdditionalGroup::create([
            'restaurant_id'  => $this->restaurant->id,
            'name'           => 'Salsas',
            'selection_type' => 'multiple',
        ]);

        $grupo->additionals()->create(['name' => 'Ají',     'extra_price' => 1000, 'sort_order' => 0]);
        $grupo->additionals()->create(['name' => 'Tártara', 'extra_price' => 1500, 'sort_order' => 1]);

        return $grupo->load('additionals');
    }

    private function pedir(Additional $additional): void
    {
        $category = Category::create(['restaurant_id' => $this->restaurant->id, 'name' => 'Platos']);

        $product = Product::create([
            'restaurant_id' => $this->restaurant->id,
            'category_id'   => $category->id,
            'name'          => 'Bandeja',
            'price'         => 25000,
        ]);

        $order = Order::create([
            'restaurant_id' => $this->restaurant->id,
            'type'          => 'dine_in',
            'status'        => 'closed',
            'subtotal'      => 25000,
            'total'         => 25000,
        ]);

        $item = OrderItem::create([
            'order_id'     => $order->id,
            'product_id'   => $product->id,
            'product_name' => $product->name,
            'unit_price'   => 25000,
            'quantity'     => 1,
            'subtotal'     => 25000,
        ]);

        OrderItemAdditional::create([
            'order_item_id'   => $item->id,
            'additional_id'   => $additional->id,
            'additional_name' => $additional->name,
            'extra_price'     => $additional->extra_price,
        ]);
    }
}
