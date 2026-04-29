<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_admin_can_create_user_for_own_company(): void
    {
        $company = Company::create(['name' => 'Empresa A']);
        $companyAdmin = User::create([
            'name' => 'Admin Empresa',
            'email' => 'company-admin@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($companyAdmin)->postJson('/api/minha-conta/users', [
            'name' => 'Agente Empresa',
            'email' => 'agent-company@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'is_active' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('ok', true);
        $this->assertDatabaseHas('users', [
            'email' => 'agent-company@test.local',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => 1,
        ]);
    }

    public function test_company_admin_can_define_ai_permission_for_agent_on_create_and_update(): void
    {
        $company = Company::create(['name' => 'Empresa IA Usuários']);
        $companyAdmin = User::create([
            'name' => 'Admin Empresa IA',
            'email' => 'company-admin-ia@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $createResponse = $this->actingAs($companyAdmin)->postJson('/api/minha-conta/users', [
            'name' => 'Agente IA',
            'email' => 'agent-ai@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'is_active' => true,
            'can_use_ai' => true,
        ]);

        $createResponse->assertStatus(201);
        $createResponse->assertJsonPath('user.can_use_ai', true);
        $this->assertDatabaseHas('users', [
            'email' => 'agent-ai@test.local',
            'company_id' => $company->id,
            'role' => User::ROLE_AGENT,
            'can_use_ai' => 1,
        ]);

        $agentId = (int) $createResponse->json('user.id');

        $updateResponse = $this->actingAs($companyAdmin)->putJson("/api/minha-conta/users/{$agentId}", [
            'name' => 'Agente IA Atualizado',
            'email' => 'agent-ai@test.local',
            'role' => User::ROLE_AGENT,
            'is_active' => true,
            'can_use_ai' => false,
        ]);

        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('user.can_use_ai', false);
        $this->assertDatabaseHas('users', [
            'id' => $agentId,
            'can_use_ai' => 0,
        ]);
    }

    public function test_company_admin_cannot_update_user_from_other_company(): void
    {
        $companyA = Company::create(['name' => 'Empresa A']);
        $companyB = Company::create(['name' => 'Empresa B']);
        $companyAdmin = User::create([
            'name' => 'Admin Empresa A',
            'email' => 'company-admin-a@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $companyA->id,
            'is_active' => true,
        ]);
        $otherCompanyUser = User::create([
            'name' => 'Usuário Empresa B',
            'email' => 'company-user-b@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $companyB->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($companyAdmin)->putJson("/api/minha-conta/users/{$otherCompanyUser->id}", [
            'name' => 'Não Pode',
            'email' => 'company-user-b@test.local',
            'role' => User::ROLE_AGENT,
            'is_active' => true,
        ]);

        $response->assertStatus(404);
    }

    public function test_company_admin_creation_ignores_company_id_from_payload(): void
    {
        $companyA = Company::create(['name' => 'Empresa A']);
        $companyB = Company::create(['name' => 'Empresa B']);
        $companyAdmin = User::create([
            'name' => 'Admin Empresa A',
            'email' => 'company-admin-a-scope@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $companyA->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($companyAdmin)->postJson('/api/minha-conta/users', [
            'name' => 'Usuário Escopo Empresa A',
            'email' => 'company-scope-a@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $companyB->id,
            'is_active' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('user.company_id', $companyA->id);
        $this->assertDatabaseHas('users', [
            'email' => 'company-scope-a@test.local',
            'role' => User::ROLE_AGENT,
            'company_id' => $companyA->id,
            'is_active' => 1,
        ]);
        $this->assertDatabaseMissing('users', [
            'email' => 'company-scope-a@test.local',
            'company_id' => $companyB->id,
        ]);
    }

    public function test_company_admin_cannot_create_system_admin(): void
    {
        $company = Company::create(['name' => 'Empresa A']);
        $companyAdmin = User::create([
            'name' => 'Admin Empresa A',
            'email' => 'company-admin-a-no-super@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($companyAdmin)->postJson('/api/minha-conta/users', [
            'name' => 'Tentativa Superadmin',
            'email' => 'blocked-superadmin@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_SYSTEM_ADMIN,
            'is_active' => true,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('users', [
            'email' => 'blocked-superadmin@test.local',
        ]);
    }

    public function test_agent_cannot_manage_company_users(): void
    {
        $company = Company::create(['name' => 'Empresa X']);
        $agent = User::create([
            'name' => 'Agente',
            'email' => 'agent-x@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($agent)->postJson('/api/minha-conta/users', [
            'name' => 'Não permitido',
            'email' => 'não-permitido@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'is_active' => true,
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('authenticated', true);
    }

    public function test_company_admin_role_can_manage_company_users(): void
    {
        $company = Company::create(['name' => 'Empresa Gestao']);
        $companyAdminUser = User::create([
            'name' => 'Admin Empresa',
            'email' => 'company-admin-manage@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $dashboard = $this->actingAs($companyAdminUser)->getJson('/api/dashboard');
        $dashboard->assertStatus(200);
        $dashboard->assertJsonPath('can_manage_users', true);

        $response = $this->actingAs($companyAdminUser)->postJson('/api/minha-conta/users', [
            'name' => 'Agente Novo',
            'email' => 'company-agent@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'is_active' => true,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'company-agent@test.local',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
        ]);
    }
}
