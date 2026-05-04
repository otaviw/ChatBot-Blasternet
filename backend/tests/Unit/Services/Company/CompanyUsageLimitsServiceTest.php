<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Company;

use App\Models\Company;
use App\Models\CompanyBotSetting;
use App\Models\User;
use App\Services\Company\CompanyUsageLimitsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyUsageLimitsServiceTest extends TestCase
{
    use RefreshDatabase;

    private CompanyUsageLimitsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CompanyUsageLimitsService::class);
    }

    public function test_check_and_consume_blocks_when_conversation_limit_is_reached(): void
    {
        $company = Company::create(['name' => 'Limites Co']);
        CompanyBotSetting::create([
            'company_id' => $company->id,
            'max_conversation_messages_monthly' => 2,
            'conversation_messages_used' => 2,
            'usage_reset_month' => (int) now()->month,
            'usage_reset_year' => (int) now()->year,
        ]);

        $result = $this->service->checkAndConsume($company->id, 'conversation');

        $this->assertFalse($result->allowed);
        $this->assertSame(2, $result->count);
        $this->assertSame(2, $result->limit);
        $this->assertStringContainsString('Limite mensal de mensagens de conversa atingido', (string) $result->errorMessage);
    }

    public function test_check_and_consume_sets_warning_at_80_percent_threshold(): void
    {
        $company = Company::create(['name' => 'Aviso Co']);
        CompanyBotSetting::create([
            'company_id' => $company->id,
            'max_template_messages_monthly' => 5,
            'template_messages_used' => 3,
            'usage_reset_month' => (int) now()->month,
            'usage_reset_year' => (int) now()->year,
        ]);

        $result = $this->service->checkAndConsume($company->id, 'template');

        $this->assertTrue($result->allowed);
        $this->assertTrue($result->warning);
        $this->assertSame(4, $result->count);
        $this->assertSame(5, $result->limit);
        $this->assertStringContainsString('80% do limite mensal de mensagens de template', (string) $result->warningMessage);
    }

    public function test_check_and_consume_resets_monthly_counters_before_consuming(): void
    {
        Carbon::setTestNow('2026-05-04 10:00:00');

        $company = Company::create(['name' => 'Reset Co']);
        $settings = CompanyBotSetting::create([
            'company_id' => $company->id,
            'max_conversation_messages_monthly' => 10,
            'conversation_messages_used' => 9,
            'template_messages_used' => 7,
            'usage_reset_month' => 4,
            'usage_reset_year' => 2026,
        ]);

        $result = $this->service->checkAndConsume($company->id, 'conversation');

        $this->assertTrue($result->allowed);
        $this->assertSame(1, $result->count);

        $fresh = $settings->fresh();
        $this->assertSame(1, (int) $fresh->conversation_messages_used);
        $this->assertSame(0, (int) $fresh->template_messages_used);
        $this->assertSame(5, (int) $fresh->usage_reset_month);
        $this->assertSame(2026, (int) $fresh->usage_reset_year);

        Carbon::setTestNow();
    }

    public function test_check_user_limit_blocks_when_company_has_reached_max_users(): void
    {
        $company = Company::create(['name' => 'Users Co']);
        CompanyBotSetting::create([
            'company_id' => $company->id,
            'max_users' => 2,
        ]);

        User::create([
            'name' => 'Admin 1',
            'email' => 'u1@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);
        User::create([
            'name' => 'Agent 2',
            'email' => 'u2@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $result = $this->service->checkUserLimit($company->id);

        $this->assertFalse($result->allowed);
        $this->assertSame(2, $result->count);
        $this->assertSame(2, $result->limit);
        $this->assertStringContainsString('Limite de usu', (string) $result->errorMessage);
    }

    public function test_snapshot_returns_defaults_when_company_settings_do_not_exist(): void
    {
        $company = Company::create(['name' => 'Sem Settings']);

        $snapshot = $this->service->snapshot($company->id);

        $this->assertNull($snapshot['max_users']);
        $this->assertNull($snapshot['max_conversation_messages_monthly']);
        $this->assertNull($snapshot['max_template_messages_monthly']);
        $this->assertSame(0, $snapshot['conversation_messages_used']);
        $this->assertSame(0, $snapshot['template_messages_used']);
        $this->assertNull($snapshot['reset_month']);
        $this->assertNull($snapshot['reset_year']);
    }

    public function test_check_and_consume_throws_for_unknown_limit_type(): void
    {
        $company = Company::create(['name' => 'Tipo Invalido']);
        CompanyBotSetting::create([
            'company_id' => $company->id,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown limit type');

        $this->service->checkAndConsume($company->id, 'billing');
    }
}
