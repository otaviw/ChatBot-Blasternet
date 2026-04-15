<?php

namespace Tests\Unit\Services\Ai;

use App\Models\Company;
use App\Models\CompanyBotSetting;
use App\Models\User;
use App\Services\Ai\AiAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AiAccessServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_use_internal_ai_without_company(): void
    {
        $user = User::create([
            'name' => 'Superadmin IA',
            'email' => 'superadmin-ai@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_SYSTEM_ADMIN,
            'is_active' => true,
        ]);

        $service = $this->app->make(AiAccessService::class);

        $this->assertTrue($service->canUseInternalAi($user));
    }

    public function test_company_admin_can_use_internal_ai_when_company_is_enabled(): void
    {
        $company = Company::create(['name' => 'Empresa Admin IA']);
        $user = User::create([
            'name' => 'Admin Empresa IA',
            'email' => 'company-admin-ai@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
            'can_use_ai' => false,
        ]);

        $settings = CompanyBotSetting::create([
            'company_id' => $company->id,
            'ai_enabled' => true,
            'ai_internal_chat_enabled' => true,
        ]);

        $service = $this->app->make(AiAccessService::class);

        $this->assertTrue($service->canUseInternalAi($user, $settings));
    }

    public function test_agent_requires_user_permission_even_when_company_is_enabled(): void
    {
        $company = Company::create(['name' => 'Empresa Agent IA']);
        $agent = User::create([
            'name' => 'Agente IA',
            'email' => 'agent-ai@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
            'can_use_ai' => false,
        ]);

        $settings = CompanyBotSetting::create([
            'company_id' => $company->id,
            'ai_enabled' => true,
            'ai_internal_chat_enabled' => true,
        ]);

        $service = $this->app->make(AiAccessService::class);

        $this->assertFalse($service->canUseInternalAi($agent, $settings));

        try {
            $service->assertCanUseInternalAi($agent, $settings);
            $this->fail('Expected ValidationException not thrown.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Usuário não possui permissão para usar IA interna.',
                $exception->errors()['user'][0] ?? ''
            );
        }
    }

    public function test_company_user_is_blocked_when_company_ai_is_disabled(): void
    {
        $company = Company::create(['name' => 'Empresa IA Off']);
        $admin = User::create([
            'name' => 'Admin IA Off',
            'email' => 'admin-ia-off@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
            'can_use_ai' => true,
        ]);

        $settings = CompanyBotSetting::create([
            'company_id' => $company->id,
            'ai_enabled' => false,
            'ai_internal_chat_enabled' => true,
        ]);

        $service = $this->app->make(AiAccessService::class);

        $this->assertFalse($service->canUseInternalAi($admin, $settings));

        try {
            $service->assertCanUseInternalAi($admin, $settings);
            $this->fail('Expected ValidationException not thrown.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'IA interna não está habilitada para esta empresa.',
                $exception->errors()['ai'][0] ?? ''
            );
        }
    }
}
