<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CriticalAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CriticalAuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_login_is_logged_as_critical_action(): void
    {
        User::create([
            'name' => 'Sys Admin',
            'email' => 'sysadmin@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_SYSTEM_ADMIN,
            'company_id' => null,
            'is_active' => true,
        ]);

        $this->withHeader('User-Agent', 'CriticalAuditTest/1.0')->postJson('/api/login', [
            'email' => 'sysadmin@test.local',
            'password' => 'secret123',
        ])->assertOk();

        $this->assertDatabaseHas('critical_audit_logs', [
            'action' => 'auth.admin_login',
            'user_id' => User::query()->where('email', 'sysadmin@test.local')->value('id'),
            'company_id' => null,
            'user_agent' => 'CriticalAuditTest/1.0',
        ]);

        $log = CriticalAuditLog::query()->where('action', 'auth.admin_login')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertNotNull($log->ip_address);
        $this->assertNotNull($log->created_at);
    }

    public function test_password_change_is_logged_as_critical_action(): void
    {
        $company = Company::create(['name' => 'Senha Co']);
        $user = User::create([
            'name' => 'Company Admin',
            'email' => 'company-admin@test.local',
            'password' => 'oldpass123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $this->actingAs($user)->withHeader('User-Agent', 'CriticalAuditTest/2.0')
            ->putJson('/api/me/password', [
                'current_password' => 'oldpass123',
                'password' => 'newpass123',
                'password_confirmation' => 'newpass123',
            ])
            ->assertOk();

        $this->assertDatabaseHas('critical_audit_logs', [
            'action' => 'auth.password_changed',
            'user_id' => $user->id,
            'company_id' => $company->id,
            'user_agent' => 'CriticalAuditTest/2.0',
        ]);
    }

    public function test_user_creation_and_permissions_update_are_logged(): void
    {
        $company = Company::create(['name' => 'Usuarios Co']);
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-users@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $create = $this->actingAs($admin)->postJson('/api/minha-conta/users', [
            'name' => 'Agente 1',
            'email' => 'agente1@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'is_active' => true,
            'permissions' => ['page_inbox'],
        ]);
        $create->assertCreated();

        $userId = (int) $create->json('user.id');
        $this->assertGreaterThan(0, $userId);

        $this->actingAs($admin)->putJson("/api/minha-conta/users/{$userId}", [
            'name' => 'Agente 1',
            'email' => 'agente1@test.local',
            'role' => User::ROLE_AGENT,
            'is_active' => true,
            'permissions' => ['page_inbox', 'page_contacts'],
        ])->assertOk();

        $this->assertDatabaseHas('critical_audit_logs', [
            'action' => 'user.created',
            'company_id' => $company->id,
        ]);

        $this->assertDatabaseHas('critical_audit_logs', [
            'action' => 'user.permissions_changed',
            'company_id' => $company->id,
        ]);

        $this->actingAs($admin)->deleteJson("/api/minha-conta/users/{$userId}")->assertOk();

        $this->assertDatabaseHas('critical_audit_logs', [
            'action' => 'user.deleted',
            'company_id' => $company->id,
        ]);
    }

    public function test_bot_update_logs_ai_and_integration_critical_actions(): void
    {
        Http::fake([
            '*' => Http::response([
                'display_phone_number' => '+55 11 90000-0000',
                'verified_name' => 'Empresa Teste',
            ], 200),
        ]);

        $company = Company::create(['name' => 'Bot Co']);
        $admin = User::create([
            'name' => 'Bot Admin',
            'email' => 'bot-admin@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $this->actingAs($admin)->putJson('/api/minha-conta/bot', [
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
            'keyword_replies' => [],
            'service_areas' => [],
            'inactivity_close_hours' => 24,
            'message_retention_days' => 90,
            'ai_enabled' => true,
            'meta_phone_number_id' => '5511999999999',
            'meta_access_token' => 'token-valido-exemplo',
        ])->assertOk();

        $this->assertDatabaseHas('critical_audit_logs', [
            'action' => 'settings.ai_config_updated',
            'company_id' => $company->id,
        ]);

        $this->assertDatabaseHas('critical_audit_logs', [
            'action' => 'settings.integrations_config_updated',
            'company_id' => $company->id,
        ]);
    }
}
