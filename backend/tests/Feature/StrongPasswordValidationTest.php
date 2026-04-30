<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class StrongPasswordValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_user_creation_rejects_weak_password(): void
    {
        $company = Company::create(['name' => 'Empresa Password Admin']);
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-password@test.local',
            'password' => 'Secret123',
            'role' => User::ROLE_SYSTEM_ADMIN,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'Novo Usuario',
            'email' => 'new-user-password@test.local',
            'password' => 'somenteletras',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('password', (array) $response->json('errors'));
    }

    public function test_company_user_creation_accepts_strong_password(): void
    {
        $company = Company::create(['name' => 'Empresa Password Company']);
        $companyAdmin = User::create([
            'name' => 'Company Admin',
            'email' => 'company-admin-password@test.local',
            'password' => 'Secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($companyAdmin)->postJson('/api/minha-conta/users', [
            'name' => 'Agente Forte',
            'email' => 'agent-strong-password@test.local',
            'password' => 'StrongPass9',
            'role' => User::ROLE_AGENT,
            'is_active' => true,
        ]);

        $response->assertCreated()->assertJsonPath('ok', true);
    }

    public function test_reset_password_rejects_password_without_number(): void
    {
        $user = User::create([
            'name' => 'Reset User',
            'email' => 'reset-strong-password@test.local',
            'password' => 'SenhaAntiga9',
            'role' => User::ROLE_COMPANY_ADMIN,
            'is_active' => true,
        ]);

        $token = Password::broker()->createToken($user);

        $response = $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'somenteletras',
            'password_confirmation' => 'somenteletras',
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('password', (array) $response->json('errors'));
    }

    public function test_update_password_requires_strong_password(): void
    {
        $user = User::create([
            'name' => 'Update Password User',
            'email' => 'update-strong-password@test.local',
            'password' => 'CurrentPass9',
            'role' => User::ROLE_COMPANY_ADMIN,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->putJson('/api/me/password', [
            'current_password' => 'CurrentPass9',
            'password' => 'somenteletras',
            'password_confirmation' => 'somenteletras',
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('password', (array) $response->json('errors'));
    }
}
