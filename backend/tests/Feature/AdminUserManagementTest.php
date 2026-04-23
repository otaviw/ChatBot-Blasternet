<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Reseller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_user_index_returns_users_list_and_summary(): void
    {
        $company = Company::create(['name' => 'Empresa Sumario']);
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-users-index@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_SYSTEM_ADMIN,
            'is_active' => true,
        ]);
        $operator = User::create([
            'name' => 'Operador 1',
            'email' => 'operador-index1@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->getJson('/api/admin/users');

        $response->assertOk();
        $response->assertJsonPath('authenticated', true);
        $response->assertJsonPath('users.1.id', $operator->id);
        $response->assertJsonPath('users_summary.companies.0.company_id', $company->id);
    }

    public function test_admin_can_create_company_user(): void
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-users@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_SYSTEM_ADMIN,
            'is_active' => true,
        ]);
        $company = Company::create(['name' => 'Empresa U']);

        $response = $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'Operador 1',
            'email' => 'operador1@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('ok', true);

        $this->assertDatabaseHas('users', [
            'email' => 'operador1@test.local',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => 1,
        ]);
    }

    public function test_admin_can_update_user_role_and_activation(): void
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-users2@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_SYSTEM_ADMIN,
            'is_active' => true,
        ]);
        $companyA = Company::create(['name' => 'Empresa X']);
        $companyB = Company::create(['name' => 'Empresa Y']);
        $user = User::create([
            'name' => 'Operador 2',
            'email' => 'operador2@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $companyA->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->putJson("/api/admin/users/{$user->id}", [
            'name' => 'Operador 2 Atualizado',
            'email' => 'operador2@test.local',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $companyB->id,
            'is_active' => false,
        ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Operador 2 Atualizado',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $companyB->id,
            'is_active' => 0,
        ]);
    }

    public function test_admin_can_create_system_admin_without_company(): void
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-super@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_SYSTEM_ADMIN,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'Novo Superadmin',
            'email' => 'novo-superadmin@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_SYSTEM_ADMIN,
            'company_id' => null,
            'is_active' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('ok', true);

        $this->assertDatabaseHas('users', [
            'email' => 'novo-superadmin@test.local',
            'role' => User::ROLE_SYSTEM_ADMIN,
            'company_id' => null,
            'is_active' => 1,
        ]);
    }

    public function test_admin_can_create_reseller_admin_without_company_scope(): void
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-reseller@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_SYSTEM_ADMIN,
            'is_active' => true,
        ]);
        $reseller = Reseller::create([
            'name' => 'Revenda X',
            'slug' => 'revenda-x',
        ]);

        $response = $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'Admin Revenda',
            'email' => 'admin-revenda@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_RESELLER_ADMIN,
            'reseller_id' => $reseller->id,
            'is_active' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('ok', true);

        $this->assertDatabaseHas('users', [
            'email' => 'admin-revenda@test.local',
            'role' => User::ROLE_RESELLER_ADMIN,
            'company_id' => null,
            'reseller_id' => $reseller->id,
            'is_active' => 1,
        ]);
    }

    public function test_reseller_admin_can_manage_users_only_inside_own_reseller_scope(): void
    {
        $resellerA = Reseller::create(['name' => 'Revenda A', 'slug' => 'revenda-a']);
        $resellerB = Reseller::create(['name' => 'Revenda B', 'slug' => 'revenda-b']);

        $companyA = Company::create(['name' => 'Empresa A', 'reseller_id' => $resellerA->id]);
        $companyB = Company::create(['name' => 'Empresa B', 'reseller_id' => $resellerB->id]);

        $resellerAdmin = User::create([
            'name' => 'Reseller Admin',
            'email' => 'reseller-scope@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_RESELLER_ADMIN,
            'reseller_id' => $resellerA->id,
            'is_active' => true,
        ]);

        $insideUser = User::create([
            'name' => 'Inside User',
            'email' => 'inside@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $companyA->id,
            'is_active' => true,
        ]);

        $outsideUser = User::create([
            'name' => 'Outside User',
            'email' => 'outside@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $companyB->id,
            'is_active' => true,
        ]);

        $indexResponse = $this->actingAs($resellerAdmin)->getJson('/api/admin/users');
        $indexResponse->assertOk();
        $ids = collect($indexResponse->json('users'))->pluck('id')->all();
        $this->assertContains($insideUser->id, $ids);
        $this->assertNotContains($outsideUser->id, $ids);

        $createInside = $this->actingAs($resellerAdmin)->postJson('/api/admin/users', [
            'name' => 'Novo User A',
            'email' => 'novo-user-a@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $companyA->id,
            'is_active' => true,
        ]);
        $createInside->assertStatus(201);

        $createOutside = $this->actingAs($resellerAdmin)->postJson('/api/admin/users', [
            'name' => 'Novo User B',
            'email' => 'novo-user-b@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $companyB->id,
            'is_active' => true,
        ]);
        $createOutside->assertStatus(403);
    }
}
