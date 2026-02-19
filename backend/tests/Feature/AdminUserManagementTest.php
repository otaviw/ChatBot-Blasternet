<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_company_user(): void
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-users@test.local',
            'password' => 'secret123',
            'role' => 'admin',
            'is_active' => true,
        ]);
        $company = Company::create(['name' => 'Empresa U']);

        $response = $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'Operador 1',
            'email' => 'operador1@test.local',
            'password' => 'secret123',
            'role' => 'company',
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('ok', true);
        $this->assertDatabaseHas('users', [
            'email' => 'operador1@test.local',
            'role' => 'company',
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
            'role' => 'admin',
            'is_active' => true,
        ]);
        $user = User::create([
            'name' => 'Operador 2',
            'email' => 'operador2@test.local',
            'password' => 'secret123',
            'role' => 'company',
            'company_id' => Company::create(['name' => 'Empresa X'])->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->putJson("/api/admin/users/{$user->id}", [
            'name' => 'Operador 2 Atualizado',
            'email' => 'operador2@test.local',
            'role' => 'admin',
            'company_id' => null,
            'is_active' => false,
        ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'role' => 'admin',
            'company_id' => null,
            'is_active' => 0,
        ]);
    }
}

