<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerControllerTest extends TestCase
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

    public function test_crea_un_cliente(): void
    {
        $response = $this->postJson('/api/customers', [
            'name'  => 'Ana Pérez',
            'phone' => '3001234567',
        ])->assertCreated();

        $this->assertSame('Ana Pérez', $response->json('name'));
        $this->assertSame($this->restaurant->id, $response->json('restaurant_id'));
        $this->assertEquals(0, $response->json('total_orders'));
    }

    public function test_no_admite_dos_clientes_con_el_mismo_telefono(): void
    {
        $this->cliente('Ana', '3001234567');

        $this->postJson('/api/customers', ['name' => 'Otra Ana', 'phone' => '3001234567'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_el_mismo_telefono_puede_existir_en_otro_restaurante(): void
    {
        Customer::create([
            'restaurant_id' => $this->otro->id,
            'name'          => 'Ana en otro lado',
            'phone'         => '3001234567',
        ]);

        $this->postJson('/api/customers', ['name' => 'Ana', 'phone' => '3001234567'])
            ->assertCreated();
    }

    public function test_al_editar_no_choca_con_su_propio_telefono(): void
    {
        $ana = $this->cliente('Ana', '3001234567');

        $this->putJson("/api/customers/{$ana->id}", [
            'name'  => 'Ana María',
            'phone' => '3001234567',
        ])->assertOk()->assertJsonPath('name', 'Ana María');
    }

    public function test_busca_por_nombre_y_por_telefono(): void
    {
        $this->cliente('Ana Pérez', '3001111111');
        $this->cliente('Luis Gómez', '3002222222');

        $this->getJson('/api/customers?search=Pérez')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/customers?search=3002')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/customers')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_lista_solo_clientes_del_restaurante(): void
    {
        $this->cliente('Ana', '3001111111');
        Customer::create(['restaurant_id' => $this->otro->id, 'name' => 'Ajeno', 'phone' => '3009999999']);

        $this->getJson('/api/customers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Ana');
    }

    public function test_ignora_intentos_de_editar_los_contadores(): void
    {
        $ana = $this->cliente('Ana', '3001111111');

        $this->putJson("/api/customers/{$ana->id}", [
            'name'        => 'Ana',
            'total_spent' => 999999,
            'total_orders' => 50,
        ])->assertOk();

        $ana->refresh();
        $this->assertEquals(0, $ana->total_spent, 'total_spent es derivado, no editable.');
        $this->assertEquals(0, $ana->total_orders);
    }

    public function test_pide_confirmacion_para_borrar_un_cliente_con_pedidos(): void
    {
        $ana = $this->cliente('Ana', '3001111111');
        $this->pedidoDe($ana);

        $this->deleteJson("/api/customers/{$ana->id}")
            ->assertStatus(422)
            ->assertJsonPath('orders_count', 1);

        $this->assertDatabaseHas('customers', ['id' => $ana->id]);
    }

    public function test_con_force_borra_el_cliente_y_los_pedidos_sobreviven(): void
    {
        $ana    = $this->cliente('Ana', '3001111111');
        $pedido = $this->pedidoDe($ana);

        $this->deleteJson("/api/customers/{$ana->id}?force=true")->assertNoContent();

        $this->assertDatabaseMissing('customers', ['id' => $ana->id]);
        $this->assertNull($pedido->fresh()->customer_id);
    }

    public function test_no_deja_tocar_clientes_de_otro_restaurante(): void
    {
        $ajeno = Customer::create([
            'restaurant_id' => $this->otro->id, 'name' => 'Ajeno', 'phone' => '3009999999',
        ]);

        $this->getJson("/api/customers/{$ajeno->id}")->assertForbidden();
        $this->putJson("/api/customers/{$ajeno->id}", ['name' => 'X'])->assertForbidden();
        $this->deleteJson("/api/customers/{$ajeno->id}")->assertForbidden();
    }

    /**
     * Un cliente sin nombre ni teléfono no se puede buscar ni contactar: solo
     * ensucia el listado. Antes un POST con el cuerpo vacío devolvía 201.
     */
    public function test_exige_al_menos_nombre_o_telefono(): void
    {
        $this->postJson('/api/customers', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'phone']);

        $this->assertSame(0, Customer::count());
    }

    public function test_basta_con_el_nombre(): void
    {
        $this->postJson('/api/customers', ['name' => 'Solo nombre'])->assertCreated();
    }

    public function test_basta_con_el_telefono(): void
    {
        $this->postJson('/api/customers', ['phone' => '3001234567'])->assertCreated();
    }

    private function cliente(string $nombre, string $telefono): Customer
    {
        return Customer::create([
            'restaurant_id' => $this->restaurant->id,
            'name'          => $nombre,
            'phone'         => $telefono,
        ]);
    }

    private function pedidoDe(Customer $cliente): Order
    {
        return Order::create([
            'restaurant_id' => $this->restaurant->id,
            'customer_id'   => $cliente->id,
            'type'          => 'delivery',
            'status'        => 'closed',
            'subtotal'      => 30000,
            'total'         => 30000,
        ]);
    }
}
