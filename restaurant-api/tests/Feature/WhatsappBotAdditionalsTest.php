<?php

namespace Tests\Feature;

use App\Models\AdditionalGroup;
use App\Models\Category;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsappBotAdditionalsTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;
    private Product $hamburguesa;
    private Product $gaseosa;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.x']]], 200)]);

        config(['services.whatsapp.token' => 'test-token', 'services.whatsapp.phone_id' => '12345']);

        $plan = Plan::create([
            'name' => 'pro', 'display_name' => 'Pro',
            'has_whatsapp' => true, 'max_daily_orders' => 0,
        ]);

        $this->restaurant = Restaurant::create([
            'plan_id' => $plan->id, 'name' => 'El Rincón', 'slug' => 'el-rincon',
            'whatsapp_number' => '573001112233', 'timezone' => 'America/Bogota',
            'currency' => 'COP',
        ]);

        $categoria = Category::create(['restaurant_id' => $this->restaurant->id, 'name' => 'Comidas']);

        $this->hamburguesa = Product::create([
            'restaurant_id' => $this->restaurant->id, 'category_id' => $categoria->id,
            'name' => 'Hamburguesa', 'price' => 20000, 'sort_order' => 0,
        ]);

        $this->gaseosa = Product::create([
            'restaurant_id' => $this->restaurant->id, 'category_id' => $categoria->id,
            'name' => 'Gaseosa', 'price' => 4000, 'sort_order' => 1,
        ]);

        // Salsas: varias, opcional. Punto: una, obligatorio.
        $salsas = AdditionalGroup::create([
            'restaurant_id' => $this->restaurant->id, 'name' => 'Salsas',
            'selection_type' => 'multiple', 'is_required' => false, 'sort_order' => 0,
        ]);
        $salsas->additionals()->create(['name' => 'Ají', 'extra_price' => 1000, 'sort_order' => 0]);
        $salsas->additionals()->create(['name' => 'Tártara', 'extra_price' => 1500, 'sort_order' => 1]);
        $salsas->additionals()->create(['name' => 'Agotada', 'extra_price' => 500, 'is_available' => false, 'sort_order' => 2]);

        $punto = AdditionalGroup::create([
            'restaurant_id' => $this->restaurant->id, 'name' => 'Punto de la carne',
            'selection_type' => 'single', 'is_required' => true, 'sort_order' => 1,
        ]);
        $punto->additionals()->create(['name' => 'Término medio', 'extra_price' => 0, 'sort_order' => 0]);
        $punto->additionals()->create(['name' => 'Bien asada', 'extra_price' => 0, 'sort_order' => 1]);

        $this->hamburguesa->additionalGroups()->sync([$salsas->id, $punto->id]);
    }

    private function enviar(string $texto, string $id = null): void
    {
        $this->postJson('/api/webhook/whatsapp', [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['display_phone_number' => '573001112233'],
                        'messages' => [[
                            'id'   => $id ?? 'wamid.' . uniqid(),
                            'from' => '573009998877',
                            'type' => 'text',
                            'text' => ['body' => $texto],
                        ]],
                    ],
                ]],
            ]],
        ])->assertOk();
    }

    private function ultimoMensaje(): string
    {
        $ultimo = Http::recorded()->last();
        $this->assertNotNull($ultimo, 'El bot no envió ningún mensaje.');

        return $ultimo[0]->data()['text']['body'];
    }

    public function test_ofrece_los_adicionales_tras_elegir_el_producto(): void
    {
        $this->enviar('hola');
        $this->enviar('1');          // categoría Comidas
        $this->enviar('1');          // Hamburguesa

        $mensaje = $this->ultimoMensaje();

        $this->assertStringContainsString('Salsas', $mensaje);
        $this->assertStringContainsString('Ají', $mensaje);
        $this->assertStringContainsString('Tártara', $mensaje);
        $this->assertStringContainsString('puedes elegir varias', $mensaje);
    }

    public function test_no_ofrece_los_adicionales_agotados(): void
    {
        $this->enviar('hola');
        $this->enviar('1');
        $this->enviar('1');

        $this->assertStringNotContainsString('Agotada', $this->ultimoMensaje());
    }

    public function test_un_producto_sin_adicionales_va_directo_a_la_cantidad(): void
    {
        $this->enviar('hola');
        $this->enviar('1');
        $this->enviar('2');          // Gaseosa, sin grupos

        $this->assertStringContainsString('unidades', $this->ultimoMensaje());
    }

    public function test_recorre_los_grupos_y_crea_el_pedido_con_los_adicionales(): void
    {
        $this->enviar('hola');
        $this->enviar('1');
        $this->enviar('1');          // Hamburguesa
        $this->enviar('1,2');        // Ají + Tártara

        $this->assertStringContainsString('Punto de la carne', $this->ultimoMensaje());

        $this->enviar('2');          // Bien asada
        $this->assertStringContainsString('unidades', $this->ultimoMensaje());

        $this->enviar('2');          // dos hamburguesas
        $this->enviar('2');          // finalizar
        $this->enviar('Calle 45 #12-30, barrio Centro');
        $this->enviar('1');          // confirmar

        $order = Order::with('items.additionals')->first();
        $this->assertNotNull($order, 'Debía crearse el pedido.');

        $item = $order->items->first();

        // 20.000 + 1.000 + 1.500 = 22.500 por unidad, dos unidades.
        $this->assertEquals(22500, $item->unit_price);
        $this->assertEquals(45000, $item->subtotal);
        $this->assertEquals(45000, $order->total);

        $nombres = $item->additionals->pluck('additional_name')->sort()->values()->all();
        $this->assertSame(['Ají', 'Bien asada', 'Tártara'], $nombres);
    }

    public function test_cero_omite_un_grupo_opcional(): void
    {
        $this->enviar('hola');
        $this->enviar('1');
        $this->enviar('1');
        $this->enviar('0');          // sin salsas

        $this->assertStringContainsString('Punto de la carne', $this->ultimoMensaje());

        $this->enviar('1');
        $this->enviar('1');
        $this->enviar('2');
        $this->enviar('Calle 45 #12-30, barrio Centro');
        $this->enviar('1');          // confirmar

        $item = Order::with('items.additionals')->first()->items->first();

        $this->assertEquals(20000, $item->unit_price, 'Sin salsas, el precio no cambia.');
        $this->assertSame(['Término medio'], $item->additionals->pluck('additional_name')->all());
    }

    public function test_un_grupo_obligatorio_no_admite_cero(): void
    {
        $this->enviar('hola');
        $this->enviar('1');
        $this->enviar('1');
        $this->enviar('0');          // salta salsas (opcional)
        $this->enviar('0');          // intenta saltar el punto (obligatorio)

        $mensaje = $this->ultimoMensaje();

        $this->assertStringContainsString('No entendí', $mensaje);
        $this->assertStringContainsString('Punto de la carne', $mensaje, 'Debe volver a preguntar el mismo grupo.');
    }

    public function test_en_un_grupo_de_una_sola_opcion_toma_la_primera_valida(): void
    {
        $this->enviar('hola');
        $this->enviar('1');
        $this->enviar('1');
        $this->enviar('0');
        $this->enviar('2,1');        // grupo single: se queda con la 2

        $this->enviar('1');
        $this->enviar('2');
        $this->enviar('Calle 45 #12-30, barrio Centro');
        $this->enviar('1');          // confirmar

        $item = Order::with('items.additionals')->first()->items->first();

        $this->assertSame(['Bien asada'], $item->additionals->pluck('additional_name')->all());
    }

    public function test_una_opcion_fuera_de_rango_vuelve_a_preguntar(): void
    {
        $this->enviar('hola');
        $this->enviar('1');
        $this->enviar('1');
        $this->enviar('99');

        $this->assertStringContainsString('No entendí', $this->ultimoMensaje());
    }

    public function test_el_resumen_del_carrito_muestra_los_adicionales(): void
    {
        $this->enviar('hola');
        $this->enviar('1');
        $this->enviar('1');
        $this->enviar('1');          // Ají
        $this->enviar('1');          // Término medio
        $this->enviar('1');          // una unidad

        $mensaje = $this->ultimoMensaje();

        $this->assertStringContainsString('+ Ají', $mensaje);
        $this->assertStringContainsString('+ Término medio', $mensaje);
    }

    public function test_cancelar_durante_los_adicionales_reinicia(): void
    {
        $this->enviar('hola');
        $this->enviar('1');
        $this->enviar('1');
        $this->enviar('cancelar');

        $this->assertStringContainsString('cancelado', mb_strtolower($this->ultimoMensaje()));
        $this->assertSame(0, Order::count());
    }
}
