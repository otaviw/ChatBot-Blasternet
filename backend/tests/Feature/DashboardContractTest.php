<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\ProductEvent;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_admin_receives_user_and_company_dashboard_summary(): void
    {
        $company = Company::create(['name' => 'Empresa Dashboard']);
        $admin = $this->makeUser(User::ROLE_COMPANY_ADMIN, $company->id, 'admin-dashboard@test.local');
        $agent = $this->makeUser(User::ROLE_AGENT, $company->id, 'agent-dashboard@test.local');

        ProductEvent::create([
            'company_id' => $company->id,
            'user_id' => $admin->id,
            'funnel' => 'login',
            'step' => 'success',
            'event_name' => 'auth_login_success',
            'occurred_at' => now(),
        ]);

        Conversation::create([
            'company_id' => $company->id,
            'customer_phone' => '5511999990001',
            'status' => 'open',
            'assigned_type' => 'user',
            'assigned_id' => $admin->id,
            'assigned_user_id' => $admin->id,
            'handling_mode' => 'human',
        ]);

        SupportTicket::create([
            'company_id' => $company->id,
            'requester_user_id' => $agent->id,
            'requester_name' => $agent->name,
            'subject' => 'Preciso de ajuda',
            'message' => 'Mensagem de teste',
            'status' => SupportTicket::STATUS_OPEN,
            'managed_by_user_id' => $admin->id,
        ]);

        DB::table('sessions')->insert([
            'id' => 'dashboard-session',
            'user_id' => $admin->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Feature Test',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $response = $this->actingAs($admin)->getJson('/api/dashboard');

        $response
            ->assertOk()
            ->assertJsonPath('role', 'company')
            ->assertJsonPath('user_role', User::ROLE_COMPANY_ADMIN)
            ->assertJsonPath('company.name', 'Empresa Dashboard')
            ->assertJsonPath('user_summary.actions_today', 1)
            ->assertJsonPath('user_summary.assigned_pending', 2)
            ->assertJsonPath('company_summary.active_users_7d', 1)
            ->assertJsonPath('company_summary.total_users', 2)
            ->assertJsonPath('company_summary.core_metric.label', 'Conversas criadas')
            ->assertJsonPath('company_summary.core_metric.value', 1);
    }

    public function test_agent_does_not_receive_company_summary_block(): void
    {
        $company = Company::create(['name' => 'Empresa Restrita']);
        $agent = $this->makeUser(User::ROLE_AGENT, $company->id, 'agent-restricted-dashboard@test.local');

        $response = $this->actingAs($agent)->getJson('/api/dashboard');

        $response
            ->assertOk()
            ->assertJsonPath('role', 'company')
            ->assertJsonPath('user_role', User::ROLE_AGENT)
            ->assertJsonMissingPath('company_summary');
    }

    public function test_system_admin_does_not_receive_company_data(): void
    {
        $admin = $this->makeUser(User::ROLE_SYSTEM_ADMIN, null, 'system-dashboard@test.local');

        $response = $this->actingAs($admin)->getJson('/api/dashboard');

        $response
            ->assertOk()
            ->assertJsonPath('role', 'admin')
            ->assertJsonMissingPath('company')
            ->assertJsonMissingPath('company_summary');
    }

    private function makeUser(string $role, ?int $companyId, string $email): User
    {
        return User::create([
            'name' => $email,
            'email' => $email,
            'password' => 'secret123',
            'role' => $role,
            'company_id' => $companyId,
            'is_active' => true,
        ]);
    }
}
