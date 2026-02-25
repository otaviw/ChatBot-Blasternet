<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_bot_update_creates_audit_log(): void
    {
        $company = Company::create(['name' => 'Empresa Segura']);
        $user = User::create([
            'name' => 'Company Security User',
            'email' => 'security-company@test.local',
            'password' => 'secret123',
            'role' => 'company',
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $payload = [
            'is_active' => true,
            'timezone' => 'America/Sao_Paulo',
            'welcome_message' => 'Oi',
            'fallback_message' => 'Fallback',
            'out_of_hours_message' => 'Fora',
            'business_hours' => [
                'monday' => ['enabled' => true, 'start' => '08:00', 'end' => '18:00'],
                'tuesday' => ['enabled' => true, 'start' => '08:00', 'end' => '18:00'],
                'wednesday' => ['enabled' => true, 'start' => '08:00', 'end' => '18:00'],
                'thursday' => ['enabled' => true, 'start' => '08:00', 'end' => '18:00'],
                'friday' => ['enabled' => true, 'start' => '08:00', 'end' => '18:00'],
                'saturday' => ['enabled' => false, 'start' => null, 'end' => null],
                'sunday' => ['enabled' => false, 'start' => null, 'end' => null],
            ],
            'keyword_replies' => [
                ['keyword' => 'preco', 'reply' => 'Consulte os planos.'],
            ],
            'inactivity_close_hours' => 24,
            'service_areas' => ['Suporte'],
        ];

        $response = $this->actingAs($user)->putJson('/api/minha-conta/bot', $payload);

        $response->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'company_id' => $company->id,
            'actor_role' => 'company',
            'actor_company_id' => $company->id,
            'action' => 'company.bot_settings.updated',
            'method' => 'PUT',
            'route' => 'api/minha-conta/bot',
        ]);
    }

    public function test_simulation_creates_audit_log(): void
    {
        $company = Company::create(['name' => 'Empresa Simulada']);
        $admin = User::create([
            'name' => 'Admin Security User',
            'email' => 'security-admin@test.local',
            'password' => 'secret123',
            'role' => 'admin',
            'company_id' => null,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->postJson('/api/simular/mensagem', [
            'company_id' => $company->id,
            'from' => '5511999999999',
            'text' => 'teste de simulacao',
            'send_outbound' => false,
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'company_id' => $company->id,
            'actor_role' => 'admin',
            'action' => 'bot.simulation.executed',
            'method' => 'POST',
            'route' => 'api/simular/mensagem',
        ]);
    }

    public function test_default_security_headers_are_present(): void
    {
        $response = $this->get('/api/entrar');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }
}
