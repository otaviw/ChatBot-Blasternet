<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_user_index_returns_aggregated_summary_in_privacy_mode(): void
    {
        $company = Company::create(['name' => 'Empresa Sumario']);
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-users-index@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_SYSTEM_ADMIN,
            'is_active' => true,
        ]);
        User::create([
            'name' => 'Operador 1',
            'email' => 'operador-index1@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
        ]);
        User::create([
            'name' => 'Operador 2',
            'email' => 'operador-index2@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => false,
        ]);

        $response = $this->actingAs($admin)->getJson('/api/admin/users');

        $response->assertOk();
        $response->assertJsonPath('privacy_mode', 'blind_default');
        $response->assertJsonPath('users_summary.companies.0.company_id', $company->id);
        $response->assertJsonMissingPath('users');
    }

    public function test_admin_cannot_create_company_user_in_privacy_mode(): void
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

        $response->assertStatus(403);
        $response->assertJsonPath('privacy_mode', 'blind_default');

        $this->assertDatabaseMissing('users', [
            'email' => 'operador1@test.local',
        ]);
    }

    public function test_admin_cannot_update_user_role_and_activation_in_privacy_mode(): void
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-users2@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_SYSTEM_ADMIN,
            'is_active' => true,
        ]);
        $user = User::create([
            'name' => 'Operador 2',
            'email' => 'operador2@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => Company::create(['name' => 'Empresa X'])->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->putJson("/api/admin/users/{$user->id}", [
            'name' => 'Operador 2 Atualizado',
            'email' => 'operador2@test.local',
            'role' => User::ROLE_SYSTEM_ADMIN,
            'company_id' => null,
            'is_active' => false,
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('privacy_mode', 'blind_default');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'role' => User::ROLE_AGENT,
            'company_id' => $user->company_id,
            'is_active' => 1,
        ]);
    }
}
