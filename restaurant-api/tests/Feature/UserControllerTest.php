<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Restaurant;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserControllerTest extends TestCase
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

        $this->admin = $this->usuario('Admin', 'admin@test.com', 'admin');

        Sanctum::actingAs($this->admin);
    }

    public function test_crea_un_usuario_con_la_contrasena_hasheada(): void
    {
        $response = $this->postJson('/api/users', [
            'name'     => 'Nuevo Mozo',
            'email'    => 'mozo@test.com',
            'password' => 'secreto123',
            'role'     => 'waiter',
        ])->assertCreated();

        $this->assertArrayNotHasKey('password', $response->json(), 'Nunca debe devolverse la contraseña.');

        $creado = User::where('email', 'mozo@test.com')->first();
        $this->assertNotSame('secreto123', $creado->password);
        $this->assertTrue(Hash::check('secreto123', $creado->password));
    }

    public function test_exige_contrasena_de_al_menos_ocho_caracteres(): void
    {
        $this->postJson('/api/users', [
            'name' => 'Corto', 'email' => 'corto@test.com', 'password' => 'abc', 'role' => 'waiter',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_el_email_es_unico_por_restaurante_pero_no_entre_restaurantes(): void
    {
        $this->postJson('/api/users', [
            'name' => 'Repetido', 'email' => 'admin@test.com', 'password' => 'secreto123', 'role' => 'waiter',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        // El mismo email en otro restaurante sí es válido.
        User::create([
            'restaurant_id' => $this->otro->id, 'name' => 'Otro admin',
            'email' => 'admin@test.com', 'password' => 'password', 'role' => 'admin',
        ]);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_no_cambia_la_contrasena_si_no_se_envia(): void
    {
        $mozo   = $this->usuario('Mozo', 'mozo@test.com', 'waiter');
        $previa = $mozo->password;

        $this->putJson("/api/users/{$mozo->id}", ['name' => 'Mozo Renombrado'])->assertOk();

        $this->assertSame($previa, $mozo->fresh()->password);
    }

    public function test_un_admin_no_puede_desactivarse_ni_degradarse_ni_borrarse(): void
    {
        $this->usuario('Otro Admin', 'admin2@test.com', 'admin');   // hay otro admin activo

        $this->putJson("/api/users/{$this->admin->id}", ['is_active' => false])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No puedes desactivar tu propia cuenta.');

        $this->putJson("/api/users/{$this->admin->id}", ['role' => 'waiter'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No puedes quitarte a ti mismo el rol de administrador.');

        $this->deleteJson("/api/users/{$this->admin->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'No puedes eliminar tu propia cuenta.');
    }

    public function test_siempre_queda_al_menos_un_admin_activo(): void
    {
        $otroAdmin = $this->usuario('Otro Admin', 'admin2@test.com', 'admin');

        // Sobre otros sí se puede: degradar y desactivar a otro admin es válido...
        $this->putJson("/api/users/{$otroAdmin->id}", ['role' => 'waiter'])->assertOk();
        $this->putJson("/api/users/{$otroAdmin->id}", ['is_active' => false])->assertOk();

        // ...y el invariante se sostiene porque quien ejecuta la acción es un
        // admin activo al que las reglas de autoprotección no dejan tocarse.
        $adminsActivos = User::where('restaurant_id', $this->restaurant->id)
            ->where('role', 'admin')
            ->where('is_active', true)
            ->count();

        $this->assertSame(1, $adminsActivos);
    }

    public function test_desactivar_a_un_usuario_revoca_sus_tokens(): void
    {
        $mozo = $this->usuario('Mozo', 'mozo@test.com', 'waiter');
        $mozo->createToken('sesion-activa');

        $this->assertSame(1, $mozo->tokens()->count());

        $this->putJson("/api/users/{$mozo->id}", ['is_active' => false])->assertOk();

        $this->assertSame(0, $mozo->fresh()->tokens()->count(), 'Un usuario desactivado no debe conservar acceso.');
    }

    public function test_no_permite_borrar_a_un_usuario_con_turnos(): void
    {
        $mozo = $this->usuario('Mozo', 'mozo@test.com', 'waiter');

        Shift::create([
            'restaurant_id' => $this->restaurant->id,
            'user_id'       => $mozo->id,
            'started_at'    => now(),
        ]);

        $this->deleteJson("/api/users/{$mozo->id}")->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $mozo->id]);
    }

    public function test_borra_a_un_usuario_sin_historial(): void
    {
        $mozo = $this->usuario('Mozo', 'mozo@test.com', 'waiter');

        $this->deleteJson("/api/users/{$mozo->id}")->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $mozo->id]);
    }

    public function test_no_deja_tocar_usuarios_de_otro_restaurante(): void
    {
        $ajeno = User::create([
            'restaurant_id' => $this->otro->id, 'name' => 'Ajeno',
            'email' => 'ajeno@test.com', 'password' => 'password', 'role' => 'admin',
        ]);

        $this->getJson("/api/users/{$ajeno->id}")->assertForbidden();
        $this->putJson("/api/users/{$ajeno->id}", ['name' => 'X'])->assertForbidden();
        $this->deleteJson("/api/users/{$ajeno->id}")->assertForbidden();
    }

    private function usuario(string $nombre, string $email, string $rol): User
    {
        return User::create([
            'restaurant_id' => $this->restaurant->id,
            'name'          => $nombre,
            'email'         => $email,
            'password'      => 'password',
            'role'          => $rol,
            'is_active'     => true,
        ]);
    }
}
