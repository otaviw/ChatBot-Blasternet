<?php

namespace Tests\Unit\Services\Ai;

use App\Models\AiUsage;
use App\Models\Company;
use App\Models\User;
use App\Services\Ai\AiUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiUsageServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_log_usage_creates_ai_usage_record(): void
    {
        $company = Company::create(['name' => 'Empresa Usage Service']);
        $user = User::create([
            'name' => 'User Usage Service',
            'email' => 'usage-service@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $service = $this->app->make(AiUsageService::class);
        $usage = $service->logUsage(
            (int) $company->id,
            (int) $user->id,
            null,
            AiUsage::FEATURE_INTERNAL_CHAT,
            'get_customer_by_phone'
        );

        $this->assertDatabaseHas('ai_usages', [
            'id' => (int) $usage->id,
            'company_id' => (int) $company->id,
            'user_id' => (int) $user->id,
            'feature' => AiUsage::FEATURE_INTERNAL_CHAT,
            'tool_used' => 'get_customer_by_phone',
        ]);
    }

    public function test_count_usage_by_company_considers_period_and_company_scope(): void
    {
        $companyA = Company::create(['name' => 'Empresa Usage A']);
        $companyB = Company::create(['name' => 'Empresa Usage B']);

        AiUsage::create([
            'company_id' => $companyA->id,
            'user_id' => null,
            'conversation_id' => null,
            'feature' => AiUsage::FEATURE_INTERNAL_CHAT,
            'tokens_used' => null,
            'tool_used' => null,
            'created_at' => now()->subMonth()->startOfMonth(),
        ]);
        AiUsage::create([
            'company_id' => $companyA->id,
            'user_id' => null,
            'conversation_id' => null,
            'feature' => AiUsage::FEATURE_INTERNAL_CHAT,
            'tokens_used' => null,
            'tool_used' => null,
            'created_at' => now()->startOfMonth()->addDay(),
        ]);
        AiUsage::create([
            'company_id' => $companyB->id,
            'user_id' => null,
            'conversation_id' => null,
            'feature' => AiUsage::FEATURE_INTERNAL_CHAT,
            'tokens_used' => null,
            'tool_used' => null,
            'created_at' => now()->startOfMonth()->addDay(),
        ]);

        $service = $this->app->make(AiUsageService::class);
        $count = $service->countUsageByCompany((int) $companyA->id, 'month');

        $this->assertSame(1, $count);
    }
}

