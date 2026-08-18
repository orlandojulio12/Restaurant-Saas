<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Restaurant;
use App\Models\RestaurantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $plan = Plan::create([
            'name' => 'pro', 'display_name' => 'Pro',
            'has_whatsapp' => true, 'has_inventory' => true,
            'has_reports' => true, 'has_financials' => true,
        ]);

        $this->restaurant = Restaurant::create([
            'plan_id'  => $plan->id,
            'name'     => 'El Rincón',
            'slug'     => 'el-rincon',
            'currency' => 'COP',
            'timezone' => 'America/Bogota',
        ]);

        Sanctum::actingAs(User::create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Admin', 'email' => 'admin@test.com',
            'password' => 'password', 'role' => 'admin',
        ]));
    }

    public function test_devuelve_restaurante_plan_y_ajustes_con_valores_por_defecto(): void
    {
        $response = $this->getJson('/api/settings')->assertOk();

        $this->assertSame('El Rincón', $response->json('restaurant.name'));
        $this->assertSame('pro', $response->json('plan.name'));
        $this->assertTrue($response->json('plan.has_inventory'));

        // Sin filas en restaurant_settings, se devuelven los valores por defecto.
        $this->assertSame('tables', $response->json('settings.mode'));
        $this->assertEqualsWithDelta(0, $response->json('settings.tax_percent'), 0.001);
        $this->assertIsNotString($response->json('settings.tax_percent'));
        $this->assertTrue($response->json('settings.print_kitchen'));
    }

    public function test_devuelve_los_ajustes_con_su_tipo_y_no_como_texto(): void
    {
        RestaurantSetting::create([
            'restaurant_id' => $this->restaurant->id, 'key_name' => 'print_kitchen', 'value' => '0',
        ]);
        RestaurantSetting::create([
            'restaurant_id' => $this->restaurant->id, 'key_name' => 'tax_percent', 'value' => '19',
        ]);

        $response = $this->getJson('/api/settings')->assertOk();

        $this->assertFalse($response->json('settings.print_kitchen'), 'Debe ser booleano, no la cadena "0".');
        $this->assertEqualsWithDelta(19, $response->json('settings.tax_percent'), 0.001);
        $this->assertIsNotString($response->json('settings.tax_percent'), 'Debe ser numérico, no la cadena "19".');
    }

    public function test_actualiza_datos_del_restaurante(): void
    {
        $this->putJson('/api/settings', [
            'name'            => 'El Rincón Renovado',
            'whatsapp_number' => '573001234567',
            'city'            => 'Medellín',
        ])
            ->assertOk()
            ->assertJsonPath('restaurant.name', 'El Rincón Renovado')
            ->assertJsonPath('restaurant.whatsapp_number', '573001234567')
            ->assertJsonPath('restaurant.city', 'Medellín');
    }

    public function test_actualiza_los_ajustes_clave_valor(): void
    {
        $this->putJson('/api/settings', [
            'settings' => ['mode' => 'delivery', 'tax_percent' => 19, 'notify_sound' => false],
        ])
            ->assertOk()
            ->assertJsonPath('settings.mode', 'delivery')
            ->assertJsonPath('settings.tax_percent', 19)
            ->assertJsonPath('settings.notify_sound', false);

        $this->assertDatabaseHas('restaurant_settings', [
            'restaurant_id' => $this->restaurant->id,
            'key_name'      => 'notify_sound',
            'value'         => '0',
        ]);
    }

    public function test_actualizar_dos_veces_no_duplica_filas(): void
    {
        $this->putJson('/api/settings', ['settings' => ['mode' => 'counter']])->assertOk();
        $this->putJson('/api/settings', ['settings' => ['mode' => 'delivery']])->assertOk();

        $this->assertSame(1, RestaurantSetting::where('key_name', 'mode')->count());
        $this->assertSame('delivery', RestaurantSetting::where('key_name', 'mode')->value('value'));
    }

    public function test_rechaza_ajustes_desconocidos(): void
    {
        $this->putJson('/api/settings', ['settings' => ['color_favorito' => 'azul']])
            ->assertStatus(422)
            ->assertJsonPath('allowed', ['mode', 'tax_percent', 'print_kitchen', 'notify_sound']);

        $this->assertDatabaseMissing('restaurant_settings', ['key_name' => 'color_favorito']);
    }

    public function test_valida_el_modo_y_el_porcentaje_de_impuesto(): void
    {
        $this->putJson('/api/settings', ['settings' => ['mode' => 'inventado']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('settings.mode');

        $this->putJson('/api/settings', ['settings' => ['tax_percent' => 150]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('settings.tax_percent');
    }

    public function test_rechaza_una_zona_horaria_invalida(): void
    {
        $this->putJson('/api/settings', ['timezone' => 'Marte/Olympus'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('timezone');
    }

    public function test_no_permite_cambiar_el_slug(): void
    {
        $this->putJson('/api/settings', ['slug' => 'otro-slug'])->assertOk();

        $this->assertSame('el-rincon', $this->restaurant->fresh()->slug, 'El slug rompe los QR impresos.');
    }

    public function test_sube_el_logo_y_borra_el_anterior(): void
    {
        $primera = $this->post('/api/settings', [
            '_method' => 'PUT',
            'logo'    => UploadedFile::fake()->image('logo1.png'),
        ], ['Accept' => 'application/json'])->assertOk();

        $url  = $primera->json('restaurant.logo_url');
        $path = substr($url, strpos($url, '/storage/') + strlen('/storage/'));
        Storage::disk('public')->assertExists($path);

        $segunda = $this->post('/api/settings', [
            '_method' => 'PUT',
            'logo'    => UploadedFile::fake()->image('logo2.png'),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertNotSame($url, $segunda->json('restaurant.logo_url'));
        Storage::disk('public')->assertMissing($path);
    }
}
