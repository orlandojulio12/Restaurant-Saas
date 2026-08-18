<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Restaurant;
use App\Models\RestaurantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegisterControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Plan::create([
            'name' => 'free', 'display_name' => 'Gratis',
            'max_tables' => 2, 'max_products' => 20, 'max_daily_orders' => 20,
        ]);
    }

    private function alta(array $extra = [])
    {
        return $this->postJson('/api/auth/register', array_merge([
            'restaurant_name'       => 'El Rincón de Ana',
            'admin_name'            => 'Ana Pérez',
            'email'                 => 'ana@rincon.com',
            'password'              => 'secreto123',
            'password_confirmation' => 'secreto123',
        ], $extra));
    }

    public function test_registra_restaurante_admin_y_devuelve_token(): void
    {
        $response = $this->alta()->assertCreated();

        $this->assertNotEmpty($response->json('token'));
        $this->assertSame('El Rincón de Ana', $response->json('restaurant.name'));
        $this->assertSame('Ana Pérez', $response->json('user.name'));
        $this->assertSame('admin', $response->json('user.role'), 'Quien registra queda como administrador.');
        $this->assertSame('free', $response->json('restaurant.plan.name'));
        $this->assertArrayNotHasKey('password', $response->json('user'));
    }

    public function test_el_token_devuelto_sirve_de_inmediato(): void
    {
        $token = $this->alta()->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'ana@rincon.com');
    }

    public function test_deriva_el_slug_del_nombre(): void
    {
        $this->alta()->assertCreated()
            ->assertJsonPath('restaurant.slug', 'el-rincon-de-ana');
    }

    public function test_si_el_slug_ya_existe_le_pone_sufijo(): void
    {
        $this->alta()->assertCreated();
        $this->alta(['email' => 'otra@rincon.com'])->assertCreated()
            ->assertJsonPath('restaurant.slug', 'el-rincon-de-ana-2');

        $this->assertSame(2, Restaurant::count());
    }

    public function test_acepta_un_slug_propio_y_rechaza_uno_ocupado(): void
    {
        $this->alta(['slug' => 'mi-restaurante'])->assertCreated()
            ->assertJsonPath('restaurant.slug', 'mi-restaurante');

        $this->alta(['email' => 'otra@x.com', 'slug' => 'mi-restaurante'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');
    }

    public function test_crea_los_ajustes_iniciales(): void
    {
        $this->alta()->assertCreated();

        $claves = RestaurantSetting::pluck('value', 'key_name');
        $this->assertSame('tables', $claves['mode']);
        $this->assertSame('0', $claves['tax_percent']);
        $this->assertSame('1', $claves['print_kitchen']);
    }

    public function test_aplica_los_valores_por_defecto_de_colombia(): void
    {
        $this->alta()->assertCreated();

        $r = Restaurant::first();
        $this->assertSame('CO', $r->country);
        $this->assertSame('COP', $r->currency);
        $this->assertSame('America/Bogota', $r->timezone);
    }

    public function test_hashea_la_contrasena(): void
    {
        $this->alta()->assertCreated();

        $user = User::first();
        $this->assertNotSame('secreto123', $user->password);
        $this->assertTrue(Hash::check('secreto123', $user->password));
    }

    public function test_exige_confirmacion_y_longitud_de_contrasena(): void
    {
        $this->alta(['password_confirmation' => 'otra-cosa'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->alta(['password' => 'corta', 'password_confirmation' => 'corta'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_exige_los_campos_obligatorios(): void
    {
        $this->postJson('/api/auth/register', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['restaurant_name', 'admin_name', 'email', 'password']);
    }

    public function test_rechaza_una_zona_horaria_invalida(): void
    {
        $this->alta(['timezone' => 'Marte/Olympus'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('timezone');
    }

    public function test_no_deja_el_restaurante_a_medias_si_algo_falla(): void
    {
        Plan::query()->delete();   // sin plan disponible

        $this->alta()->assertStatus(503);

        $this->assertSame(0, Restaurant::count());
        $this->assertSame(0, User::count());
    }

    public function test_los_restaurantes_registrados_quedan_aislados_entre_si(): void
    {
        $primero = $this->alta()->assertCreated();
        $segundo = $this->alta(['restaurant_name' => 'Otro Sitio', 'email' => 'otro@x.com'])->assertCreated();

        $this->assertNotSame(
            $primero->json('restaurant.id'),
            $segundo->json('restaurant.id')
        );

        // El admin del segundo no ve usuarios del primero.
        $this->withHeader('Authorization', 'Bearer ' . $segundo->json('token'))
            ->getJson('/api/users')
            ->assertOk()
            ->assertJsonCount(1);
    }

    /**
     * El correo es único por restaurante, así que una misma persona puede
     * administrar varios. Antes el login tomaba el primero que apareciera.
     */
    public function test_un_mismo_correo_puede_administrar_dos_restaurantes(): void
    {
        $this->alta()->assertCreated();
        $this->alta(['restaurant_name' => 'Segundo Local'])->assertCreated();

        $response = $this->postJson('/api/auth/login', [
            'email' => 'ana@rincon.com', 'password' => 'secreto123',
        ])->assertStatus(409);

        $this->assertCount(2, $response->json('restaurants'));

        // Indicando el restaurante, entra sin problema.
        $this->postJson('/api/auth/login', [
            'email'           => 'ana@rincon.com',
            'password'        => 'secreto123',
            'restaurant_slug' => 'segundo-local',
        ])
            ->assertOk()
            ->assertJsonPath('restaurant.name', 'Segundo Local');
    }

    public function test_con_dos_cuentas_una_contrasena_incorrecta_sigue_dando_401(): void
    {
        $this->alta()->assertCreated();
        $this->alta(['restaurant_name' => 'Segundo Local'])->assertCreated();

        $this->postJson('/api/auth/login', ['email' => 'ana@rincon.com', 'password' => 'incorrecta'])
            ->assertStatus(401);
    }

    public function test_el_plan_gratuito_limita_desde_el_primer_dia(): void
    {
        $token = $this->alta()->json('token');

        // El plan free permite 2 mesas.
        foreach (['1', '2'] as $numero) {
            $this->withHeader('Authorization', "Bearer {$token}")
                ->postJson('/api/tables', ['number' => $numero])
                ->assertCreated();
        }

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/tables', ['number' => '3'])
            ->assertStatus(403)
            ->assertJsonPath('resource', 'tables');
    }
}
