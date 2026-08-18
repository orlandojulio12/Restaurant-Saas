<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ZoneControllerTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;
    private Restaurant $otro;
    private User $admin;
    private User $mozo;

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

        $this->mozo = User::create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Mozo', 'email' => 'mozo@test.com',
            'password' => 'password', 'role' => 'waiter',
        ]);
    }

    public function test_solo_un_admin_puede_gestionar_zonas(): void
    {
        Sanctum::actingAs($this->mozo);

        $this->getJson('/api/zones')->assertForbidden();
        $this->postJson('/api/zones', ['name' => 'Terraza'])->assertForbidden();
    }

    public function test_lista_solo_las_zonas_del_restaurante_con_conteo_de_mesas(): void
    {
        Sanctum::actingAs($this->admin);

        $zona = Zone::create(['restaurant_id' => $this->restaurant->id, 'name' => 'Salón']);
        Zone::create(['restaurant_id' => $this->otro->id, 'name' => 'De otro']);
        $this->mesaEn($zona);

        $response = $this->getJson('/api/zones')->assertOk();

        $this->assertCount(1, $response->json());
        $this->assertSame('Salón', $response->json('0.name'));
        $this->assertSame(1, $response->json('0.tables_count'));
    }

    public function test_crea_una_zona_al_final_del_orden(): void
    {
        Sanctum::actingAs($this->admin);

        Zone::create(['restaurant_id' => $this->restaurant->id, 'name' => 'Salón', 'sort_order' => 3]);

        $response = $this->postJson('/api/zones', ['name' => 'Terraza'])->assertCreated();

        $this->assertSame('Terraza', $response->json('name'));
        $this->assertSame($this->restaurant->id, $response->json('restaurant_id'));
        $this->assertSame(4, $response->json('sort_order'));
    }

    public function test_rechaza_una_zona_sin_nombre(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/zones', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_no_deja_tocar_zonas_de_otro_restaurante(): void
    {
        Sanctum::actingAs($this->admin);

        $ajena = Zone::create(['restaurant_id' => $this->otro->id, 'name' => 'De otro']);

        $this->getJson("/api/zones/{$ajena->id}")->assertForbidden();
        $this->putJson("/api/zones/{$ajena->id}", ['name' => 'X'])->assertForbidden();
        $this->deleteJson("/api/zones/{$ajena->id}")->assertForbidden();
    }

    public function test_borra_sin_confirmar_una_zona_vacia(): void
    {
        Sanctum::actingAs($this->admin);

        $zona = Zone::create(['restaurant_id' => $this->restaurant->id, 'name' => 'Vacía']);

        $this->deleteJson("/api/zones/{$zona->id}")->assertNoContent();

        $this->assertDatabaseMissing('zones', ['id' => $zona->id]);
    }

    public function test_pide_confirmacion_para_borrar_una_zona_con_mesas(): void
    {
        Sanctum::actingAs($this->admin);

        $zona = Zone::create(['restaurant_id' => $this->restaurant->id, 'name' => 'Salón']);
        $this->mesaEn($zona);

        $this->deleteJson("/api/zones/{$zona->id}")
            ->assertStatus(422)
            ->assertJsonPath('tables_count', 1);

        $this->assertDatabaseHas('zones', ['id' => $zona->id]);
    }

    public function test_con_force_borra_la_zona_y_las_mesas_quedan_sin_zona(): void
    {
        Sanctum::actingAs($this->admin);

        $zona = Zone::create(['restaurant_id' => $this->restaurant->id, 'name' => 'Salón']);
        $mesa = $this->mesaEn($zona);

        $this->deleteJson("/api/zones/{$zona->id}?force=true")->assertNoContent();

        $this->assertDatabaseMissing('zones', ['id' => $zona->id]);
        $this->assertNull($mesa->fresh()->zone_id, 'La mesa debe sobrevivir sin zona.');
    }

    private function mesaEn(Zone $zona): RestaurantTable
    {
        return RestaurantTable::create([
            'restaurant_id' => $this->restaurant->id,
            'zone_id'       => $zona->id,
            'number'        => (string) random_int(1000, 9999),
            'capacity'      => 4,
            'qr_code'       => Str::uuid()->toString(),
        ]);
    }
}
