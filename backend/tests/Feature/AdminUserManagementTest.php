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

    public function test_system_admin_user_index_returns_global_users_and_summary(): void
    {
        $company = Company::create(['name' => 'Empresa Sumario']);
        $reseller = Reseller::create([
            'name' => 'Revenda Sumario',
            'slug' => 'revenda-sumario',
        ]);
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-users-index@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_SYSTEM_ADMIN,
            'is_active' => true,
        ]);
        $resellerAdmin = User::create([
            'name' => 'Admin Revenda',
            'email' => 'reseller-admin-users-index@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_RESELLER_ADMIN,
            'reseller_id' => $reseller->id,
            'is_active' => true,
        ]);
        $companyAdmin = User::create([
            'name' => 'Admin Empresa',
            'email' => 'company-admin-users-index@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->getJson('/api/admin/users');

        $response->assertOk();
        $response->assertJsonPath('authenticated', true);

        $userIds = collect($response->json('users'))->pluck('id');
        $this->assertTrue($userIds->contains($resellerAdmin->id));
        $this->assertTrue($userIds->contains($companyAdmin->id));
        $this->assertFalse($userIds->contains($admin->id));

        $response->assertJsonPath('users_summary.global.total', 2);
        $this->assertNotEmpty($response->json('users_summary.companies'));
    }

    public function test_reseller_admin_can_create_company_user(): void
    {
        $reseller = Reseller::create([
            'name' => 'Revenda U',
            'slug' => 'revenda-u',
        ]);
        $admin = User::create([
            'name' => 'Admin Revenda',
            'email' => 'admin-users@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_RESELLER_ADMIN,
            'reseller_id' => $reseller->id,
            'is_active' => true,
        ]);
        $company = Company::create(['name' => 'Empresa U', 'reseller_id' => $reseller->id]);

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

    public function test_reseller_admin_can_update_user_role_and_activation(): void
    {
        $reseller = Reseller::create([
            'name' => 'Revenda X',
            'slug' => 'revenda-x-users',
        ]);
        $admin = User::create([
            'name' => 'Admin Revenda',
            'email' => 'admin-users2@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_RESELLER_ADMIN,
            'reseller_id' => $reseller->id,
            'is_active' => true,
        ]);
        $companyA = Company::create(['name' => 'Empresa X', 'reseller_id' => $reseller->id]);
        $companyB = Company::create(['name' => 'Empresa Y', 'reseller_id' => $reseller->id]);
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

    public function test_system_admin_cannot_create_system_admin_via_admin_users_flow(): void
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

        $response->assertStatus(403);
    }

    public function test_system_admin_can_migrate_user_between_reseller_admin_and_company_admin_roles(): void
    {
        $reseller = Reseller::create([
            'name' => 'Revenda Migracao',
            'slug' => 'revenda-migracao',
        ]);
        $company = Company::create([
            'name' => 'Empresa Migracao',
            'reseller_id' => $reseller->id,
        ]);
        $systemAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'super-migracao@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_SYSTEM_ADMIN,
            'is_active' => true,
        ]);
        $target = User::create([
            'name' => 'Usuario Alvo',
            'email' => 'alvo-migracao@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_RESELLER_ADMIN,
            'reseller_id' => $reseller->id,
            'is_active' => true,
        ]);

        $toCompanyResponse = $this->actingAs($systemAdmin)->putJson("/api/admin/users/{$target->id}", [
            'name' => 'Usuario Alvo',
            'email' => 'alvo-migracao@test.local',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $toCompanyResponse->assertOk();
        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'reseller_id' => null,
        ]);

        $toResellerResponse = $this->actingAs($systemAdmin)->putJson("/api/admin/users/{$target->id}", [
            'name' => 'Usuario Alvo',
            'email' => 'alvo-migracao@test.local',
            'role' => User::ROLE_RESELLER_ADMIN,
            'reseller_id' => $reseller->id,
            'is_active' => true,
        ]);

        $toResellerResponse->assertOk();
        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'role' => User::ROLE_RESELLER_ADMIN,
            'company_id' => null,
            'reseller_id' => $reseller->id,
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
