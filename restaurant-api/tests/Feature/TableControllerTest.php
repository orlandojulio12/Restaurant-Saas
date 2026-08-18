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

class TableControllerTest extends TestCase
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

    /**
     * El caso que estaba roto: number es string(20), pero se validaba como
     * entero, así que no se podían crear mesas tipo "A1" o "Terraza-3".
     */
    public function test_admite_numeros_de_mesa_no_numericos(): void
    {
        $this->postJson('/api/tables', ['number' => 'A1', 'capacity' => 2])
            ->assertCreated()
            ->assertJsonPath('number', 'A1');

        $this->postJson('/api/tables', ['number' => 'Terraza-3'])
            ->assertCreated()
            ->assertJsonPath('number', 'Terraza-3');
    }

    public function test_no_admite_dos_mesas_con_el_mismo_numero(): void
    {
        $this->postJson('/api/tables', ['number' => '1'])->assertCreated();

        $this->postJson('/api/tables', ['number' => '1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('number');
    }

    public function test_el_mismo_numero_puede_existir_en_otro_restaurante(): void
    {
        RestaurantTable::create([
            'restaurant_id' => $this->otro->id, 'number' => '1',
            'capacity' => 4, 'qr_code' => Str::uuid()->toString(),
        ]);

        $this->postJson('/api/tables', ['number' => '1'])->assertCreated();
    }

    /**
     * El enum de la migración es available|occupied|reserved|disabled, pero la
     * validación pedía 'unavailable': el valor válido se rechazaba y el
     * inválido habría reventado en la base de datos.
     */
    public function test_acepta_el_estado_disabled_y_rechaza_uno_inexistente(): void
    {
        $this->postJson('/api/tables', ['number' => '1', 'status' => 'disabled'])
            ->assertCreated()
            ->assertJsonPath('status', 'disabled');

        $this->postJson('/api/tables', ['number' => '2', 'status' => 'unavailable'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_genera_un_qr_unico_por_mesa(): void
    {
        $una  = $this->postJson('/api/tables', ['number' => '1'])->assertCreated();
        $otra = $this->postJson('/api/tables', ['number' => '2'])->assertCreated();

        $this->assertNotEmpty($una->json('qr_code'));
        $this->assertNotSame($una->json('qr_code'), $otra->json('qr_code'));
    }

    public function test_actualiza_una_mesa_sin_chocar_con_su_propio_numero(): void
    {
        $mesa = RestaurantTable::create([
            'restaurant_id' => $this->restaurant->id, 'number' => 'A1',
            'capacity' => 4, 'qr_code' => Str::uuid()->toString(),
        ]);

        $zona = Zone::create(['restaurant_id' => $this->restaurant->id, 'name' => 'Terraza']);

        $this->putJson("/api/tables/{$mesa->id}", [
            'number'  => 'A1',
            'zone_id' => $zona->id,
            'status'  => 'reserved',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'reserved');
    }

    public function test_no_deja_tocar_mesas_de_otro_restaurante(): void
    {
        $ajena = RestaurantTable::create([
            'restaurant_id' => $this->otro->id, 'number' => '9',
            'capacity' => 4, 'qr_code' => Str::uuid()->toString(),
        ]);

        $this->getJson("/api/tables/{$ajena->id}")->assertForbidden();
        $this->putJson("/api/tables/{$ajena->id}", ['capacity' => 8])->assertForbidden();
        $this->deleteJson("/api/tables/{$ajena->id}")->assertForbidden();
    }
}
