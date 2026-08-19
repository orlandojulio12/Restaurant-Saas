<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Restaurant;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;
    private Plan $free;
    private Plan $pro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->free = Plan::create([
            'name' => 'free', 'display_name' => 'Gratis',
            'max_tables' => 2, 'max_products' => 20,
            'price_monthly' => 0, 'price_yearly' => 0,
        ]);

        $this->pro = Plan::create([
            'name' => 'pro', 'display_name' => 'Pro',
            'max_tables' => 0, 'max_products' => 0,
            'has_whatsapp' => true, 'has_inventory' => true,
            'has_reports' => true, 'has_financials' => true,
            'price_monthly' => 99000, 'price_yearly' => 950000,
        ]);

        $this->restaurant = Restaurant::create([
            'plan_id' => $this->free->id, 'name' => 'El Rincón',
            'slug' => 'el-rincon', 'timezone' => 'America/Bogota',
        ]);
    }

    private function servicio(): SubscriptionService
    {
        return app(SubscriptionService::class);
    }

    public function test_activar_sube_de_plan_y_registra_el_pago(): void
    {
        $s = $this->servicio()->activate($this->restaurant, $this->pro, reference: 'NEQUI-8891');

        $this->assertSame('pro', $this->restaurant->fresh()->plan->name);
        $this->assertSame('active', $s->status);
        $this->assertSame('NEQUI-8891', $s->payment_reference);
        $this->assertTrue($s->current_period_end->isFuture());
    }

    public function test_el_periodo_dura_un_mes_y_termina_al_final_del_dia(): void
    {
        $s = $this->servicio()->activate($this->restaurant, $this->pro);

        $this->assertEqualsWithDelta(
            30, now()->diffInDays($s->current_period_end), 2,
            'Un ciclo mensual debe durar aproximadamente un mes.'
        );
        $this->assertSame(23, (int) $s->current_period_end->format('H'));
    }

    public function test_varios_periodos_de_una_vez(): void
    {
        $s = $this->servicio()->activate($this->restaurant, $this->pro, periods: 3);

        $this->assertEqualsWithDelta(90, now()->diffInDays($s->current_period_end), 3);
    }

    public function test_el_ciclo_anual_dura_un_ano(): void
    {
        $s = $this->servicio()->activate($this->restaurant, $this->pro, cycle: 'yearly');

        $this->assertEqualsWithDelta(365, now()->diffInDays($s->current_period_end), 2);
    }

    /**
     * Renovar antes de que venza no debe costarle al cliente los días que
     * todavía no ha consumido: el periodo nuevo encadena con el anterior.
     */
    public function test_renovar_antes_de_tiempo_encadena_los_periodos(): void
    {
        $primera = $this->servicio()->activate($this->restaurant, $this->pro);
        $finPrimera = $primera->current_period_end->copy();

        $segunda = $this->servicio()->activate($this->restaurant, $this->pro, reference: 'NEQUI-2');

        $this->assertTrue(
            $segunda->current_period_start->gte($finPrimera->startOfDay()),
            'El periodo nuevo debe arrancar donde terminaba el anterior.'
        );
        $this->assertEqualsWithDelta(60, now()->diffInDays($segunda->current_period_end), 3);
        $this->assertSame(2, Subscription::count(), 'Cada pago deja su propia fila.');
    }

    public function test_cambiar_de_plan_no_encadena_arranca_hoy(): void
    {
        $basico = Plan::create(['name' => 'basic', 'display_name' => 'Básico', 'price_monthly' => 49000]);

        $this->servicio()->activate($this->restaurant, $basico);
        $s = $this->servicio()->activate($this->restaurant, $this->pro);

        $this->assertEqualsWithDelta(0, now()->diffInDays($s->current_period_start), 1);
        $this->assertSame('pro', $this->restaurant->fresh()->plan->name);
    }

    public function test_al_vencer_vuelve_al_plan_gratuito(): void
    {
        $s = $this->servicio()->activate($this->restaurant, $this->pro);
        $this->assertSame('pro', $this->restaurant->fresh()->plan->name);

        // Se fuerza el vencimiento moviendo la fecha al pasado.
        $s->update(['current_period_end' => now()->subDay()]);

        $resultado = $this->servicio()->expireOverdue();

        $this->assertSame(1, $resultado['expiradas']);
        $this->assertSame('free', $this->restaurant->fresh()->plan->name);
        $this->assertSame('expired', $s->fresh()->status);
    }

    public function test_no_degrada_si_ya_renovo_para_el_periodo_siguiente(): void
    {
        $vieja = $this->servicio()->activate($this->restaurant, $this->pro);
        $this->servicio()->activate($this->restaurant, $this->pro);   // renovación encadenada

        $vieja->update(['current_period_end' => now()->subDay()]);

        $this->servicio()->expireOverdue();

        $this->assertSame('pro', $this->restaurant->fresh()->plan->name, 'Renovó: no debe degradarse.');
    }

    public function test_cancelar_devuelve_al_plan_gratuito(): void
    {
        $this->servicio()->activate($this->restaurant, $this->pro);

        $cancelada = $this->servicio()->cancel($this->restaurant);

        $this->assertSame('cancelled', $cancelada->status);
        $this->assertNotNull($cancelada->cancelled_at);
        $this->assertSame('free', $this->restaurant->fresh()->plan->name);
    }

    public function test_el_plan_de_pago_levanta_los_limites_de_inmediato(): void
    {
        Sanctum::actingAs(User::create([
            'restaurant_id' => $this->restaurant->id, 'name' => 'Admin',
            'email' => 'admin@test.com', 'password' => 'password', 'role' => 'admin',
        ]));

        // En free (2 mesas) la tercera se rechaza.
        foreach (['1', '2'] as $n) {
            $this->postJson('/api/tables', ['number' => $n])->assertCreated();
        }
        $this->postJson('/api/tables', ['number' => '3'])->assertStatus(403);

        $this->servicio()->activate($this->restaurant, $this->pro);

        // En pro (ilimitado) ya entra.
        $this->postJson('/api/tables', ['number' => '3'])->assertCreated();
    }

    public function test_el_endpoint_muestra_plan_estado_e_historial(): void
    {
        Sanctum::actingAs(User::create([
            'restaurant_id' => $this->restaurant->id, 'name' => 'Admin',
            'email' => 'admin@test.com', 'password' => 'password', 'role' => 'admin',
        ]));

        $this->getJson('/api/subscription')
            ->assertOk()
            ->assertJsonPath('plan.name', 'free')
            ->assertJsonPath('subscription', null)
            ->assertJsonPath('is_paid', false);

        $this->servicio()->activate($this->restaurant, $this->pro, reference: 'NEQUI-8891');

        $response = $this->getJson('/api/subscription')->assertOk();

        $this->assertSame('pro', $response->json('plan.name'));
        $this->assertTrue($response->json('is_paid'));
        $this->assertSame('active', $response->json('subscription.status'));
        $this->assertGreaterThan(25, $response->json('subscription.days_left'));
        $this->assertSame('NEQUI-8891', $response->json('history.0.reference'));
    }

    public function test_solo_los_admin_ven_la_suscripcion(): void
    {
        Sanctum::actingAs(User::create([
            'restaurant_id' => $this->restaurant->id, 'name' => 'Mozo',
            'email' => 'mozo@test.com', 'password' => 'password', 'role' => 'waiter',
        ]));

        $this->getJson('/api/subscription')->assertStatus(403);
    }

    public function test_el_comando_activa_el_plan(): void
    {
        $this->artisan('subscription:activate el-rincon pro --periods=2 --reference=TRF-77 --no-interaction')
            ->assertSuccessful();

        $this->assertSame('pro', $this->restaurant->fresh()->plan->name);
        $this->assertSame('TRF-77', Subscription::first()->payment_reference);
    }

    public function test_el_comando_avisa_si_el_restaurante_o_el_plan_no_existen(): void
    {
        $this->artisan('subscription:activate no-existe pro --no-interaction')->assertFailed();
        $this->artisan('subscription:activate el-rincon plan-inventado --no-interaction')->assertFailed();
    }

    public function test_el_comando_de_vencimiento_no_falla_sin_nada_que_vencer(): void
    {
        $this->artisan('subscriptions:expire')->assertSuccessful();
    }
}
