<?php

namespace Tests\Feature;

use App\Events\OrderCreated;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsappBotTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENTE = '573001234567';
    private const NUMERO_RESTAURANTE = '573009999999';

    private Restaurant $restaurant;
    private Product $bandeja;
    private Product $jugo;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.whatsapp.token', 'token-de-prueba');
        config()->set('services.whatsapp.phone_id', '123456');
        config()->set('services.whatsapp.verify_token', 'verificame');

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.x']]], 200)]);

        $plan = Plan::create([
            'name' => 'pro', 'display_name' => 'Pro', 'has_whatsapp' => true, 'max_daily_orders' => 0,
        ]);

        $this->restaurant = Restaurant::create([
            'plan_id'         => $plan->id,
            'name'            => 'El Rincón',
            'slug'            => 'el-rincon',
            'whatsapp_number' => self::NUMERO_RESTAURANTE,
            'currency'        => 'COP',
            'timezone'        => 'America/Bogota',
        ]);

        $categoria = Category::create(['restaurant_id' => $this->restaurant->id, 'name' => 'Platos', 'sort_order' => 1]);
        $bebidas   = Category::create(['restaurant_id' => $this->restaurant->id, 'name' => 'Bebidas', 'sort_order' => 2]);

        $this->bandeja = Product::create([
            'restaurant_id' => $this->restaurant->id, 'category_id' => $categoria->id,
            'name' => 'Bandeja Paisa', 'price' => 25000,
        ]);

        $this->jugo = Product::create([
            'restaurant_id' => $this->restaurant->id, 'category_id' => $bebidas->id,
            'name' => 'Jugo de mango', 'price' => 8000,
        ]);
    }

    public function test_el_handshake_de_verificacion_responde_el_challenge(): void
    {
        $this->get('/api/webhook/whatsapp?hub_mode=subscribe&hub_verify_token=verificame&hub_challenge=abc123')
            ->assertOk()
            ->assertSee('abc123');

        $this->get('/api/webhook/whatsapp?hub_mode=subscribe&hub_verify_token=incorrecto&hub_challenge=abc123')
            ->assertStatus(403);
    }

    public function test_el_saludo_muestra_las_categorias(): void
    {
        $this->enviar('hola');

        $texto = $this->ultimoMensaje();
        $this->assertStringContainsString('El Rincón', $texto);
        $this->assertStringContainsString('1. Platos', $texto);
        $this->assertStringContainsString('2. Bebidas', $texto);

        $this->assertSame('category', $this->sesion()->state);
    }

    public function test_recorre_el_flujo_completo_y_crea_el_pedido(): void
    {
        Event::fake([OrderCreated::class]);

        $this->enviar('hola');            // → categorías
        $this->enviar('1');               // Platos → productos
        $this->assertStringContainsString('Bandeja Paisa', $this->ultimoMensaje());

        $this->enviar('1');               // Bandeja Paisa → pide cantidad
        $this->assertStringContainsString('Cuántas unidades', $this->ultimoMensaje());

        $this->enviar('2');               // 2 unidades → carrito
        $this->assertStringContainsString('50.000', $this->ultimoMensaje());

        $this->enviar('2');               // finalizar → pide dirección
        $this->assertStringContainsString('dirección', $this->ultimoMensaje());

        $this->enviar('Calle 45 #12-30, apto 501');   // → resumen
        $this->assertStringContainsString('Resumen', $this->ultimoMensaje());

        $this->enviar('1');               // confirmar

        $order = Order::first();
        $this->assertNotNull($order);
        $this->assertSame('delivery', $order->type);
        $this->assertSame('pending', $order->status);
        $this->assertNull($order->user_id, 'El pedido lo origina el cliente, no el personal.');
        $this->assertEquals(50000, $order->total);
        $this->assertSame('Calle 45 #12-30, apto 501', $order->delivery_address);
        $this->assertSame(1, $order->items()->count());

        $this->assertStringContainsString("#{$order->id}", $this->ultimoMensaje());
        $this->assertSame('greeting', $this->sesion()->state);

        Event::assertDispatched(OrderCreated::class);
    }

    public function test_permite_agregar_varios_productos(): void
    {
        $this->enviar('hola');
        $this->enviar('1');
        $this->enviar('1');
        $this->enviar('1');   // 1 bandeja
        $this->enviar('1');   // agregar más → categorías
        $this->enviar('2');   // Bebidas
        $this->enviar('1');   // Jugo
        $this->enviar('3');   // 3 jugos
        $this->enviar('2');   // finalizar
        $this->enviar('Carrera 7 #45-12');
        $this->enviar('1');   // confirmar

        $order = Order::first();
        $this->assertSame(2, $order->items()->count());
        $this->assertEquals(49000, $order->total);   // 25.000 + 3×8.000
    }

    public function test_crea_el_cliente_con_su_telefono(): void
    {
        $this->flujoCompleto();

        $cliente = Customer::first();
        $this->assertNotNull($cliente);
        $this->assertSame(self::CLIENTE, $cliente->phone);
        $this->assertSame($cliente->id, Order::first()->customer_id);
        $this->assertSame($cliente->id, $this->sesion()->customer_id);
    }

    public function test_una_opcion_invalida_no_avanza_la_conversacion(): void
    {
        $this->enviar('hola');
        $this->enviar('99');

        $this->assertStringContainsString('No entendí', $this->ultimoMensaje());
        $this->assertSame('category', $this->sesion()->state);
    }

    public function test_rechaza_una_direccion_demasiado_corta(): void
    {
        $this->enviar('hola');
        $this->enviar('1');
        $this->enviar('1');
        $this->enviar('1');
        $this->enviar('2');
        $this->enviar('casa');

        $this->assertStringContainsString('más completa', $this->ultimoMensaje());
        $this->assertSame('address', $this->sesion()->state);
    }

    public function test_cancelar_reinicia_la_conversacion(): void
    {
        $this->enviar('hola');
        $this->enviar('1');
        $this->enviar('cancelar');

        $this->assertStringContainsString('cancelado', $this->ultimoMensaje());
        $this->assertSame('greeting', $this->sesion()->state);
        $this->assertSame(0, Order::count());
    }

    public function test_ignora_un_reintento_del_mismo_mensaje(): void
    {
        $this->enviar('hola', 'wamid.repetido');
        $this->enviar('1', 'wamid.repetido');   // Meta reintenta el mismo id

        // El estado no debe haber avanzado a 'product'.
        $this->assertSame('category', $this->sesion()->state);
    }

    public function test_ignora_mensajes_a_un_numero_sin_restaurante(): void
    {
        $this->postJson('/api/webhook/whatsapp', $this->payload('hola', '570000000000'))->assertOk();

        $this->assertSame(0, WhatsappSession::count());
    }

    public function test_no_responde_si_el_plan_no_incluye_whatsapp(): void
    {
        $free = Plan::create(['name' => 'free', 'display_name' => 'Gratis', 'has_whatsapp' => false]);
        $this->restaurant->update(['plan_id' => $free->id]);

        $this->enviar('hola');

        Http::assertNothingSent();
        $this->assertSame(0, WhatsappSession::count());
    }

    public function test_ignora_los_avisos_de_estado_sin_mensajes(): void
    {
        $this->postJson('/api/webhook/whatsapp', [
            'entry' => [['changes' => [['value' => [
                'metadata' => ['display_phone_number' => self::NUMERO_RESTAURANTE],
                'statuses' => [['id' => 'wamid.x', 'status' => 'delivered']],
            ]]]]],
        ])->assertOk();

        $this->assertSame(0, WhatsappSession::count());
    }

    public function test_el_webhook_responde_200_aunque_falle_el_procesamiento(): void
    {
        // Un payload malformado no debe devolver error: Meta reintentaría en bucle.
        $this->postJson('/api/webhook/whatsapp', ['entry' => 'no-es-un-array'])->assertOk();
    }

    // ── Apoyo ───────────────────────────────────────────────────────────────

    private function flujoCompleto(): void
    {
        foreach (['hola', '1', '1', '1', '2', 'Calle 45 #12-30, apto 501', '1'] as $texto) {
            $this->enviar($texto);
        }
    }

    private function enviar(string $texto, ?string $id = null): void
    {
        $this->postJson('/api/webhook/whatsapp', $this->payload($texto, self::NUMERO_RESTAURANTE, $id))
            ->assertOk();
    }

    private function payload(string $texto, string $numeroDestino, ?string $id = null): array
    {
        return [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['display_phone_number' => $numeroDestino],
                        'messages' => [[
                            'id'   => $id ?? 'wamid.' . uniqid(),
                            'from' => self::CLIENTE,
                            'type' => 'text',
                            'text' => ['body' => $texto],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    private function ultimoMensaje(): string
    {
        // Http::recorded() devuelve una Collection de pares [Request, Response].
        $ultimo = Http::recorded()->last();
        $this->assertNotNull($ultimo, 'El bot no envió ningún mensaje.');

        return $ultimo[0]->data()['text']['body'];
    }

    private function sesion(): WhatsappSession
    {
        return WhatsappSession::where('phone', self::CLIENTE)->firstOrFail();
    }
}
