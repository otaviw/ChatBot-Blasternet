<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ProductEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductMetricsFunnelTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_user_receives_funnel_metrics_scoped_to_own_company(): void
    {
        $companyA = Company::create(['name' => 'Empresa A']);
        $companyB = Company::create(['name' => 'Empresa B']);

        $userA = User::create([
            'name' => 'Admin A',
            'email' => 'admin-a-metrics@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $companyA->id,
            'is_active' => true,
        ]);

        ProductEvent::create([
            'company_id' => $companyA->id,
            'funnel' => 'cadastro',
            'step' => 'company_created',
            'event_name' => 'admin_company_created',
            'occurred_at' => now()->subDay(),
        ]);
        ProductEvent::create([
            'company_id' => $companyA->id,
            'funnel' => 'cadastro',
            'step' => 'user_created',
            'event_name' => 'company_user_created',
            'occurred_at' => now()->subDay(),
        ]);
        ProductEvent::create([
            'company_id' => $companyA->id,
            'funnel' => 'uso_chatbot',
            'step' => 'inbound_received',
            'event_name' => 'chatbot_inbound_received',
            'occurred_at' => now()->subDay(),
        ]);

        ProductEvent::create([
            'company_id' => $companyB->id,
            'funnel' => 'cadastro',
            'step' => 'company_created',
            'event_name' => 'admin_company_created',
            'occurred_at' => now()->subDay(),
        ]);
        ProductEvent::create([
            'company_id' => $companyB->id,
            'funnel' => 'cadastro',
            'step' => 'user_created',
            'event_name' => 'company_user_created',
            'occurred_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($userA)
            ->getJson('/api/minha-conta/produto/funil?date_from=2026-04-01&date_to=2026-04-30');

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('company_id', $companyA->id);
        $response->assertJsonPath('funnels.cadastro.entered', 1);
        $response->assertJsonPath('funnels.cadastro.converted', 1);
        $response->assertJsonPath('funnels.cadastro.conversion_rate_pct', 100);
        $response->assertJsonPath('funnels.uso_chatbot.entered', 1);
        $response->assertJsonPath('funnels.uso_chatbot.converted', 0);
    }

    public function test_funnel_endpoint_applies_date_window(): void
    {
        $company = Company::create(['name' => 'Empresa Janela']);
        $user = User::create([
            'name' => 'Admin Janela',
            'email' => 'admin-janela@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        ProductEvent::create([
            'company_id' => $company->id,
            'funnel' => 'login',
            'step' => 'attempt',
            'event_name' => 'auth_login_attempt',
            'occurred_at' => '2026-04-10 10:00:00',
        ]);
        ProductEvent::create([
            'company_id' => $company->id,
            'funnel' => 'login',
            'step' => 'success',
            'event_name' => 'auth_login_success',
            'occurred_at' => '2026-04-10 10:00:03',
        ]);
        ProductEvent::create([
            'company_id' => $company->id,
            'funnel' => 'login',
            'step' => 'attempt',
            'event_name' => 'auth_login_attempt',
            'occurred_at' => '2026-03-31 23:59:59',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/minha-conta/produto/funil?date_from=2026-04-01&date_to=2026-04-30');

        $response->assertOk();
        $response->assertJsonPath('funnels.login.entered', 1);
        $response->assertJsonPath('funnels.login.converted', 1);
        $response->assertJsonPath('funnels.login.conversion_rate_pct', 100);
    }
}

