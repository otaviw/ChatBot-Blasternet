<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginProductMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_login_tracks_attempt_and_success_events(): void
    {
        $company = Company::create(['name' => 'Empresa Login']);
        $user = User::create([
            'name' => 'Usuario Login',
            'email' => 'login-metrics@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ]);

        $response->assertOk();
        $response->assertJsonPath('authenticated', true);

        $this->assertDatabaseHas('product_events', [
            'funnel' => 'login',
            'step' => 'attempt',
            'event_name' => 'auth_login_attempt',
        ]);

        $this->assertDatabaseHas('product_events', [
            'funnel' => 'login',
            'step' => 'success',
            'event_name' => 'auth_login_success',
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);
    }
}

