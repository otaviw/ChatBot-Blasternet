<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthSessionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_fetch_me_after_login_and_loses_access_after_logout(): void
    {
        $company = Company::create(['name' => 'Auth Co']);
        User::create([
            'name' => 'Auth User',
            'email' => 'auth-user@test.local',
            'password' => 'senha-correta-123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $login = $this->postJson('/api/login', [
            'email' => 'auth-user@test.local',
            'password' => 'senha-correta-123',
        ]);
        $login->assertOk()->assertJsonPath('authenticated', true);

        $meBeforeLogout = $this->getJson('/api/me');
        $meBeforeLogout->assertOk()
            ->assertJsonPath('authenticated', true)
            ->assertJsonPath('user.email', 'auth-user@test.local');

        $logout = $this->postJson('/api/logout');
        $logout->assertOk()->assertJsonPath('ok', true);

        $meAfterLogout = $this->getJson('/api/me');
        $meAfterLogout->assertStatus(401);
    }
}
